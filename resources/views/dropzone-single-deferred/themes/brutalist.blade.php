@include('laracrate::dropzone._script')

<div
    x-data="laracrateDropzone({
        presignUrl:   @js(route('laracrate.uploads.presign')),
        disk:         @js($disk),
        fileableType: @js($fileableType),
        fileableId:   @js($fileableId),
        collection:   @js($collection),
        maxSizeKb:    @js($maxSizeKb),
        persistQueue: false,
        autoStart:    false,
        maxFiles:     1,
    })"
    class="w-full"
>
    @if ($existing)
        <div
            @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            :class="dragOver ? 'bg-yellow-200 -translate-x-0.5 -translate-y-0.5 shadow-[6px_6px_0_0_rgba(10,10,10,1)]' : 'bg-white shadow-[4px_4px_0_0_rgba(10,10,10,1)]'"
            class="flex items-center gap-4 rounded-[4px] border border-neutral-900 p-4 transition-all duration-150">
            @if(($iconCategory ?? 'mixed') === 'image')
                <img src="{{ $existing->url() }}" alt="" class="h-16 w-16 rounded-[3px] border border-neutral-900 object-cover shrink-0" />
            @elseif($iconCategory === 'video')
                <video controls class="h-16 w-24 rounded-[3px] border border-neutral-900 bg-black object-cover shrink-0"><source src="{{ $existing->url() }}"></video>
            @else
                <div class="h-16 w-16 rounded-[3px] border border-neutral-900 bg-yellow-200 flex items-center justify-center shrink-0">
                    <svg class="w-7 h-7 text-neutral-900" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-500">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="text-sm font-semibold text-neutral-950 truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-xs text-neutral-500">{{ number_format($existing->size / 1024, 0) }} KB</p>
            </div>
            <button type="button" @click="$refs.input.click()" title="{{ __('laracrate::uploader.replace') }}"
                class="inline-flex items-center justify-center w-9 h-9 rounded-[4px] border border-neutral-900 bg-neutral-950 text-white hover:bg-neutral-800 transition-colors shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </button>
            <button type="button" wire:click="removeFile" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" title="{{ __('laracrate::uploader.delete_short') }}"
                class="inline-flex items-center justify-center w-9 h-9 rounded-[4px] border border-neutral-900 bg-white text-neutral-700 hover:bg-red-50 hover:text-red-700 transition-colors shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''" />
        </div>
    @else
        <label x-show="!uploading && queue.length === 0"
            @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            :class="dragOver ? 'border-neutral-900 bg-yellow-200 -translate-x-0.5 -translate-y-0.5 shadow-[6px_6px_0_0_rgba(10,10,10,1)]' : 'border-neutral-900 bg-white shadow-[4px_4px_0_0_rgba(10,10,10,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:shadow-[6px_6px_0_0_rgba(10,10,10,1)]'"
            class="flex flex-col items-center justify-center rounded-[4px] border-2 border-dashed p-6 text-center cursor-pointer transition-all duration-150">
            <svg class="w-10 h-10 text-neutral-900 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                @switch($iconCategory ?? 'mixed')
                    @case('image')<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5z"/>@break
                    @case('video')<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/>@break
                    @case('audio')<path stroke-linecap="round" stroke-linejoin="round" d="M9 9l10.5-3m0 6.553v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 11-.99-3.467l2.31-.66a2.25 2.25 0 001.632-2.163zm0 0V2.25L9 5.25v10.303"/>@break
                    @case('document')<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>@break
                    @default<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
                @endswitch
            </svg>
            <p class="text-[11px] font-mono font-semibold uppercase tracking-[0.25em] text-neutral-500">{{ __('laracrate::uploader.upload') }}</p>
            <p class="mt-1 text-sm text-neutral-950 font-bold">{{ __('laracrate::uploader.drag_or_click_long') }}</p>
            <p class="mt-1 text-xs text-neutral-500 font-mono">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''" />
        </label>

        {{-- Pending: staged, waiting confirm --}}
        <div x-show="queue.length > 0 && queue[0]?.status === 'pending'" x-cloak
             class="flex items-center gap-4 rounded-[4px] border border-neutral-900 bg-yellow-100 p-4 shadow-[4px_4px_0_0_rgba(10,10,10,1)]">
            <div class="h-12 w-12 rounded-[3px] border border-neutral-900 bg-white flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-neutral-900" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700">{{ __('laracrate::uploader.pending') }}</p>
                <p class="text-sm font-bold text-neutral-950 truncate" x-text="queue[0]?.name ?? '...'"></p>
            </div>
            <button type="button" @click="startBatch()"
                class="inline-flex items-center justify-center h-9 px-4 rounded-[4px] border border-neutral-900 bg-neutral-950 text-white text-xs font-bold uppercase tracking-[0.15em] hover:bg-neutral-800 shrink-0">{{ __('laracrate::uploader.submit') }}</button>
            <button type="button" @click="removeItem(0)" title="{{ __('laracrate::uploader.cancel') }}"
                class="inline-flex items-center justify-center w-9 h-9 rounded-[4px] border border-neutral-900 bg-white text-neutral-700 hover:bg-red-50 hover:text-red-700 shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Uploading --}}
        <div x-show="queue.length > 0 && queue[0]?.status === 'uploading'" x-cloak
             class="flex items-center gap-4 rounded-[4px] border border-neutral-900 bg-white p-4 shadow-[4px_4px_0_0_rgba(10,10,10,1)]">
            <svg class="animate-spin h-8 w-8 text-neutral-900 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700">{{ __('laracrate::uploader.uploading') }}</p>
                <p class="text-sm font-semibold text-neutral-950 truncate" x-text="queue[0]?.name ?? '...'"></p>
                <div class="mt-1 h-1.5 w-full bg-neutral-200 border border-neutral-900">
                    <div class="h-full bg-yellow-400 transition-all" :style="'width:' + batchProgress + '%'"></div>
                </div>
            </div>
        </div>
    @endif
</div>
