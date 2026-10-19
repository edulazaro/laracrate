{{--
    laracrate-dropzone: one view, every theme. The look
    is resources/css/laracrate.css: [data-wire-theme] picks the theme, and the theme only
    sets variables. $deferred stages the files until the user confirms (or a
    `laracrate-start-batch` event does); otherwise they upload as soon as they are picked.
--}}
@include('laracrate::partials.dropzone-script')

@php
    $deferred = $deferred ?? false;
    $layout = $layout ?? 'grid';
    $multipleAllowed = $multipleAllowed ?? ($multiple ?? true);
    $showSlotPicker = $showSlotPicker ?? false;
    $requiresSlot = $requiresSlot ?? false;
@endphp

<div
    x-data="laracrateDropzone({
        fileableType: @js($fileableType),
        fileableId:   @js($fileableId),
        {{-- The route's collection, as always: start-batch events match on it only when it is set. --}}
        collection:   @js(request()->route()?->parameter('collection') ?? null),
        persistQueue: @js($persistQueue),
        autoStart:    @js(! $deferred),
        maxFiles:     @js($effectiveMaxFiles ?? null),
    })"
    @if ($deferred)
        x-init="(() => {
            const sync = () => {
                const v = $el.dataset.effectiveMax;
                cfg.maxFiles = (v === '' || v == null) ? null : parseInt(v, 10);
                if (cfg.maxFiles !== null && queue.length > cfg.maxFiles) {
                    queue = queue.slice(0, cfg.maxFiles);
                }
            };
            sync();
            new MutationObserver(sync).observe($el, { attributes: true, attributeFilter: ['data-effective-max'] });
        })()"
        data-effective-max="{{ $effectiveMaxFiles ?? '' }}"
    @endif
    @if ($deferred) data-deferred @endif
    @if ($appearance['theme']) data-wire-theme="{{ $appearance['theme'] }}" @endif
    class="laracrate laracrate-dropzone {{ $appearance['classes'] }}"
    @if ($appearance['style']) style="{{ $appearance['style'] }}" @endif
