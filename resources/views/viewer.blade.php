{{--
    The viewer: one image, audio or video at a time, in a card at the bottom of the
    page. Images and videos open to full size from there. Put it once in the layout,
    inside @persist, so what is playing keeps playing across wire:navigate:

        @persist('laracrate-viewer')
            @include('laracrate::viewer')
        @endpersist

    Open a file with Laracrate.viewer.open(slug), or give any element a
    data-laracrate-view="{{ $file->slug }}": a plain click on it opens the viewer
    (a link keeps its href for a new tab). The link to the file is asked for when it
    opens (ViewerController) and again if it runs out halfway.

    Elements that share a data-laracrate-gallery="name" form a gallery: the viewer
    moves through them in page order (arrows, and the arrow keys). The same with
    Laracrate.viewer.open(slug, { gallery: [slug, ...] }).

    Optional: ['position' => 'bottom: 1rem; right: 1rem;'] to move it. Colours come
    from the --lc-viewer-* variables below, which the app can override. Next to the
    upload tray, stack both in one container of the app.

    Own CSS on purpose: the host app's Tailwind does not scan the package views.
--}}
@php
    $position = $position ?? 'bottom: 1rem; right: 1rem;';
    $config = [
        'url' => route('laracrate.files.view', ['file' => '__SLUG__'], absolute: false),
        'messages' => [
            'loading' => __('laracrate::viewer.loading'),
            'failed' => __('laracrate::viewer.failed'),
            'of' => __('laracrate::viewer.of', ['current' => ':current', 'total' => ':total']),
        ],
    ];
@endphp

