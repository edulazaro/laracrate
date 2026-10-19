import { loadEngine } from './engine.js';

const ACTIVE = ['pending', 'uploading'];
const FINISHED = ['done', 'error', 'canceled'];
let staged = 0;

/**
 * The state of one upload component, with no framework: the files it holds, its
 * batch and its progress. The same job the Alpine factory of the Livewire dropzones
 * does (partials/dropzone-script), with the app handing out the grant instead of a
 * Livewire component. The bytes go through the page's upload queue
 * (window.Laracrate.uploads), so an upload keeps going after an SPA navigation.
 *
 * The React and Svelte components wrap it: they call start() when mounted and
 * destroy() when unmounted, act through its methods and render snapshot() on every
 * onChange().
 *
 * @param {object} options
 * @param {{grant: string, scope: string, collection?: string}|function(File[]): Promise<object>} options.grant
 *        where the files go: an UploadGrant the app issued (token(), scope()), or a
 *        function that asks the app for one for the files picked. Who may upload what
 *        is the app's to decide, in the route that issues it.
 * @param {boolean} [options.autoStart=true] upload as soon as files are picked; false stages them until startBatch()
 * @param {number|null} [options.maxFiles=null] how many files it takes; null for no limit
 * @param {boolean} [options.persistQueue=false] keep the finished files listed
 * @param {string} [options.refusedMessage] the error of a file the grant could not be had for
 * @param {function} [options.onUploaded] (file, item) per file saved, file being the server's File
 * @param {function} [options.onRejected] (item) per file the server refused
 * @param {function} [options.onBatchCompleted] ({ok, error}) when a batch ends
 * @param {function} [options.onMaxFiles] ({max, rejected}) when files were left out for maxFiles
 * @param {function} [options.onChange] () on every change, to render again
 */
