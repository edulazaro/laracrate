/**
 * Laracrate upload engine: window.Laracrate.uploads.
 *
 * One queue for the whole page, shared by every upload component and the upload
 * tray. Each upload carries an UploadGrant token from the server (where the file
 * goes, decided by the component that issued it) and a scope (a stable name of that
 * target). The engine asks the server to sign the upload, sends the bytes the way
 * the grant says (straight to the bucket, or through PHP) and gets the File back.
 *
 * Nothing here depends on the page that started an upload. The queue lives in an
 * Alpine store, so after a wire:navigate the uploads keep going, the tray keeps
 * showing them, and a component mounted again for the same target finds its own by
 * scope. Plain script on purpose (no ES exports): Blade prints it inline and it
 * defines itself once per page.
 *
 *   Laracrate.uploads.add(input.files, { grant: $wire.uploadGrant, scope: $wire.uploadScope, origin: $el })
 *
 * `tray: false` keeps an upload out of the upload tray, for a page that shows it
 * itself (a chat composer with its attachments).
 *
 * Item: { id, name, size, mime, status, phase, loaded, progress, error, retryable,
 *         result, scope, tray?, preview? }
 *   status: pending | uploading (phase 'sending', then 'saving') | done | error | canceled
 *
 * Events on window:
 *   laracrate:upload    { type, item } on every change (added, started, progress,
 *                       saving, done, error, canceled, dismissed)
 *   laracrate:uploaded  { scope, fileId, slug, collection, contextKey, name } per file
 * And, while the element that started it is still on the page, the Livewire events
 * the components always sent: laracrate-file-uploaded { collection, fileId, contextKey }
 * and, for a file the server refused, laracrate-file-rejected { collection, reason, fileName }.
 */
