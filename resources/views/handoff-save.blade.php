{{--
    The handoff save target (HandoffController::save): the popup an editor opens to save
    its result as a copy next to the original. It tells window.opener it is ready, takes
    one handoff:save message from the editor's origin, lets the person name the copy and
    uploads it with the upload queue under a grant for the original's place, answering
    with handoff:progress, handoff:saved, handoff:cancelled or handoff:error.

    A page of its own on purpose: it opens in a popup, outside the app layout, and works
    without Alpine. Colours come from the --lc-handoff-* variables below.
--}}
@php
    $config = [
        'editor' => $editor,
        'upload' => $upload,
        'limits' => $limits,
        'original' => $name,
        'texts' => [
            'notOpened' => __('laracrate::handoff.not_opened'),
            'untrusted' => __('laracrate::handoff.untrusted', ['editor' => $editor['name']]),
            'from' => __('laracrate::handoff.from', ['host' => ':host']),
            'saving' => __('laracrate::handoff.saving', ['percent' => ':percent']),
            'saved' => __('laracrate::handoff.saved'),
            'failed' => __('laracrate::handoff.failed', ['reason' => ':reason']),
            'tooLarge' => __('laracrate::handoff.too_large'),
            'notAccepted' => __('laracrate::handoff.not_accepted'),
            'edited' => __('laracrate::handoff.edited'),
        ],
    ];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ __('laracrate::handoff.title') }}</title>
    <style>
        :root {
            --lc-handoff-bg: #f3f4f6;
            --lc-handoff-card: #ffffff;
            --lc-handoff-border: #e5e7eb;
            --lc-handoff-text: #111827;
            --lc-handoff-muted: #6b7280;
            --lc-handoff-accent: #111827;
            --lc-handoff-accent-text: #ffffff;
            --lc-handoff-error: #b91c1c;
            --lc-handoff-ok: #15803d;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem;
            background: var(--lc-handoff-bg); color: var(--lc-handoff-text);
            font: 14px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .lc-handoff { width: 100%; max-width: 26rem; background: var(--lc-handoff-card); border: 1px solid var(--lc-handoff-border);
            border-radius: .75rem; padding: 1.25rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,.08); }
        .lc-handoff h1 { margin: 0 0 .25rem; font-size: 1rem; font-weight: 600; }
        .lc-handoff__muted { margin: 0; color: var(--lc-handoff-muted); font-size: .8125rem; }
        .lc-handoff__error { margin: .75rem 0 0; color: var(--lc-handoff-error); font-size: .8125rem; }
        .lc-handoff__ok { margin: .75rem 0 0; color: var(--lc-handoff-ok); font-size: .8125rem; }
        .lc-handoff label { display: block; margin: 1rem 0 .25rem; font-size: .75rem; font-weight: 500; color: var(--lc-handoff-muted); }
        .lc-handoff input { width: 100%; padding: .5rem .75rem; font: inherit; color: inherit;
            border: 1px solid var(--lc-handoff-border); border-radius: .375rem; }
        .lc-handoff input:focus { outline: none; border-color: var(--lc-handoff-accent); }
        .lc-handoff__actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }
        .lc-handoff button { font: inherit; font-size: .8125rem; font-weight: 500; padding: .5rem .875rem; border-radius: .375rem; cursor: pointer;
            border: 1px solid var(--lc-handoff-border); background: var(--lc-handoff-card); color: var(--lc-handoff-text); }
        .lc-handoff button.lc-handoff__primary { background: var(--lc-handoff-accent); border-color: var(--lc-handoff-accent); color: var(--lc-handoff-accent-text); }
        .lc-handoff button:disabled { opacity: .5; cursor: default; }
        .lc-handoff__bar { height: .375rem; margin-top: 1rem; border-radius: 999px; background: var(--lc-handoff-border); overflow: hidden; }
        .lc-handoff__bar > span { display: block; height: 100%; width: 0; background: var(--lc-handoff-accent); transition: width .2s; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <main class="lc-handoff">
        <h1>{{ __('laracrate::handoff.title') }}</h1>
        <p class="lc-handoff__muted" id="lc-status">{{ __('laracrate::handoff.waiting', ['editor' => $editor['name']]) }}</p>

        <form id="lc-form" hidden>
            <p class="lc-handoff__muted">
                {{ __('laracrate::handoff.next_to', ['name' => $name]) }}@if ($folder) {{ __('laracrate::handoff.in_folder', ['folder' => $folder]) }}@endif.
            </p>
            <label for="lc-name">{{ __('laracrate::handoff.name') }}</label>
            <input id="lc-name" type="text" maxlength="255" autocomplete="off" required>
            <div class="lc-handoff__actions">
                <button type="button" id="lc-cancel">{{ __('laracrate::handoff.cancel') }}</button>
                <button type="submit" id="lc-save" class="lc-handoff__primary">{{ __('laracrate::handoff.save') }}</button>
            </div>
        </form>

        <div id="lc-progress" hidden>
            <div class="lc-handoff__bar"><span id="lc-bar"></span></div>
            <p class="lc-handoff__muted" id="lc-progress-text" style="margin-top: .5rem"></p>
        </div>

        <p class="lc-handoff__ok" id="lc-done" hidden></p>
        <p class="lc-handoff__error" id="lc-error" hidden></p>
    </main>

    <script>
        {!! \EduLazaro\Laracrate\Support\UploaderScript::source() !!}
    </script>
    <script>
        (() => {
            const cfg = @js($config);
            const el = (id) => document.getElementById(id);
            const fill = (text, values) => Object.entries(values).reduce((t, [k, v]) => t.replace(':' + k, v), text);
            const opener = window.opener;

            const fail = (text) => { el('lc-error').textContent = text; el('lc-error').hidden = false; };

            if (!opener) {
                el('lc-status').textContent = cfg.texts.notOpened;
                return;
            }

            let origin = null;
            let answered = false;
            const reply = (msg) => opener.postMessage({ ...msg, v: 1 }, origin);
            const end = (msg) => { if (answered) return; answered = true; reply(msg); };

            const typeOf = (mime) => ['image', 'audio', 'video'].find((t) => mime.startsWith(t + '/')) || 'document';

            /** "Name.mp3" for the copy, or "Name (edited).mp3" when it would repeat the original's. */
            const suggest = (name) => {
                const dot = name.lastIndexOf('.');
                const base = dot > 0 ? name.slice(0, dot) : name;
                const ext = dot > 0 ? name.slice(dot) : '';
                const original = cfg.original.replace(/\.[^.]+$/, '');
                return base.toLowerCase() === original.toLowerCase() ? `${base} (${cfg.texts.edited})${ext}` : name;
            };

            const onMessage = (event) => {
                if (event.source !== opener) return;
                const data = event.data;
                if (!data || data.type !== 'handoff:save' || data.v !== 1) return;

                // Only the configured editor can save here.
                if (event.origin !== cfg.editor.origin) {
                    opener.postMessage({ type: 'handoff:error', v: 1, code: 'unauthorized' }, event.origin);
                    el('lc-status').textContent = cfg.texts.untrusted;
                    return;
                }

                const name = typeof data.name === 'string' ? data.name.trim() : '';
                if (!(data.file instanceof Blob) || !name) return;

                window.removeEventListener('message', onMessage);
                origin = event.origin;
                present(data.file, name);
            };

            const present = (blob, name) => {
                el('lc-status').textContent = fill(cfg.texts.from, { host: new URL(origin).host });

                const limits = cfg.limits[typeOf(blob.type || '')];
                if (!limits || (limits.mimes.length && !limits.mimes.includes(blob.type))) {
                    fail(fill(cfg.texts.failed, { reason: cfg.texts.notAccepted }));
                    return end({ type: 'handoff:error', code: 'unsupported', message: cfg.texts.notAccepted });
                }
                if (limits.maxBytes && blob.size > limits.maxBytes) {
                    fail(fill(cfg.texts.failed, { reason: cfg.texts.tooLarge }));
                    return end({ type: 'handoff:error', code: 'too_large', message: cfg.texts.tooLarge });
                }

                el('lc-name').value = suggest(name);
                el('lc-form').hidden = false;
                el('lc-name').focus();

                el('lc-cancel').onclick = () => { end({ type: 'handoff:cancelled' }); window.close(); };
                el('lc-form').onsubmit = (e) => {
                    e.preventDefault();
                    upload(new File([blob], el('lc-name').value.trim() || suggest(name), { type: blob.type }));
                };
            };

            const upload = (file) => {
                el('lc-form').hidden = true;
                el('lc-error').hidden = true;
                el('lc-progress').hidden = false;
                el('lc-progress-text').textContent = fill(cfg.texts.saving, { percent: 0 });

                const [item] = window.Laracrate.uploads.add([file], { grant: cfg.upload.grant, scope: cfg.upload.scope, tray: false });
                const off = window.Laracrate.uploads.on(cfg.upload.scope, (type, it) => {
                    if (it.id !== item.id) return;
                    if (type === 'progress') {
                        el('lc-bar').style.width = it.progress + '%';
                        el('lc-progress-text').textContent = fill(cfg.texts.saving, { percent: it.progress });
                        if (!answered) reply({ type: 'handoff:progress', ratio: it.size ? it.loaded / it.size : 0 });
                    } else if (type === 'done') {
                        off();
                        el('lc-progress').hidden = true;
                        el('lc-done').textContent = cfg.texts.saved;
                        el('lc-done').hidden = false;
                        end({ type: 'handoff:saved', id: it.result?.slug, name: file.name });
                        // The app's other tabs (the folder this went into) hear it too.
                        try {
                            new BroadcastChannel('laracrate').postMessage({ type: 'uploaded', detail: {
                                scope: it.scope, fileId: it.result?.id, slug: it.result?.slug,
                                collection: it.result?.collection, contextKey: it.result?.contextKey ?? null, name: file.name,
                            } });
                        } catch (e) {}
                        setTimeout(() => window.close(), 1500);
                    } else if (type === 'error') {
                        off();
                        el('lc-progress').hidden = true;
                        fail(fill(cfg.texts.failed, { reason: it.error }));
                        // A refusal will not change by trying again; a lost connection might,
                        // and then the form is still there.
                        if (it.retryable === false) {
                            end({ type: 'handoff:error', code: 'unsupported', message: it.error });
                        } else {
                            window.Laracrate.uploads.dismiss(it);
                            el('lc-form').hidden = false;
                        }
                    }
                });
            };

            window.addEventListener('message', onMessage);
            opener.postMessage({ type: 'handoff:save-ready', v: 1 }, '*');
        })();
    </script>
</body>
</html>
