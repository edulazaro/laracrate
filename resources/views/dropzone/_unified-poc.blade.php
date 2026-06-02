@include('laracrate::dropzone._script')

{{--
    PoC: UN blade para los 11 temas de `dropzone` (instant, multi).
    Igual estructura que dropzone-deferred pero autoStart=true y SIN bloque de
    acciones (sube al soltar/seleccionar). Mismo CSS (.lc-dz__*), tema por
    [data-wire-theme]. Sin @if por tema; estados via is-<status>.
--}}
<div
    data-wire-theme="{{ $theme ?? 'default' }}"
    x-data="laracrateDropzone({
        presignUrl:   @js(route('laracrate.uploads.presign')),
        disk:         @js($disk),
        fileableType: @js($fileableType),
        fileableId:   @js($fileableId),
        collection:   @js(request()->route()?->parameter('collection') ?? null),
        maxSizeKb:    @js($maxSizeKb),
        persistQueue: @js($persistQueue),
        autoStart:    true,
        maxFiles:     @js($effectiveMaxFiles ?? null),
    })"
    class="lc-dz"
>
    {{-- Dropzone --}}
    <div
        x-show="!reachedMax"
        @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
        @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
        @click="$refs.input.click()" role="button" tabindex="0"
        :class="dragOver && 'is-dragover'" class="lc-dz__drop"
    >
        <span class="lc-dz__icon">
            @switch($iconCategory ?? 'mixed')
                @case('image')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z"/></svg>@break
                @case('document')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 12h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>@break
                @default<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            @endswitch
        </span>
        <p class="lc-dz__title">{{ __('laracrate::uploader.select') }}</p>
        <p class="lc-dz__sub">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
        <p class="lc-dz__max-hint">{{ __('laracrate::uploader.max_size', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
        <input type="file" x-ref="input" :multiple="@js($multipleAllowed) && (cfg.maxFiles === null || cfg.maxFiles > 1)" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''" />
    </div>

    <div x-show="reachedMax" x-cloak class="lc-dz__max">
        <span class="lc-dz__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></span>
        <p class="lc-dz__title">{{ __('laracrate::uploader.max_reached_title') }}</p>
        <p class="lc-dz__sub" x-text="'máximo: ' + (cfg.maxFiles ?? '∞') + ' ' + @js(__('laracrate::uploader.max_reached_unit'))"></p>
    </div>

    {{-- Cola (sin acciones: instant) --}}
    <div x-show="queue.length > 0" class="lc-dz__queue" x-cloak>
        <div class="lc-dz__status">
            <span>
                <span x-show="uploading">{{ str(__('laracrate::uploader.uploading'))->lower() }}</span>
                <span x-show="!uploading && doneCount > 0" x-text="doneCount + ' OK' + (errorCount > 0 ? ' / ' + errorCount + ' err' : '')"></span>
            </span>
            <span x-show="uploading" x-text="batchProgress + '%'"></span>
        </div>
        <div x-show="uploading" class="lc-dz__bar"><div class="lc-dz__bar-fill" :style="'width: ' + batchProgress + '%'"></div></div>

        @if (($layout ?? 'grid') === 'list')
            @include('laracrate::dropzone._queue-list')
        @else
        <div class="lc-dz__grid">
            <template x-for="(item, i) in queue" :key="item.id">
                <div class="lc-dz__item" :class="'is-' + item.status">
                    <template x-if="item.preview"><img :src="item.preview" class="lc-dz__preview"></template>
                    <template x-if="!item.preview"><div class="lc-dz__ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div></template>
                    <div class="lc-dz__overlay">
                        <template x-if="item.status === 'uploading'"><svg class="lc-dz__spin" fill="none" viewBox="0 0 24 24"><circle class="lc-dz__spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                        <template x-if="item.status === 'done'"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></template>
                        <template x-if="item.status === 'error'"><button type="button" @click.stop="retryItem(i)" class="lc-dz__retry">↻</button></template>
                    </div>
                    <p class="lc-dz__name" x-text="item.name"></p>
                </div>
            </template>
        </div>
        @endif
    </div>
</div>
