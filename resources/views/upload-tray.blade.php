{{--
    The upload tray: every upload of the page (window.Laracrate.uploads), wherever it
    was started. Put it once in the layout, inside @persist, so it is not re-created
    on each wire:navigate:

        @persist('laracrate-uploads')
            @include('laracrate::upload-tray')
        @endpersist

    Optional: ['position' => 'bottom: 3rem; right: 1rem;'] to move it. Colours come
    from the --wire-laracrate-tray-* variables below, which the app can override.

    Own CSS on purpose: the host app's Tailwind does not scan the package views.
--}}
@php
    $position = $position ?? 'bottom: 1rem; right: 1rem;';
    $texts = [
        'uploadingOne' => trans_choice('laracrate::uploader.tray.uploading', 1, ['count' => ':count']),
        'uploadingMany' => trans_choice('laracrate::uploader.tray.uploading', 2, ['count' => ':count']),
        'doneOne' => trans_choice('laracrate::uploader.tray.done', 1, ['count' => ':count']),
        'doneMany' => trans_choice('laracrate::uploader.tray.done', 2, ['count' => ':count']),
        'failedOne' => trans_choice('laracrate::uploader.tray.failed', 1, ['count' => ':count']),
        'failedMany' => trans_choice('laracrate::uploader.tray.failed', 2, ['count' => ':count']),
        'saving' => __('laracrate::uploader.tray.saving'),
    ];
@endphp

