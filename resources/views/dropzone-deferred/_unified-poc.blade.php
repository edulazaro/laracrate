@include('laracrate::dropzone._script')

{{--
    PoC: UN blade para los 11 temas de dropzone-deferred, construido sobre la
    estructura COMPLETA de `studio` (el tema canónico). Todos los temas heredan
    TODO (icono por tipo, estado max alcanzado, slot-picker, nombre de archivo,
    estados del item). El tema solo cambia el CSS, vía [data-wire-theme].

    Sin @if por tema. Los condicionales que quedan son FEATURE flags
    (showSlotPicker, layout, requiresSlot, multiple) — comunes a todos los temas.
    Estados → clases is-<status>. hideActions → is-hide-actions.
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
        autoStart:    false,
        maxFiles:     @js($effectiveMaxFiles ?? null),
    })"
    class="lc-dz {{ ($hideActions ?? false) ? 'is-hide-actions' : '' }}"
>
    {{-- Slot picker (FEATURE flag, no tema) --}}
    @if ($showSlotPicker)
        <div class="lc-dz__slots" x-data="{ open: false }">
            @if ($pickerLabel)<p class="lc-dz__slots-label">{{ $pickerLabel }}</p>@endif
            <div class="lc-dz__slots-wrap">
                <button type="button" @click="open = !open" class="lc-dz__slots-trigger">
                    <span class="truncate">
                        @php($selectedOpt = collect($pickerOptions)->firstWhere('id', $this->selectedSlotId))
                        @if ($selectedOpt)
                            @if (!empty($selectedOpt['color']))<span class="lc-dz__dot" style="background:{{ $selectedOpt['color'] }}"></span>@endif
                            {{ $selectedOpt['name'] }}
                        @else
                            <span class="lc-dz__muted">{{ $pickerPlaceholder }}</span>
                        @endif
                    </span>
                    <svg class="lc-dz__chevron" :class="open && 'is-open'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" @keydown.escape.window="open = false" class="lc-dz__slots-menu">
                    <ul>
                        @if ($pickerOptional)
                            <li><button type="button" wire:click="$set('selectedSlotId', null)" @click="open = false" class="lc-dz__slots-opt {{ $this->selectedSlotId === null ? 'is-active' : '' }}">{{ $pickerPlaceholder }}</button></li>
                        @endif
                        @foreach ($pickerOptions as $opt)
                            <li><button type="button" wire:click="$set('selectedSlotId', {{ (int) $opt['id'] }})" @click="open = false" class="lc-dz__slots-opt {{ $this->selectedSlotId === (int) $opt['id'] ? 'is-active' : '' }}">
                                @if (!empty($opt['color']))<span class="lc-dz__dot" style="background:{{ $opt['color'] }}"></span>@endif
                                <span class="truncate">{{ $opt['name'] }}</span>
                            </button></li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @if ($requiresSlot)<p class="lc-dz__slots-hint">{{ __('laracrate::uploader.slot_required_hint') }}</p>@endif
        </div>
    @endif

    {{-- Dropzone --}}
    <div
        x-show="!reachedMax"
        @if ($requiresSlot) class="lc-dz__drop is-locked" @else class="lc-dz__drop" @endif
        @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
        @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
        @click="$refs.input.click()" role="button" tabindex="0"
        :class="dragOver && 'is-dragover'"
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

    {{-- Estado: max alcanzado --}}
    <div x-show="reachedMax" x-cloak class="lc-dz__max">
        <span class="lc-dz__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></span>
        <p class="lc-dz__title">{{ __('laracrate::uploader.max_reached_title') }}</p>
        <p class="lc-dz__sub" x-text="'máximo: ' + (cfg.maxFiles ?? '∞') + ' ' + @js(__('laracrate::uploader.max_reached_unit'))"></p>
    </div>

    {{-- Cola + acciones --}}
    <div x-show="queue.length > 0" class="lc-dz__queue" x-cloak>
        <div class="lc-dz__status">
            <span>
                <span x-show="!uploading && pendingCount > 0" x-text="pendingCount + ' en cola'"></span>
                <span x-show="uploading">{{ str(__('laracrate::uploader.uploading'))->lower() }}</span>
                <span x-show="!uploading && pendingCount === 0 && doneCount > 0" x-text="doneCount + ' OK' + (errorCount > 0 ? ' / ' + errorCount + ' err' : '')"></span>
            </span>
            <span x-show="uploading" x-text="batchProgress + '%'"></span>
        </div>
        <div x-show="uploading" class="lc-dz__bar"><div class="lc-dz__bar-fill" :style="'width: ' + batchProgress + '%'"></div></div>

        @if (($layout ?? 'grid') === 'list')
            @include('laracrate::dropzone-deferred._queue-list')
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
                    <template x-if="item.status === 'pending'"><button type="button" @click.stop="removeItem(i)" class="lc-dz__remove">×</button></template>
                    <p class="lc-dz__name" x-text="item.name"></p>
                </div>
            </template>
        </div>
        @endif

        <div x-show="!uploading && pendingCount > 0" class="lc-dz__actions">
            <button type="button" @click="startBatch()" class="lc-dz__btn">
                {{ str(__('laracrate::uploader.submit'))->lower() }}
                <span x-show="pendingCount > 1" x-text="'(' + pendingCount + ')'"></span>
            </button>
            <button type="button" @click="clearQueue()" class="lc-dz__cancel">{{ str(__('laracrate::uploader.cancel'))->lower() }}</button>
        </div>
    </div>
</div>
