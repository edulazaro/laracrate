{{--
    laracrate-uploader: a single file (an avatar, a logo), one view, every theme
    (resources/css/laracrate.css), two layouts: `row` and `portrait`, which are only CSS.

    By default it puts the file in the page's upload queue in its mode, through PHP, so
    it keeps uploading after a wire:navigate; a bridge refreshes it when the file is
    saved and shows a refusal under it. With $deferred it stages the file with
    Livewire's temporary upload and saves it on submit().
--}}
@php
    $deferred = $deferred ?? false;
    $dropToInput = "over = false; if (\$event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add(\$event.dataTransfer.files[0]); \$refs.input.files = dt.files; \$refs.input.dispatchEvent(new Event('change', { bubbles: true })); }";
    $errorKey = $deferred ? 'pending' : 'upload';
@endphp

<div
    @if (in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    @if ($appearance['theme']) data-wire-theme="{{ $appearance['theme'] }}" @endif
    class="laracrate laracrate-uploader is-{{ $layout === 'portrait' ? 'portrait' : 'row' }} {{ $appearance['classes'] }}"
    @if ($appearance['style']) style="{{ $appearance['style'] }}" @endif
>
    @if ($deferred && $state === 'staged')
        <div class="laracrate-uploader__card is-staged">
            <div class="laracrate-uploader__media">
                @if ($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="laracrate-uploader__image">
                @else
                    <span class="laracrate-uploader__placeholder">@include('laracrate::partials.icon', ['icon' => 'file'])</span>
                @endif
            </div>
            <div class="laracrate-uploader__body">
                <p class="laracrate-label">{{ __('laracrate::uploader.pending') }}</p>
                <p class="laracrate-name">{{ $pending?->getClientOriginalName() }}</p>
                <p class="laracrate-meta">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
            </div>
            <div class="laracrate-uploader__actions">
                <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="laracrate-button">
                    <span wire:loading.remove wire:target="submit">{{ __('laracrate::uploader.submit') }}</span>
                    <span wire:loading wire:target="submit" class="laracrate-pulse">{{ __('laracrate::uploader.uploading') }}</span>
                </button>
                <button type="button" wire:click="cancel" title="{{ __('laracrate::uploader.cancel') }}" class="laracrate-icon-button">@include('laracrate::partials.icon', ['icon' => 'close'])</button>
            </div>
        </div>
    @elseif ($file)
        <div class="laracrate-uploader__card"
            @unless ($deferred)
                @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="{{ $dropToInput }}"
            @endunless
        >
            <div class="laracrate-uploader__media">
                @if ($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="laracrate-uploader__image">
                @else
                    <span class="laracrate-uploader__placeholder">@include('laracrate::partials.icon', ['icon' => 'file'])</span>
                @endif
                @unless ($deferred)
                    <div x-show="over" x-cloak class="laracrate-uploader__drop-hint">{{ str(__('laracrate::uploader.drop_to_replace'))->lower() }}</div>
                @endunless
            </div>
            <div class="laracrate-uploader__body">
                <p class="laracrate-label">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="laracrate-name">{{ $file->original_name ?: $file->name }}</p>
                <p class="laracrate-meta">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if ($state === 'pending' || $state === 'processing')
                        · <span class="laracrate-pulse">{{ str(__('laracrate::uploader.processing'))->lower() }}</span>
                    @elseif ($state === 'failed')
                        · <span class="is-error">{{ str(__('laracrate::uploader.failed'))->lower() }}</span>
                    @endif
                </p>
            </div>
            <div class="laracrate-uploader__actions">
                @unless ($deferred)
                    <button type="button" @click="$refs.input.click()" class="laracrate-button laracrate-uploader__replace">{{ __('laracrate::uploader.replace') }}</button>
                @endunless
                <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                    title="{{ __('laracrate::uploader.delete_short') }}" class="laracrate-icon-button is-danger">@include('laracrate::partials.icon', ['icon' => 'trash'])</button>
            </div>
            @unless ($deferred)
                @include('laracrate::partials.uploader-input')
            @endunless
        </div>
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="{{ $dropToInput }}"
            @click="$refs.input.click()" @keydown.enter.prevent="$refs.input.click()" role="button" tabindex="0"
            :class="over && 'is-dragover'"
            class="laracrate-drop laracrate-uploader__drop"
        >
            <span class="laracrate-icon">@include('laracrate::partials.icon', ['icon' => 'upload'])</span>
            <div class="laracrate-uploader__drop-body">
                <p class="laracrate-title">{{ $deferred ? __('laracrate::uploader.select') : __('laracrate::uploader.upload') }}</p>
                <p class="laracrate-subtitle">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
                <p class="laracrate-hint">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            </div>
            <span class="laracrate-button is-small laracrate-uploader__choose">{{ __('laracrate::uploader.choose') }}</span>
            @if ($deferred)
                <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="laracrate-hidden" />
                <p wire:loading wire:target="pending" class="laracrate-hint laracrate-pulse">{{ str(__('laracrate::uploader.preparing'))->lower() }}</p>
            @else
                @include('laracrate::partials.uploader-input')
                <p x-cloak x-show="window.Laracrate.uploads.busyFor($wire.uploadScope)" class="laracrate-hint laracrate-pulse">{{ str(__('laracrate::uploader.uploading'))->lower() }}</p>
            @endif
        </div>
    @endif

    @error($errorKey) <p class="laracrate-error">{{ $message }}</p> @enderror
</div>