<style>
    .laracrate-tray {
        --wire-laracrate-tray-bg: #f3f4f6;
        --wire-laracrate-tray-border: #e5e7eb;
        --wire-laracrate-tray-text: #111827;
        --wire-laracrate-tray-muted: #6b7280;
        --wire-laracrate-tray-error: #b91c1c;
        --wire-laracrate-tray-ok: #15803d;
        position: fixed; z-index: 60; width: 24rem; max-width: calc(100vw - 2rem);
        background: var(--wire-laracrate-tray-bg); color: var(--wire-laracrate-tray-text);
        border: 1px solid var(--wire-laracrate-tray-border); border-radius: .5rem;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1);
        font-size: .875rem; line-height: 1.25rem; overflow: hidden;
    }
    .laracrate-tray__head { display: flex; align-items: center; gap: .5rem; padding: .75rem 1rem; }
    .laracrate-tray__title { flex: 1; min-width: 0; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .laracrate-tray__pct { color: var(--wire-laracrate-tray-muted); font-variant-numeric: tabular-nums; }
    .laracrate-tray__btn { display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem;
        border-radius: .375rem; color: var(--wire-laracrate-tray-muted); background: transparent; border: 0; cursor: pointer; }
    .laracrate-tray__btn:hover { color: var(--wire-laracrate-tray-text); background: rgba(0,0,0,.06); }
    .laracrate-tray__list { max-height: 16rem; overflow-y: auto; border-top: 1px solid var(--wire-laracrate-tray-border); }
    .laracrate-tray__item { display: flex; align-items: flex-start; gap: .625rem; padding: .5rem 1rem; }
    .laracrate-tray__item + .laracrate-tray__item { border-top: 1px solid var(--wire-laracrate-tray-border); }
    .laracrate-tray__icon { width: 1rem; height: 1rem; flex-shrink: 0; margin-top: .125rem; }
    .laracrate-tray__body { flex: 1; min-width: 0; }
    .laracrate-tray__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .laracrate-tray__meta { color: var(--wire-laracrate-tray-muted); font-size: .75rem; }
    .laracrate-tray__error { color: var(--wire-laracrate-tray-error); font-size: .75rem; }
    .laracrate-tray__actions { display: flex; gap: .75rem; margin-top: .25rem; font-size: .75rem; }
    .laracrate-tray__link { background: none; border: 0; padding: 0; cursor: pointer; color: var(--wire-laracrate-tray-text); font-weight: 500; }
    .laracrate-tray__link:hover { text-decoration: underline; }
    .laracrate-tray__link--muted { color: var(--wire-laracrate-tray-muted); font-weight: 400; }
    .laracrate-tray__spin { animation: laracrate-tray-spin 1s linear infinite; }
    @keyframes laracrate-tray-spin { to { transform: rotate(360deg); } }
    [x-cloak].laracrate-tray { display: none; }
</style>

<script>
    {!! \EduLazaro\Laracrate\Support\UploaderScript::source() !!}

    window.laracrateUploadTray = window.laracrateUploadTray || ((texts) => ({
        open: true,
        clearTimer: null,

        get items() { return window.Laracrate.uploads.items.filter(i => i.queued && i.tray !== false); },
        get active() { return this.items.filter(i => ['pending', 'uploading'].includes(i.status)); },
        get failed() { return this.items.filter(i => i.status === 'error'); },
        get done() { return this.items.filter(i => i.status === 'done'); },

        get title() {
            const count = (one, many, n) => (n === 1 ? one : many).replace(':count', n);
            if (this.active.length) {
                return this.active.every(i => i.phase === 'saving')
                    ? texts.saving
                    : count(texts.uploadingOne, texts.uploadingMany, this.active.length);
            }
            if (this.failed.length) return count(texts.failedOne, texts.failedMany, this.failed.length);
            return count(texts.doneOne, texts.doneMany, this.done.length);
        },

        /** By bytes, over what is still going plus what finished with it. */
        get percent() {
            const pool = this.items.filter(i => ['pending', 'uploading', 'done'].includes(i.status));
            const total = pool.reduce((sum, i) => sum + (i.size || 0), 0);
            const sent = pool.reduce((sum, i) => sum + (i.status === 'done' ? i.size : (i.loaded || 0)), 0);
            return total ? Math.floor((sent / total) * 100) : 0;
        },

        size(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        },

        /** All done and nothing failed: the tray goes away by itself shortly after. */
        settle() {
            clearTimeout(this.clearTimer);
            if (this.items.length && !this.active.length && !this.failed.length) {
                this.clearTimer = setTimeout(() => window.Laracrate.uploads.clearFinished(), 4000);
            }
        },

        retry(item) { window.Laracrate.uploads.retry(item); },
        cancel(item) { window.Laracrate.uploads.cancel(item); },
        dismiss(item) { window.Laracrate.uploads.dismiss(item); },
        close() { window.Laracrate.uploads.clearFinished(); },
    }));
</script>

<div x-data="laracrateUploadTray(@js($texts))" x-show="items.length" x-cloak x-effect="settle()"
     class="laracrate-tray" style="{{ $position }}" role="status" aria-live="polite">
    <div class="laracrate-tray__head">
        <svg x-show="active.length" class="laracrate-tray__icon laracrate-tray__spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
        <span class="laracrate-tray__title" x-text="title"></span>
        <span class="laracrate-tray__pct" x-show="active.length && ! active.every(i => i.phase === 'saving')" x-text="percent + ' %'"></span>
        <button type="button" class="laracrate-tray__btn" @click="open = ! open"
                :title="open ? @js(__('laracrate::uploader.tray.collapse')) : @js(__('laracrate::uploader.tray.expand'))">
            <svg class="laracrate-tray__icon" :style="open ? '' : 'transform: rotate(180deg)'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <button type="button" class="laracrate-tray__btn" x-show="! active.length" @click="close()" title="{{ __('laracrate::uploader.tray.close') }}">
            <svg class="laracrate-tray__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
        </button>
    </div>

    <div class="laracrate-tray__list" x-show="open">
        <template x-for="item in items" :key="item.id">
            <div class="laracrate-tray__item">
                <svg x-show="['pending', 'uploading'].includes(item.status)" class="laracrate-tray__icon laracrate-tray__spin" style="color: var(--wire-laracrate-tray-muted)" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <svg x-show="item.status === 'done'" class="laracrate-tray__icon" style="color: var(--wire-laracrate-tray-ok)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <svg x-show="['error', 'canceled'].includes(item.status)" class="laracrate-tray__icon" style="color: var(--wire-laracrate-tray-error)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v5M12 16h.01"/></svg>

                <div class="laracrate-tray__body">
                    <p class="laracrate-tray__name" x-text="item.name"></p>
                    <p class="laracrate-tray__meta" x-show="item.status !== 'error'"
                       x-text="item.status === 'uploading' && item.phase !== 'saving' ? item.progress + ' % · ' + size(item.size) : size(item.size)"></p>
                    <p class="laracrate-tray__error" x-show="item.status === 'error'" x-text="item.error"></p>
                    <div class="laracrate-tray__actions" x-show="item.status === 'error' || item.status === 'uploading'">
                        <button type="button" class="laracrate-tray__link" x-show="item.status === 'error' && item.retryable" @click="retry(item)">{{ __('laracrate::uploader.tray.retry') }}</button>
                        <button type="button" class="laracrate-tray__link laracrate-tray__link--muted" x-show="item.status === 'error'" @click="dismiss(item)">{{ __('laracrate::uploader.tray.dismiss') }}</button>
                        <button type="button" class="laracrate-tray__link laracrate-tray__link--muted" x-show="item.status === 'uploading' && item.phase !== 'saving'" @click="cancel(item)">{{ __('laracrate::uploader.tray.cancel') }}</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
