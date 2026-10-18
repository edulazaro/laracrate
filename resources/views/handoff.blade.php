{{--
    Handoff from the app's pages: Laracrate.handoff.open(editor, slug) opens a file in an
    editor of another site (laracrate.handoff.editors), with the handoff protocol. Include
    it once in the layout:

        @include('laracrate::handoff', ['appName' => 'Inis Legal'])

    `appName` is the name the editor shows on its "Save to …" command. open() must run inside
    the person's click: it opens the editor window first, then reads the file from this
    site (counted as a download) and hands it over once the editor says it is ready. It
    returns a promise; it rejects with an Error whose `code` is 'popup_blocked', 'closed'
    (the editor window was closed first) or 'failed'.

    A copy saved back from the editor's popup reaches the other tabs of the app as a
    laracrate:uploaded window event, so a folder showing it can refresh.
--}}
@php
    $config = [
        'editors' => collect(config('laracrate.handoff.editors', []))->map(fn (array $editor) => $editor['url'])->all(),
        'url' => route('laracrate.files.handoff', ['file' => '__SLUG__', 'editor' => '__EDITOR__'], absolute: false),
        'app' => $appName ?? config('app.name'),
        'texts' => [
            'popupBlocked' => __('laracrate::handoff.popup_blocked'),
            'failed' => __('laracrate::handoff.open_failed'),
        ],
    ];
@endphp

<script>
    (function () {
        if (window.Laracrate && window.Laracrate.handoff) return;

        const cfg = @js($config);

        const error = (code, message) => Object.assign(new Error(message || code), { code });

        function open(editor, slug) {
            const url = cfg.editors[editor];
            if (!url) return Promise.reject(error('failed', cfg.texts.failed));

            // Before anything slow: a window opened later than the click is blocked.
            const win = window.open(url, '_blank');
            if (!win) return Promise.reject(error('popup_blocked', cfg.texts.popupBlocked));
            const origin = new URL(url).origin;

            return new Promise((resolve, reject) => {
                let ready = false;
                let payload = null;
                let finished = false;

                const finish = (e) => {
                    if (finished) return;
                    finished = true;
                    window.removeEventListener('message', onMessage);
                    clearInterval(watch);
                    e ? reject(e) : resolve();
                };
                const send = () => {
                    if (!ready || !payload) return;
                    win.postMessage({ type: 'handoff:open', v: 1, ...payload }, origin);
                    finish();
                };
                const onMessage = (event) => {
                    if (event.source !== win || event.origin !== origin) return;
                    if (event.data?.type === 'handoff:ready' && event.data?.v === 1) {
                        ready = true;
                        send();
                    }
                };
                window.addEventListener('message', onMessage);
                const watch = setInterval(() => { if (win.closed) finish(error('closed')); }, 500);

                (async () => {
                    try {
                        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                        const res = await fetch(cfg.url.replace('__SLUG__', encodeURIComponent(slug)).replace('__EDITOR__', encodeURIComponent(editor)), { headers, credentials: 'same-origin' });
                        const info = await res.json().catch(() => ({}));
                        if (!res.ok) throw error('failed', info.message || cfg.texts.failed);

                        const file = await fetch(info.download, { credentials: 'same-origin' });
                        if (!file.ok) throw error('failed', cfg.texts.failed);

                        payload = { file: await file.blob(), name: info.name, app: { name: cfg.app }, ...(info.save ? { save: info.save } : {}) };
                        send();
                    } catch (e) {
                        win.close();
                        finish(e.code ? e : error('failed', cfg.texts.failed));
                    }
                })();
            });
        }

        window.Laracrate = window.Laracrate || {};
        window.Laracrate.handoff = { open };

        try {
            new BroadcastChannel('laracrate').addEventListener('message', (event) => {
                if (event.data?.type === 'uploaded') {
                    window.dispatchEvent(new CustomEvent('laracrate:uploaded', { detail: event.data.detail }));
                }
            });
        } catch (e) {}
    })();
</script>