(function () {
    if (window.Laracrate && window.Laracrate.uploads) return;

    const defaults = {
        signUrl: '/laracrate/uploads/sign',
        confirmUrl: '/laracrate/uploads/confirm',
        concurrency: 3,
        // Without a single progress event for this long, the upload is given up: a
        // connection that hangs halfway would otherwise spin forever.
        stallMs: 30000,
        messages: {
            network: 'The connection was lost.',
            stalled: 'The upload stalled.',
            expired: 'This page has expired. Reload it to keep uploading.',
            failed: 'The file could not be saved.',
            leave: 'Uploads are in progress. Leaving now will cancel them.',
        },
    };

    const csrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const running = new Map();   // item id -> { xhr, canceled, stalled }
    const origins = new Map();   // item id -> element that started it
    const listeners = new Set(); // { scope, callback }
    const waiters = [];          // { ids, resolve }
    let fallbackItems = [];
    let config = { ...defaults, messages: { ...defaults.messages } };
    let nextId = 0;
    let active = 0;

    /** The reactive list when Alpine is there, a plain array otherwise. */
    function store() {
        if (window.Alpine && typeof window.Alpine.store === 'function') {
            if (!window.Alpine.store('laracrateUploads')) {
                window.Alpine.store('laracrateUploads', { items: fallbackItems });
            }
            return window.Alpine.store('laracrateUploads');
        }
        return { items: fallbackItems };
    }

    /** Adds an item to the shared list and returns the reactive copy to mutate. */
    function track(item) {
        const list = store().items;
        const existing = list.find((i) => i.id === item.id);
        if (existing) return existing;
        list.push(item);
        return list[list.length - 1];
    }

    function emit(type, item) {
        for (const listener of listeners) {
            if (!item || listener.scope === null || listener.scope === item.scope) {
                try { listener.callback(type, item); } catch (e) { console.error(e); }
            }
        }
        window.dispatchEvent(new CustomEvent('laracrate:upload', { detail: { type, item } }));
    }

    function settle() {
        for (let i = waiters.length - 1; i >= 0; i--) {
            const waiter = waiters[i];
            const items = store().items.filter((it) => waiter.ids.includes(it.id));
            if (items.every((it) => !['pending', 'uploading'].includes(it.status))) {
                waiters.splice(i, 1);
                waiter.resolve(items);
            }
        }
    }

    function pump() {
        while (active < config.concurrency) {
            const next = store().items.find((i) => i.status === 'pending' && i.grant && i.queued);
            if (!next) break;
            run(next);
        }
        settle();
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        });

        let data = null;
        try { data = await res.json(); } catch (e) {}

        if (!res.ok) {
            const error = new Error((data && data.message) || (res.status === 419 ? config.messages.expired : config.messages.failed));
            error.status = res.status;
            throw error;
        }
        return data;
    }

    /** XHR, for upload progress. Resolves with the parsed JSON body (or null). */
    function send(item, run, { method, url, headers, body }) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            run.xhr = xhr;

            let lastActivity = Date.now();
            const watchdog = setInterval(() => {
                if (Date.now() - lastActivity > config.stallMs) {
                    run.stalled = true;
                    xhr.abort();
                }
            }, 1000);
            const done = () => clearInterval(watchdog);

            xhr.open(method, url);
            Object.entries(headers || {}).forEach(([k, v]) => xhr.setRequestHeader(k, v));

            xhr.upload.addEventListener('progress', (e) => {
                lastActivity = Date.now();
                if (!e.lengthComputable) return;
                item.loaded = e.loaded;
                item.progress = Math.min(99, Math.round((e.loaded / e.total) * 100));
                emit('progress', item);
            });
            xhr.upload.addEventListener('load', () => { lastActivity = Date.now(); });

            xhr.onload = () => {
                done();
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300) return resolve(data);
                const error = new Error((data && data.message) || (xhr.status === 419 ? config.messages.expired : config.messages.failed));
                error.status = xhr.status;
                reject(error);
            };
            xhr.onerror = () => { done(); reject(new Error(config.messages.network)); };
            xhr.onabort = () => { done(); reject(new Error(run.stalled ? config.messages.stalled : 'canceled')); };

            xhr.send(body);
        });
    }

    async function run(item) {
        const ctl = { xhr: null, canceled: false, stalled: false };
        running.set(item.id, ctl);
        active++;
        Object.assign(item, { status: 'uploading', phase: 'sending', error: null, retryable: true, loaded: 0, progress: 0 });
        emit('started', item);

        try {
            const signed = await postJson(config.signUrl, {
                grant: item.grant,
                file_name: item.name,
                mime: item.mime,
                size: item.size,
            });
            if (ctl.canceled) throw new Error('canceled');

            let result;
            if (signed.transport === 'server') {
                const form = new FormData();
                form.append('grant', item.grant);
                form.append('file', item.file, item.name);
                result = await send(item, ctl, {
                    method: 'POST',
                    url: signed.url,
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    body: form,
                });
            } else {
                item.key = signed.key;
                item.disk = signed.disk;
                await send(item, ctl, {
                    method: signed.method || 'PUT',
                    url: signed.url,
                    headers: signed.headers || { 'Content-Type': item.mime },
                    body: item.file,
                });
                item.phase = 'saving';
                item.retryable = false;
                emit('saving', item);
                result = await postJson(config.confirmUrl, {
                    grant: item.grant,
                    receipt: signed.receipt,
                    original_name: item.name,
                    mime: item.mime,
                });
            }

            Object.assign(item, { status: 'done', phase: null, progress: 100, loaded: item.size, result });
            emit('done', item);
            announce(item);
        } catch (e) {
            if (ctl.canceled) {
                Object.assign(item, { status: 'canceled', phase: null });
                emit('canceled', item);
            } else {
                // Refused by the rules (422) or the grant (403): sending it again will not help.
                const refused = e && (e.status === 422 || e.status === 403);
                Object.assign(item, {
                    status: 'error',
                    phase: null,
                    retryable: !refused,
                    error: (e && e.message) || config.messages.failed,
                });
                emit('error', item);
                if (refused) announceRejection(item);
            }
        } finally {
            running.delete(item.id);
            active--;
            pump();
        }
    }

    function announce(item) {
        const r = item.result || {};
        const detail = {
            scope: item.scope,
            fileId: r.id,
            slug: r.slug,
            collection: r.collection,
            contextKey: r.contextKey ?? null,
            name: item.name,
        };
        window.dispatchEvent(new CustomEvent('laracrate:uploaded', { detail }));

        // The event Livewire listeners always got, only while the page that started
        // the upload is still there: another page may listen for the same collection
        // with a different meaning.
        const origin = origins.get(item.id);
        if (origin && origin.isConnected && window.Livewire) {
            window.Livewire.dispatch('laracrate-file-uploaded', {
                collection: r.collection,
                fileId: r.id,
                contextKey: r.contextKey ?? null,
            });
        }
    }

    /** The Livewire event the dropzones always sent for a refused file, while their page is there. */
    function announceRejection(item) {
        const origin = origins.get(item.id);
        if (origin && origin.isConnected && window.Livewire && item.collection) {
            window.Livewire.dispatch('laracrate-file-rejected', {
                collection: item.collection,
                reason: item.error,
                fileName: item.name,
            });
        }
    }

    function make(file, extra) {
        return {
            id: ++nextId,
            file,
            name: file.name,
            mime: file.type || 'application/octet-stream',
            size: file.size,
            status: 'pending',
            phase: null,
            loaded: 0,
            progress: 0,
            error: null,
            retryable: true,
            result: null,
            key: null,
            disk: null,
            queued: false,
            grant: null,
            scope: null,
            ...extra,
        };
    }

    const uploads = {
        configure(options = {}) {
            config = {
                ...config,
                ...options,
                messages: { ...config.messages, ...(options.messages || {}) },
            };
        },

        get items() {
            return store().items;
        },

        get busy() {
            return store().items.some((i) => ['pending', 'uploading'].includes(i.status) && i.queued);
        },

        /** Uploads files to the target of a grant. Returns the (reactive) items. */
        add(files, { grant, scope, origin = null, collection = null, ...extra } = {}) {
            const added = Array.from(files || []).map((file) => this.enqueue(make(file, extra), { grant, scope, origin, collection }));
            return added;
        },

        /**
         * Starts an item built elsewhere (a deferred dropzone stages its files and
         * only hands them over when the user confirms). Returns the reactive item.
         */
        enqueue(item, { grant, scope, origin = null, collection = null } = {}) {
            if (!item.id) item.id = ++nextId;
            const tracked = track(item);
            Object.assign(tracked, { grant, scope, collection: collection ?? tracked.collection ?? null, queued: true, status: 'pending', error: null });
            if (origin) origins.set(tracked.id, origin);
            emit('added', tracked);
            pump();
            return tracked;
        },

        /** Resolves when none of these items is pending or uploading. */
        waitFor(items) {
            const ids = Array.from(items || []).map((i) => i.id);
            return new Promise((resolve) => {
                waiters.push({ ids, resolve });
                settle();
            });
        },

        retry(item) {
            if (!['error', 'canceled'].includes(item.status) || item.retryable === false) return;
            Object.assign(item, { status: 'pending', phase: null, loaded: 0, progress: 0, error: null });
            emit('retry', item);
            pump();
        },

        cancel(item) {
            const ctl = running.get(item.id);
            if (ctl) {
                ctl.canceled = true;
                ctl.xhr?.abort();
                return;
            }
            if (item.status === 'pending') {
                item.status = 'canceled';
                emit('canceled', item);
                settle();
            }
        },

        /** Takes an item off the list (cancelling it first if it is still going). */
        dismiss(item) {
            this.cancel(item);
            const list = store().items;
            const index = list.findIndex((i) => i.id === item.id);
            if (index !== -1) list.splice(index, 1);
            origins.delete(item.id);
            emit('dismissed', item);
            settle();
        },

        /** Drops the finished and failed items, keeps what is still going. */
        clearFinished() {
            store().items
                .filter((i) => ['done', 'error', 'canceled'].includes(i.status))
                .forEach((i) => this.dismiss(i));
        },

        itemsFor(scope) {
            return store().items.filter((i) => i.scope === scope && i.queued);
        },

        busyFor(scope) {
            return store().items.some((i) => i.scope === scope && i.queued && ['pending', 'uploading'].includes(i.status));
        },

        /** Calls back on every change of the uploads of a scope (null: all). Returns the unsubscribe. */
        on(scope, callback) {
            const listener = { scope, callback };
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
    };

    window.Laracrate = window.Laracrate || {};
    window.Laracrate.uploads = uploads;

    // Leaving the page for real (reload, close, a link without wire:navigate) ends
    // every upload: the browser asks first.
    window.addEventListener('beforeunload', (e) => {
        if (!uploads.busy) return;
        e.preventDefault();
        e.returnValue = config.messages.leave;
        return config.messages.leave;
    });

    /**
     * For the single file uploader themes: refreshes the component when its upload
     * is saved, and puts the server's reason under the field when it is refused.
     */
    window.laracrateUploaderBridge = ($wire) => ({
        init() {
            this.off = uploads.on($wire.uploadScope, (type, item) => {
                if (type === 'done') $wire.$refresh();
                if (type === 'error') $wire.reportUploadError(item.error);
            });
        },
        destroy() {
            this.off?.();
        },
    });
})();