export function createQueue(options = {}) {
    const state = { queue: [], uploading: false, batchProgress: 0, uploadedTotal: 0, batch: [] };
    let engine = null;
    let off = null;
    let live = false;

    const changed = () => { if (live) options.onChange?.(); };
    const key = (item) => item.uid ?? `upload-${item.id}`;
    const find = (itemKey) => state.queue.find((item) => key(item) === itemKey);

    async function ready() {
        engine ??= await loadEngine();

        if (!off && live) {
            off = engine.on(null, (type, item) => {
                if (!item || !state.queue.some((queued) => queued.id === item.id)) return;
                if (type === 'done') {
                    state.uploadedTotal++;
                    options.onUploaded?.(item.result, item);
                }
                if (type === 'error' && item.retryable === false) options.onRejected?.(item);
                updateBatchProgress();
                changed();
            });
        }

        return engine;
    }

    function updateBatchProgress() {
        const total = state.batch.reduce((sum, item) => sum + (item.size || 0), 0);
        if (!total) return;
        const sent = state.batch.reduce((sum, item) => sum + (FINISHED.includes(item.status) ? item.size : (item.loaded || 0)), 0);
        state.batchProgress = Math.round((sent / total) * 100);
    }

    async function resolveGrant(items) {
        const grant = options.grant;

        return typeof grant === 'function' ? grant(items.map((item) => item.file)) : grant;
    }

    /**
     * Gives the staged files to the page's upload queue, with the grant the app hands
     * out for them. From here they no longer depend on this component.
     */
    async function handOver() {
        const items = state.queue.filter((item) => item.status === 'pending' && !item.queued);
        if (!items.length) return [];

        // Taken now, so a second call while the grant is on its way does not hand them twice.
        items.forEach((item) => { item.queued = true; });

        const uploads = await ready();
        let target = null;
        let failure = null;
        try {
            target = await resolveGrant(items);
        } catch (e) {
            failure = e;
        }

        const tracked = items.map((item) => (target && target.grant
            ? uploads.enqueue(item, { grant: target.grant, scope: target.scope, collection: target.collection ?? null })
            : uploads.reject(item, failure?.message || options.refusedMessage || 'The upload could not start.', { scope: target?.scope ?? null })));

        tracked.forEach((item, index) => {
            const at = state.queue.indexOf(items[index]);
            if (at !== -1) state.queue[at] = item;
        });
        changed();

        return tracked;
    }

    /** Waits for a batch, then announces it and clears the files that went up. */
    async function watchBatch(items) {
        state.uploading = true;
        state.batch = items;
        state.batchProgress = 0;
        updateBatchProgress();
        changed();

        await (await ready()).waitFor(items);

        // Unmounted meanwhile: the page's queue finished the job.
        if (!live) return;

        const ok = state.batch.filter((item) => item.status === 'done').length;
        const error = state.batch.filter((item) => item.status === 'error').length;
        Object.assign(state, { batch: [], uploading: false, batchProgress: 0 });
        changed();
        options.onBatchCompleted?.({ ok, error });

        if (!options.persistQueue) {
            setTimeout(() => {
                // Copies fade out: the items stay 'done' in the page's queue.
                state.queue = state.queue.map((item) => (item.status === 'done' ? { ...item, status: 'fade', queued: false } : item));
                changed();
                setTimeout(() => {
                    state.queue = state.queue.filter((item) => item.status !== 'fade');
                    changed();
                }, 300);
            }, 1200);
        }
    }

    async function startBatch() {
        if (!state.queue.some((item) => item.status === 'pending' && !item.queued)) return;
        await watchBatch(await handOver());
    }

    function release(item) {
        if (item.preview) URL.revokeObjectURL(item.preview);
        if (item.queued && engine) engine.dismiss(item);
    }

    return {
        state,
        key,

        /** Mounted: listens to the page's queue, and shows again the uploads still going for its target. */
        start() {
            live = true;
            ready().catch(() => {});
            if (typeof options.grant === 'object') this.restore(options.grant?.scope);
        },

        /** Shows again the uploads of this target (its scope) still going, or failed, in the page's queue. */
        restore(scope) {
            if (!scope) return;

            ready().then((uploads) => {
                if (state.queue.length) return;

                const carried = uploads.itemsFor(scope).filter((item) => [...ACTIVE, 'error'].includes(item.status));
                if (!carried.length) return;
                state.queue.push(...carried);
                const running = carried.filter((item) => ACTIVE.includes(item.status));
                if (running.length) watchBatch(running);
                else changed();
            }).catch(() => {});
        },

        /** Unmounted: stops listening. The uploads themselves keep going. */
        destroy() {
            live = false;
            off?.();
            off = null;
        },

        /** Files picked or dropped: staged and, unless deferred, uploaded at once. */
        handleFiles(fileList) {
            let files = Array.from(fileList || []);
            if (!files.length) return;

            const max = options.maxFiles ?? null;
            if (max !== null) {
                const active = state.queue.filter((item) => ACTIVE.includes(item.status)).length;
                const free = Math.max(0, max - state.uploadedTotal - active);
                if (free === 0) {
                    options.onMaxFiles?.({ max, rejected: files.length });
                    return;
                }
                if (files.length > free) {
                    options.onMaxFiles?.({ max, rejected: files.length - free });
                    files = files.slice(0, free);
                }
            }

            const wasIdle = state.queue.every((item) => !ACTIVE.includes(item.status));

            for (const file of files) {
                state.queue.push({
                    id: null,
                    uid: `staged-${++staged}`,
                    file,
                    name: file.name,
                    mime: file.type || 'application/octet-stream',
                    size: file.size,
                    preview: file.type?.startsWith('image/') && typeof URL.createObjectURL === 'function' ? URL.createObjectURL(file) : null,
                    status: 'pending',
                    loaded: 0,
                    progress: 0,
                    error: null,
                    retryable: true,
                    queued: false,
                });
            }
            changed();

            if (options.autoStart === false) return;

            if (wasIdle) {
                startBatch();
            } else {
                // Dropped while a batch runs: it joins that batch.
                handOver().then((items) => { state.batch.push(...items); });
            }
        },

        startBatch,

        retry(itemKey) {
            const item = find(itemKey);
            if (item?.queued && engine) engine.retry(item);
        },

        remove(itemKey) {
            const item = find(itemKey);
            if (!item) return;
            release(item);
            state.queue.splice(state.queue.indexOf(item), 1);
            changed();
        },

        clear() {
            state.queue.forEach(release);
            state.queue = [];
            changed();
        },

        /** What a component renders: copies, so a framework sees every change. */
        snapshot() {
            const queue = state.queue.map((item) => ({ ...item, key: key(item) }));
            const max = options.maxFiles ?? null;
            const active = queue.filter((item) => ACTIVE.includes(item.status)).length;

            return {
                queue,
                uploading: state.uploading,
                batchProgress: state.batchProgress,
                pendingCount: queue.filter((item) => item.status === 'pending').length,
                stagedCount: queue.filter((item) => item.status === 'pending' && !item.queued).length,
                doneCount: queue.filter((item) => item.status === 'done').length,
                errorCount: queue.filter((item) => item.status === 'error').length,
                reachedMax: max !== null && state.uploadedTotal + active >= max,
            };
        },
    };
}