>
    @if ($showSlotPicker)
        <div class="laracrate-slots" x-data="{ open: false }">
            @if ($pickerLabel)
                <p class="laracrate-label">{{ $pickerLabel }}</p>
            @endif
            <div class="laracrate-slots__wrap">
                <button type="button" @click="open = !open" class="laracrate-slots__trigger">
                    <span class="laracrate-truncate">
                        @php($selectedOpt = collect($pickerOptions)->firstWhere('id', $this->selectedSlotId))
                        @if ($selectedOpt)
                            @if (!empty($selectedOpt['color']))
                                <span class="laracrate-dot" style="background: {{ $selectedOpt['color'] }}"></span>
                            @endif
                            {{ $selectedOpt['name'] }}
                        @else
                            <span class="laracrate-muted">{{ $pickerPlaceholder }}</span>
                        @endif
                    </span>
                    <svg class="laracrate-chevron" :class="open && 'is-open'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" @keydown.escape.window="open = false" class="laracrate-slots__menu">
                    <ul>
                        @if ($pickerOptional)
                            <li>
                                <button type="button" wire:click="$set('selectedSlotId', null)" @click="open = false"
                                    class="laracrate-slots__option {{ $this->selectedSlotId === null ? 'is-active' : '' }}">{{ $pickerPlaceholder }}</button>
                            </li>
                        @endif
                        @foreach ($pickerOptions as $opt)
                            <li>
                                <button type="button" wire:click="$set('selectedSlotId', {{ (int) $opt['id'] }})" @click="open = false"
                                    class="laracrate-slots__option {{ $this->selectedSlotId === (int) $opt['id'] ? 'is-active' : '' }}">
                                    @if (!empty($opt['color']))
                                        <span class="laracrate-dot" style="background: {{ $opt['color'] }}"></span>
                                    @endif
                                    <span class="laracrate-truncate">{{ $opt['name'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @if ($requiresSlot)
                <p class="laracrate-hint is-warning">{{ __('laracrate::uploader.slot_required_hint') }}</p>
            @endif
        </div>
    @endif

    <div
        x-show="!reachedMax"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
        @click="$refs.input.click()"
        @keydown.enter.prevent="$refs.input.click()"
        role="button"
        tabindex="0"
        :class="dragOver && 'is-dragover'"
        class="laracrate-drop {{ $requiresSlot ? 'is-locked' : '' }}"
    >
        <span class="laracrate-icon">@include('laracrate::partials.icon', ['icon' => $iconCategory ?? 'upload'])</span>
        <p class="laracrate-title">{{ $deferred ? __('laracrate::uploader.select') : __('laracrate::uploader.upload') }}</p>
        <p class="laracrate-subtitle">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
        <p class="laracrate-hint">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
        <input type="file" x-ref="input" class="laracrate-hidden"
            :multiple="@js($multipleAllowed) && (cfg.maxFiles === null || cfg.maxFiles > 1)"
            accept="{{ $acceptAttr }}"
            @change="handleFiles($event.target.files); $event.target.value = ''" />
    </div>

    <div x-show="reachedMax" x-cloak class="laracrate-drop is-full">
        <span class="laracrate-icon">@include('laracrate::partials.icon', ['icon' => 'check'])</span>
        <p class="laracrate-title">{{ __('laracrate::uploader.max_reached_title') }}</p>
        <p class="laracrate-subtitle" x-text="(cfg.maxFiles ?? '∞') + ' ' + @js(__('laracrate::uploader.max_reached_unit'))"></p>
    </div>

    <div x-show="queue.length > 0" x-cloak class="laracrate-queue">
        <div class="laracrate-status">
            <p>
                <span x-show="!uploading && pendingCount > 0" x-text="pendingCount + ' ' + @js(__('laracrate::uploader.in_queue'))"></span>
                <span x-show="uploading">{{ str(__('laracrate::uploader.uploading'))->lower() }}</span>
                <span x-show="!uploading && pendingCount === 0 && doneCount > 0" x-text="doneCount + ' OK' + (errorCount > 0 ? ' / ' + errorCount + ' err' : '')"></span>
            </p>
            <span x-show="uploading" x-text="batchProgress + '%'"></span>
        </div>

        <div x-show="uploading" class="laracrate-progress"><div class="laracrate-progress__bar" :style="'width: ' + batchProgress + '%'"></div></div>

        @if ($layout === 'list')
            <div class="laracrate-list">
                <template x-for="(item, i) in queue" :key="item.uid || item.id">
                    <div class="laracrate-row" :data-state="item.status">
                        {{-- An image shows itself, small; anything else, the file icon. --}}
                        <template x-if="item.preview">
                            <img :src="item.preview" alt="" class="laracrate-row__thumb">
                        </template>
                        <template x-if="!item.preview">
                            <span class="laracrate-row__icon">@include('laracrate::partials.icon', ['icon' => 'file'])</span>
                        </template>
                        <p class="laracrate-row__name" x-text="item.name"></p>
                        <p class="laracrate-row__size" x-text="item.size < 1024 ? item.size + ' B' : (item.size < 1048576 ? (item.size / 1024).toFixed(1) + ' KB' : (item.size / 1048576).toFixed(1) + ' MB')"></p>
                        <template x-if="item.status === 'uploading'">
                            <span class="laracrate-spinner">@include('laracrate::partials.icon', ['icon' => 'spinner'])</span>
                        </template>
                        <template x-if="item.status === 'done'">
                            <span class="laracrate-row__done">@include('laracrate::partials.icon', ['icon' => 'check'])</span>
                        </template>
                        <template x-if="item.status === 'error'">
                            <button type="button" @click.stop="retryItem(i)" :title="item.error || @js(__('laracrate::uploader.retry'))" class="laracrate-row__retry">@include('laracrate::partials.icon', ['icon' => 'retry'])</button>
                        </template>
                        <template x-if="item.status === 'pending'">
                            <button type="button" @click.stop="removeItem(i)" title="{{ __('laracrate::uploader.delete_short') }}" class="laracrate-row__remove">@include('laracrate::partials.icon', ['icon' => 'close'])</button>
                        </template>
                    </div>
                </template>
            </div>
        @else
            <div class="laracrate-grid">
                <template x-for="(item, i) in queue" :key="item.uid || item.id">
                    <div class="laracrate-tile" :data-state="item.status">
                        <template x-if="item.preview">
                            <img :src="item.preview" alt="" class="laracrate-tile__preview">
                        </template>
                        <template x-if="!item.preview">
                            <div class="laracrate-tile__placeholder">@include('laracrate::partials.icon', ['icon' => 'file'])</div>
                        </template>

                        <div class="laracrate-tile__overlay">
                            <template x-if="item.status === 'uploading' || ({{ $deferred ? 'false' : 'true' }} && item.status === 'pending')">
                                <span class="laracrate-spinner">@include('laracrate::partials.icon', ['icon' => 'spinner'])</span>
                            </template>
                            <template x-if="item.status === 'done'">
                                <span>@include('laracrate::partials.icon', ['icon' => 'check'])</span>
                            </template>
                            <template x-if="item.status === 'error'">
                                <button type="button" @click.stop="retryItem(i)" :title="item.error || @js(__('laracrate::uploader.retry'))" class="laracrate-tile__retry">@include('laracrate::partials.icon', ['icon' => 'retry'])</button>
                            </template>
                        </div>

                        @if ($deferred)
                            <template x-if="item.status === 'pending'">
                                <button type="button" @click.stop="removeItem(i)" title="{{ __('laracrate::uploader.delete_short') }}" class="laracrate-tile__remove">@include('laracrate::partials.icon', ['icon' => 'close'])</button>
                            </template>
                        @endif

                        <p class="laracrate-tile__name" x-text="item.name"></p>
                    </div>
                </template>
            </div>
        @endif

        @if ($deferred && ! ($hideActions ?? false))
            <div x-show="!uploading && pendingCount > 0" class="laracrate-actions">
                <button type="button" @click="startBatch()" class="laracrate-button">
                    {{ __('laracrate::uploader.submit') }}
                    <span x-show="pendingCount > 1" class="laracrate-button__count" x-text="'(' + pendingCount + ')'"></span>
                </button>
                <button type="button" @click="clearQueue()" class="laracrate-link">{{ __('laracrate::uploader.cancel') }}</button>
            </div>
        @endif
    </div>
</div>
