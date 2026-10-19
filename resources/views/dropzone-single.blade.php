{{--
    laracrate-dropzone-single: one file, one view, every theme (resources/css/laracrate.css). $deferred stages the file until the user
    confirms; otherwise it uploads as soon as it is picked.
--}}
@include('laracrate::partials.dropzone-script')

@php($deferred = $deferred ?? false)

<div
    x-data="laracrateDropzone({
        fileableType: @js($fileableType),
        fileableId:   @js($fileableId),
        collection:   @js($collection),
        persistQueue: false,
        autoStart:    @js(! $deferred),
        maxFiles:     1,
    })"
    @if ($appearance['theme']) data-wire-theme="{{ $appearance['theme'] }}" @endif
    class="laracrate laracrate-single {{ $appearance['classes'] }}"
    @if ($appearance['style']) style="{{ $appearance['style'] }}" @endif
>
    @if ($existing)
        {{-- Picking another file replaces it: at once, or once confirmed in the deferred one. --}}
        <div class="laracrate-card" x-show="queue.length === 0">
            @switch($iconCategory ?? 'mixed')
                @case('image')
                    <img src="{{ $existing->url() }}" alt="{{ $existing->original_name }}" class="laracrate-card__thumb">
                    @break
                @case('video')
                    <video controls class="laracrate-card__thumb is-video"><source src="{{ $existing->url() }}"></video>
                    @break
                @case('audio')
                    <audio controls class="laracrate-card__audio"><source src="{{ $existing->url() }}"></audio>
                    @break
                @default
                    <span class="laracrate-icon">@include('laracrate::partials.icon', ['icon' => 'document'])</span>
            @endswitch
            <div class="laracrate-card__body">
                <p class="laracrate-name">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="laracrate-meta">{{ number_format($existing->size / 1024, 1) }} KB · {{ strtoupper($existing->extension ?? '') }}</p>
            </div>
            <button type="button" @click="$refs.input.click()" class="laracrate-icon-button is-accent" title="{{ __('laracrate::uploader.replace') }}">@include('laracrate::partials.icon', ['icon' => 'upload'])</button>
            <a href="{{ $existing->url() }}" target="_blank" class="laracrate-icon-button" title="{{ __('laracrate::uploader.view') }}">@include('laracrate::partials.icon', ['icon' => 'eye'])</a>
            <button type="button" wire:click="removeFile" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                class="laracrate-icon-button is-danger" title="{{ __('laracrate::uploader.delete_short') }}">@include('laracrate::partials.icon', ['icon' => 'close'])</button>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="laracrate-hidden"
                @change="handleFiles($event.target.files); $event.target.value = ''" />
        </div>
    @else
        <label
            x-show="!uploading && queue.length === 0"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            role="button"
            tabindex="0"
            :class="dragOver && 'is-dragover'"
            class="laracrate-drop is-compact"
        >
            <span class="laracrate-icon">@include('laracrate::partials.icon', ['icon' => $iconCategory ?? 'upload'])</span>
            <p class="laracrate-title">{{ __('laracrate::uploader.select') }}</p>
            <p class="laracrate-subtitle">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="laracrate-hint">
                {{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}
                @if (!empty($extensions))
                    · {{ implode(', ', array_map('strtoupper', $extensions)) }}
                @endif
            </p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="laracrate-hidden"
                @change="handleFiles($event.target.files); $event.target.value = ''" />
        </label>
    @endif

    @if ($deferred)
        <div x-show="queue.length > 0 && queue[0]?.status === 'pending'" x-cloak class="laracrate-card">
            <span class="laracrate-icon">@include('laracrate::partials.icon', ['icon' => 'file'])</span>
            <div class="laracrate-card__body">
                <p class="laracrate-label">{{ __('laracrate::uploader.pending') }}</p>
                <p class="laracrate-name" x-text="queue[0]?.name ?? '...'"></p>
            </div>
            @unless ($hideActions ?? false)
                <button type="button" @click="startBatch()" class="laracrate-button is-small">{{ __('laracrate::uploader.submit') }}</button>
                <button type="button" @click="removeItem(0)" class="laracrate-icon-button is-danger" title="{{ __('laracrate::uploader.cancel') }}">@include('laracrate::partials.icon', ['icon' => 'close'])</button>
            @endunless
        </div>
    @endif

    <div x-show="{{ $deferred ? "queue.length > 0 && queue[0]?.status === 'uploading'" : 'uploading || queue.length > 0' }}" x-cloak class="laracrate-card is-busy">
        <span class="laracrate-spinner">@include('laracrate::partials.icon', ['icon' => 'spinner'])</span>
        <div class="laracrate-card__body">
            <p class="laracrate-name" x-text="queue[0]?.name ?? '...'"></p>
            <div class="laracrate-progress"><div class="laracrate-progress__bar" :style="'width: ' + batchProgress + '%'"></div></div>
        </div>
    </div>
</div>
