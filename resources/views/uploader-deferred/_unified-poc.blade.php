{{--
    PoC: UN blade para los 11 temas Y los 2 layouts (portrait/row) de
    `uploader-deferred` (imagen, Livewire). Tema por [data-wire-theme]; layout por
    modificador de raíz is-portrait / is-row (el CSS hace la disposición, sin @if
    de layout). Clases .lc-up__*. Estados (staged/file/empty) son del componente.
--}}
<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    data-wire-theme="{{ $theme ?? 'default' }}"
    x-data="{ over: false }"
    class="lc-up is-{{ $layout ?? 'portrait' }}"
>
    @if($state === 'staged')
        <div class="lc-up__card">
            <div class="lc-up__media">
                @if($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="lc-up__img {{ $roundedClass }}">
                @else
                    <span class="lc-up__media-ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                @endif
            </div>
            <div class="lc-up__body">
                <p class="lc-up__label">{{ __('laracrate::uploader.pending') }}</p>
                <p class="lc-up__name">{{ $pending?->getClientOriginalName() }}</p>
                <p class="lc-up__meta">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
                <div class="lc-up__actions">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="lc-up__btn">
                        <span wire:loading.remove wire:target="submit">{{ __('laracrate::uploader.submit') }}</span>
                        <span wire:loading wire:target="submit">{{ __('laracrate::uploader.uploading') }}</span>
                    </button>
                    <button type="button" wire:click="cancel" class="lc-up__icon-btn" title="{{ __('laracrate::uploader.cancel') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        @error('pending') <p class="lc-up__error">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="lc-up__card">
            <div class="lc-up__media">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="lc-up__img {{ $roundedClass }}">
                @else
                    <span class="lc-up__media-ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                @endif
            </div>
            <div class="lc-up__body">
                <p class="lc-up__label">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="lc-up__name">{{ $file->original_name ?: $file->name }}</p>
                <p class="lc-up__meta">{{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing') · <span class="lc-up__processing">{{ str(__('laracrate::uploader.processing'))->lower() }}</span>
                    @elseif($state === 'failed') · <span class="lc-up__failed">{{ str(__('laracrate::uploader.failed'))->lower() }}</span>@endif
                </p>
                <div class="lc-up__actions">
                    <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" class="lc-up__btn lc-up__btn--danger">{{ __('laracrate::uploader.delete') }}</button>
                </div>
            </div>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over && 'is-dragover'" class="lc-up__drop">
            <span class="lc-up__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></span>
            <div class="lc-up__drop-body">
                <p class="lc-up__title">{{ __('laracrate::uploader.select') }}</p>
                <p class="lc-up__sub">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }} · {{ __('laracrate::uploader.max_size', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            </div>
            <span class="lc-up__choose">{{ __('laracrate::uploader.choose') }}</span>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden">
        </div>
        <div wire:loading wire:target="pending" class="lc-up__preparing">{{ str(__('laracrate::uploader.preparing'))->lower() }}</div>
        @error('pending') <p class="lc-up__error">{{ $message }}</p> @enderror
    @endif
</div>