<style>
    .lc-viewer {
        --lc-viewer-bg: #f3f4f6;
        --lc-viewer-border: #e5e7eb;
        --lc-viewer-text: #111827;
        --lc-viewer-muted: #6b7280;
        --lc-viewer-error: #b91c1c;
        --lc-viewer-stage: #e5e7eb;
        --lc-viewer-backdrop: rgba(17, 24, 39, .96);
        position: fixed; z-index: 60; width: 24rem; max-width: calc(100vw - 2rem);
        background: var(--lc-viewer-bg); color: var(--lc-viewer-text);
        border: 1px solid var(--lc-viewer-border); border-radius: .5rem;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1);
        font-size: .875rem; line-height: 1.25rem; overflow: hidden;
    }
    .lc-viewer__head { display: flex; align-items: center; gap: .25rem; padding: .5rem .5rem .5rem 1rem; }
    .lc-viewer__title { flex: 1; min-width: 0; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lc-viewer__btn { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 1.75rem; height: 1.75rem;
        border-radius: .375rem; color: var(--lc-viewer-muted); background: transparent; border: 0; cursor: pointer; }
    .lc-viewer__btn:hover { color: var(--lc-viewer-text); background: rgba(0,0,0,.06); }
    .lc-viewer__icon { width: 1rem; height: 1rem; }
    .lc-viewer__error { padding: 0 1rem .75rem; color: var(--lc-viewer-error); font-size: .75rem; }
    .lc-viewer__stage { display: flex; align-items: center; justify-content: center; border-top: 1px solid var(--lc-viewer-border); }
    .lc-viewer__stage img, .lc-viewer__stage video { display: block; width: 100%; max-height: 14rem; object-fit: contain; background: var(--lc-viewer-stage); }
    .lc-viewer__stage img { cursor: zoom-in; }
    .lc-viewer__stage video { background: #000; }
    .lc-viewer__stage audio { display: block; width: 100%; margin: .5rem .75rem .75rem; }
    .lc-viewer__bar { display: none; }

    /* Full size: the same stage over the whole window, so a video keeps playing. */
    .lc-viewer__stage--full { position: fixed; inset: 0; z-index: 1; flex-direction: column; padding: 3.5rem 1rem 1rem;
        background: var(--lc-viewer-backdrop); border: 0; }
    .lc-viewer__stage--full img, .lc-viewer__stage--full video { width: auto; height: auto; max-width: 100%; max-height: 100%; min-height: 0; background: transparent; }
    .lc-viewer__stage--full img { cursor: default; }
    .lc-viewer__stage--full .lc-viewer__bar { display: flex; align-items: center; gap: .25rem; position: absolute; top: 0; left: 0; right: 0;
        padding: .75rem .75rem .75rem 1.25rem; color: #fff; }
    .lc-viewer__stage--full .lc-viewer__btn { color: rgba(255,255,255,.75); }
    .lc-viewer__stage--full .lc-viewer__btn:hover { color: #fff; background: rgba(255,255,255,.12); }
    .lc-viewer__count { color: var(--lc-viewer-muted); font-size: .75rem; font-variant-numeric: tabular-nums; padding: 0 .125rem; }
    .lc-viewer__btn:disabled { opacity: .35; cursor: default; background: transparent; }
    .lc-viewer__nav { display: none; }
    .lc-viewer__stage--full .lc-viewer__nav { display: inline-flex; position: absolute; top: 50%; transform: translateY(-50%);
        width: 2.75rem; height: 2.75rem; border-radius: 999px; background: rgba(255,255,255,.12); }
    .lc-viewer__stage--full .lc-viewer__nav:hover { background: rgba(255,255,255,.24); }
    .lc-viewer__stage--full .lc-viewer__nav--prev { left: 1rem; }
    .lc-viewer__stage--full .lc-viewer__nav--next { right: 1rem; }
    .lc-viewer__stage--full .lc-viewer__count { color: rgba(255,255,255,.75); }
    .lc-viewer-lock { overflow: hidden; }
    [x-cloak].lc-viewer { display: none; }
</style>

<script>
    (function () {
        if (window.Laracrate && window.Laracrate.viewer) return;

        const open = (slug, options = {}) => window.dispatchEvent(new CustomEvent('laracrate:view', {
            detail: { slug, gallery: options.gallery || null },
        }));

        /** The slugs of the elements sharing this one's data-laracrate-gallery, in page order. */
        const galleryOf = (el) => {
            const name = el.dataset.laracrateGallery;
            if (!name) return null;
            return [...document.querySelectorAll('[data-laracrate-view][data-laracrate-gallery]')]
                .filter((other) => other.dataset.laracrateGallery === name)
                .map((other) => other.dataset.laracrateView);
        };

        window.Laracrate = window.Laracrate || {};
        window.Laracrate.viewer = {
            open,
            close: () => window.dispatchEvent(new CustomEvent('laracrate:view-close')),
        };

        // data-laracrate-view: a plain click opens the viewer; Ctrl, Cmd, Shift or the
        // middle button leave a link to do what it does.
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-laracrate-view]');
            if (!el || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            open(el.dataset.laracrateView, { gallery: galleryOf(el) });
        });
    })();

    window.laracrateViewer = window.laracrateViewer || ((config) => ({
        slug: null,      // what is open, or opening
        gallery: [],     // slugs it moves through, when opened from a gallery
        item: null,      // { slug, name, type, mime, url, download }
        loading: false,
        error: null,
        full: false,
        ticket: 0,
        wants: false,    // the media was playing, or about to
        position: 0,     // last second played: a failed load has already reset currentTime
        retried: false,
        resume: null,    // { at, play } while a fresh link loads

        get title() { return this.item?.name || (this.loading ? config.messages.loading : ''); },
        get place() { return this.gallery.indexOf(this.slug); },
        get count() { return config.messages.of.replace(':current', this.place + 1).replace(':total', this.gallery.length); },

        async fetchItem(slug) {
            const response = await fetch(config.url.replace('__SLUG__', encodeURIComponent(slug)), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.url) throw new Error(data.message || config.messages.failed);
            return data;
        },

        async open(slug, gallery = null, keep = false) {
            if (!slug) return;
            this.gallery = gallery && gallery.includes(slug) ? gallery : [];
            // What is already open stays as it is (a double click, play pressed again);
            // paused, it plays on.
            if (slug === this.slug && (this.item || this.loading) && !this.error) {
                const media = this.$root.querySelector('audio, video');
                if (media?.paused) media.play().catch(() => {});
                return;
            }
            const ticket = ++this.ticket;
            // Moving through a gallery keeps the current one (and full size) until the next is in.
            Object.assign(this, keep
                ? { slug, error: null, loading: true, retried: false, resume: null, position: 0 }
                : { slug, item: null, error: null, full: false, loading: true, retried: false, resume: null, position: 0 });
            try {
                const item = await this.fetchItem(slug);
                if (ticket === this.ticket) this.item = item;
            } catch (e) {
                if (ticket === this.ticket) this.error = e.message;
            } finally {
                if (ticket === this.ticket) this.loading = false;
            }
        },

        close() {
            this.ticket++;
            Object.assign(this, { slug: null, gallery: [], item: null, error: null, full: false, loading: false, resume: null });
        },

        /** The previous (-1) or next (1) of the gallery. */
        step(by) {
            const next = this.gallery[this.place + by];
            if (next) this.open(next, this.gallery, true);
        },

        /** The arrow keys move through the gallery, unless the person is typing. */
        key(event, by) {
            if (this.gallery.length < 2 || event.target.closest('input, textarea, select, [contenteditable]')) return;
            event.preventDefault();
            this.step(by);
        },

        /**
         * The media could not load: most likely its link ran out. Ask for a new one,
         * once, and carry on at the same second.
         */
        async recover() {
            if (!this.item) return;
            if (this.retried) { this.error = config.messages.failed; return; }
            this.retried = true;
            const ticket = this.ticket;
            const resume = { at: this.position, play: this.wants };
            try {
                const fresh = await this.fetchItem(this.item.slug);
                if (ticket !== this.ticket) return;
                this.resume = resume;
                this.item = { ...this.item, url: fresh.url, download: fresh.download };
            } catch (e) {
                if (ticket === this.ticket) this.error = e.message;
            }
        },

        resumed(media) {
            if (!this.resume) return;
            if (this.resume.at) media.currentTime = this.resume.at;
            // Paused before it ran out: autoplay must not start it again.
            this.resume.play ? media.play().catch(() => {}) : media.pause();
            this.resume = null;
        },

        /** With a fresh link: the one from opening may be old by now. */
        async download() {
            if (!this.item) return;
            try {
                window.location.href = (await this.fetchItem(this.item.slug)).download;
            } catch (e) {
                this.error = e.message;
            }
        },
    }));
</script>

<div x-data="laracrateViewer(@js($config))" x-show="item || loading || error" x-cloak
     @laracrate:view.window="open($event.detail.slug, $event.detail.gallery)"
     @keydown.arrow-left.window="key($event, -1)"
     @keydown.arrow-right.window="key($event, 1)"
     @laracrate:view-close.window="close()"
     @keydown.escape.window="full = false"
     x-effect="document.documentElement.classList.toggle('lc-viewer-lock', full)"
     class="lc-viewer" style="{{ $position }}" role="region" aria-label="{{ __('laracrate::viewer.label') }}">
    <div class="lc-viewer__head">
        <span class="lc-viewer__title" x-text="title"></span>
        <template x-if="gallery.length > 1">
            <span style="display: contents">
                <button type="button" class="lc-viewer__btn" :disabled="place < 1" @click="step(-1)" title="{{ __('laracrate::viewer.previous') }}">
                    <svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <span class="lc-viewer__count" x-text="count"></span>
                <button type="button" class="lc-viewer__btn" :disabled="place >= gallery.length - 1" @click="step(1)" title="{{ __('laracrate::viewer.next') }}">
                    <svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </span>
        </template>
        <button type="button" class="lc-viewer__btn" x-show="item && item.type !== 'audio'" @click="full = true" title="{{ __('laracrate::viewer.expand') }}">
            <svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"/></svg>
        </button>
        <button type="button" class="lc-viewer__btn" x-show="item" @click="download()" title="{{ __('laracrate::viewer.download') }}">
            <svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/></svg>
        </button>
        <button type="button" class="lc-viewer__btn" @click="close()" title="{{ __('laracrate::viewer.close') }}">
            <svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
        </button>
    </div>

    <p class="lc-viewer__error" x-show="error" x-text="error"></p>

    <div class="lc-viewer__stage" :class="full && 'lc-viewer__stage--full'" x-show="item && ! error" @click.self="full = false">
        <div class="lc-viewer__bar">
            <span class="lc-viewer__title" x-text="title"></span>
            <span class="lc-viewer__count" x-show="gallery.length > 1" x-text="count"></span>
            <button type="button" class="lc-viewer__btn" @click="download()" title="{{ __('laracrate::viewer.download') }}">
                <svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/></svg>
            </button>
            <button type="button" class="lc-viewer__btn" @click="full = false" title="{{ __('laracrate::viewer.collapse') }}">
                <svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
            </button>
        </div>
        <button type="button" class="lc-viewer__btn lc-viewer__nav lc-viewer__nav--prev" x-show="gallery.length > 1 && place > 0"
                @click="step(-1)" title="{{ __('laracrate::viewer.previous') }}"><svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg></button>
        <button type="button" class="lc-viewer__btn lc-viewer__nav lc-viewer__nav--next" x-show="gallery.length > 1 && place < gallery.length - 1"
                @click="step(1)" title="{{ __('laracrate::viewer.next') }}"><svg class="lc-viewer__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></button>
        <template x-if="item?.type === 'image'">
            <img :src="item.url" :alt="item.name" @click="full = true" @load="retried = false" x-on:error="recover()">
        </template>
        <template x-if="item?.type === 'video'">
            <video :src="item.url" controls autoplay playsinline
                   @play="wants = true" @pause="wants = false" @playing="retried = false"
                   @timeupdate="if ($el.readyState) position = $el.currentTime"
                   @loadedmetadata="resumed($el)" x-on:error="recover()"></video>
        </template>
        <template x-if="item?.type === 'audio'">
            <audio :src="item.url" controls autoplay
                   @play="wants = true" @pause="wants = false" @playing="retried = false"
                   @timeupdate="if ($el.readyState) position = $el.currentTime"
                   @loadedmetadata="resumed($el)" x-on:error="recover()"></audio>
        </template>
    </div>
</div>
