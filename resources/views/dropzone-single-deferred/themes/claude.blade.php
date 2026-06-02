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
    style="font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; letter-spacing: -0.005em;"
    class="w-full"
>
    @if ($existing)
        <div
            @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            :class="dragOver ? 'border-[#C15F3C] bg-[#FAF9F5]' : 'border-[#1A1A1A]/[0.12] bg-[#FAF9F5]'"
            class="flex items-center gap-4 rounded-[10px] border border-t-2 border-t-[#C15F3C] p-4 transition-colors">
            @if(($iconCategory ?? 'mixed') === 'image')
                <img src="{{ $existing->url() }}" alt="" class="h-14 w-14 rounded-md object-cover shrink-0" />
            @elseif($iconCategory === 'video')
                <video controls class="h-14 w-24 rounded-md bg-black object-cover shrink-0"><source src="{{ $existing->url() }}"></video>
            @else
                <div class="h-14 w-14 rounded-md border border-[#1A1A1A]/[0.08] bg-white flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-[#C15F3C]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[11px] uppercase tracking-wider text-[#6B6560]">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="text-[15px] font-semibold text-[#1A1A1A] truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-xs text-[#6B6560]">{{ number_format($existing->size / 1024, 0) }} KB</p>
            </div>
            <button type="button" @click="$refs.input.click()" title="{{ __('laracrate::uploader.replace') }}"
                class="inline-flex items-center justify-center w-9 h-9 rounded-md bg-[#C15F3C] text-white hover:bg-[#A04E30] transition-colors shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </button>
            <button type="button" wire:click="removeFile" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" title="{{ __('laracrate::uploader.delete_short') }}"
                class="inline-flex items-center justify-center w-9 h-9 rounded-md border border-[#1A1A1A]/[0.12] bg-white text-[#6B6560] hover:bg-red-50 hover:text-red-700 transition-colors shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''" />
        </div>
    @else
        <label x-show="!uploading && queue.length === 0"
            @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            :class="dragOver ? 'border-[#C15F3C] bg-[#FAF9F5]' : 'border-[#1A1A1A]/[0.12] bg-[#FAF9F5] hover:border-[#1A1A1A]/30'"
            class="flex flex-col items-center justify-center rounded-[10px] border border-t-2 border-t-[#C15F3C] p-8 text-center cursor-pointer transition-colors">
            <div class="inline-flex items-center justify-center w-11 h-11 rounded-md border border-[#1A1A1A]/[0.08] bg-white text-[#C15F3C] mb-3">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </div>
            <p class="text-[15px] text-[#1A1A1A] font-semibold">{{ __('laracrate::uploader.upload') }}</p>
            <p class="mt-1 text-[13px] text-[#6B6560]">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="mt-2 text-xs text-[#6B6560]">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''" />
        </label>
        <div x-show="queue.length > 0 && queue[0]?.status === 'pending'" x-cloak class="flex items-center gap-3 p-4 rounded-[10px] border border-t-2 border-t-[#C15F3C] border-[#1A1A1A]/[0.12] bg-[#FAF9F5]">
            <div class="h-10 w-10 rounded-md border border-[#1A1A1A]/[0.08] bg-white text-[#C15F3C] inline-flex items-center justify-center shrink-0"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="flex-1 min-w-0">
                <p class="text-[11px] text-[#6B6560] uppercase tracking-wider">{{ __('laracrate::uploader.pending') }}</p>
                <p class="text-[15px] text-[#1A1A1A] font-semibold truncate" x-text="queue[0]?.name ?? '...'"></p>
            </div>
            <button type="button" @click="startBatch()" x-show="{{ ($hideActions ?? false) ? 'false' : 'true' }}" class="text-xs font-semibold px-4 py-2 bg-[#C15F3C] text-white rounded-md hover:bg-[#A04E30] shrink-0">{{ __('laracrate::uploader.submit') }}</button>
            <button type="button" @click="removeItem(0)" x-show="{{ ($hideActions ?? false) ? 'false' : 'true' }}" title="{{ __('laracrate::uploader.cancel') }}" class="inline-flex items-center justify-center w-9 h-9 rounded-md border border-[#1A1A1A]/[0.12] bg-white text-[#6B6560] hover:text-red-700 shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div x-show="queue.length > 0 && queue[0]?.status === 'uploading'" x-cloak class="flex items-center gap-4 p-4 rounded-[10px] border border-t-2 border-t-[#C15F3C] border-[#1A1A1A]/[0.12] bg-[#FAF9F5]">
            <svg class="animate-spin h-6 w-6 text-[#C15F3C]" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <div class="flex-1 min-w-0">
                <p class="text-[15px] text-[#1A1A1A] font-semibold truncate" x-text="queue[0]?.name ?? '...'"></p>
                <div class="mt-1 h-1 w-full bg-[#1A1A1A]/[0.08] rounded-full overflow-hidden">
                    <div class="h-full bg-[#C15F3C] transition-all" :style="'width:' + batchProgress + '%'"></div>
                </div>
            </div>
        </div>
    @endif
</div>
