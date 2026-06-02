@include('laracrate::dropzone._script')

{{--
    PoC: UN blade para los 11 temas de `dropzone-single` (instant, 1 archivo).
    Igual que single-deferred pero autoStart=true y sin botón Subir (sube al
    elegir). Mismo CSS (.lc-dzs__*), tema por [data-wire-theme].
--}}
<div
    data-wire-theme="{{ $theme ?? 'default' }}"
    x-data="laracrateDropzone({
        presignUrl:   @js(route('laracrate.uploads.presign')),
        disk:         @js($disk),
        fileableType: @js($fileableType),
        fileableId:   @js($fileableId),
        collection:   @js($collection),
        maxSizeKb:    @js($maxSizeKb),
        persistQueue: false,
        autoStart:    true,
        maxFiles:     1,
    })"
    class="lc-dzs"
>
    @if ($existing)
        <div class="lc-dzs__existing">
            @switch($iconCategory ?? 'mixed')
                @case('image')<img src="{{ $existing->url() }}" alt="{{ $existing->original_name }}" class="lc-dzs__thumb">@break
                @case('video')<video controls class="lc-dzs__thumb lc-dzs__thumb--video"><source src="{{ $existing->url() }}"></video>@break
                @case('audio')<audio controls class="lc-dzs__audio"><source src="{{ $existing->url() }}"></audio>@break
                @default<span class="lc-dzs__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 12h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg></span>
            @endswitch
            <div class="lc-dzs__meta">
                <p class="lc-dzs__name">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="lc-dzs__sub">{{ number_format($existing->size / 1024, 1) }} KB · {{ strtoupper($existing->extension ?? '') }}</p>
            </div>
            <a href="{{ $existing->url() }}" target="_blank" class="lc-dzs__action" title="Ver">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </a>
            <button type="button" wire:click="removeFile" wire:confirm="¿Quitar este archivo?" class="lc-dzs__action lc-dzs__action--danger" title="Quitar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    @else
        <label x-show="!uploading && queue.length === 0"
            @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            role="button" tabindex="0" :class="dragOver && 'is-dragover'" class="lc-dzs__drop">
            <span class="lc-dzs__icon">
                @switch($iconCategory ?? 'mixed')
                    @case('image')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z"/></svg>@break
                    @case('document')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 12h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>@break
                    @default<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                @endswitch
            </span>
            <p class="lc-dzs__title">{{ __('laracrate::uploader.select') }}</p>
            <p class="lc-dzs__hint">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="lc-dzs__maxhint">{{ __('laracrate::uploader.max_size', ['size' => number_format($maxSizeKb / 1024, 1)]) }}@if(!empty($extensions)) · {{ implode(', ', array_map('strtoupper', $extensions)) }}@endif</p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''" />
        </label>

        {{-- Subiendo (instant: no hay paso de confirmar) --}}
        <div x-show="queue.length > 0 && queue[0]?.status !== 'done'" x-cloak class="lc-dzs__row lc-dzs__row--uploading">
            <svg class="lc-dzs__spin" fill="none" viewBox="0 0 24 24"><circle class="lc-dzs__spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <div class="lc-dzs__meta">
                <p class="lc-dzs__name" x-text="queue[0]?.name ?? '...'"></p>
                <div class="lc-dzs__bar"><div class="lc-dzs__bar-fill" :style="'width: ' + batchProgress + '%'"></div></div>
            </div>
        </div>
    @endif
</div>
