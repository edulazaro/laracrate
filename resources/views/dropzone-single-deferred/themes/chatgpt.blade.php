@include('laracrate::dropzone._script')
<div
    x-data="laracrateDropzone({presignUrl:@js(route('laracrate.uploads.presign')),disk:@js($disk),fileableType:@js($fileableType),fileableId:@js($fileableId),collection:@js($collection),maxSizeKb:@js($maxSizeKb),persistQueue:false,autoStart:false,maxFiles:1})"
    style="font-family: 'Söhne', ui-sans-serif, system-ui, -apple-system, sans-serif;" class="w-full">
    @if ($existing)
        <div @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            :class="dragOver?'border-[#0D0D0D] bg-[#F7F7F8]':'border-[#E5E5E5] bg-white'"
            class="flex items-center gap-4 rounded-xl border p-4 transition-colors">
            @if(($iconCategory ?? 'mixed') === 'image')
                <img src="{{ $existing->url() }}" alt="" class="h-14 w-14 rounded-lg object-cover shrink-0"/>
            @elseif($iconCategory === 'video')
                <video controls class="h-14 w-24 rounded-lg bg-black object-cover shrink-0"><source src="{{ $existing->url() }}"></video>
            @else
                <div class="h-14 w-14 rounded-lg border border-[#E5E5E5] bg-white flex items-center justify-center shrink-0"><svg class="w-6 h-6 text-[#0D0D0D]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[11px] text-[#5D5D5D]">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="text-[15px] font-semibold text-[#0D0D0D] truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-xs text-[#5D5D5D]">{{ number_format($existing->size / 1024, 0) }} KB</p>
            </div>
            <button type="button" @click="$refs.input.click()" class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-[#0D0D0D] text-white hover:bg-black shrink-0"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></button>
            <button type="button" wire:click="removeFile" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-[#E5E5E5] text-[#5D5D5D] hover:text-red-700 hover:bg-red-50 shrink-0"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </div>
    @else
        <label x-show="!uploading && queue.length === 0" @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            :class="dragOver?'border-[#0D0D0D] bg-[#F7F7F8]':'border-[#E5E5E5] bg-white hover:border-[#0D0D0D]/40'"
            class="flex flex-col items-center justify-center rounded-xl border p-8 text-center cursor-pointer transition-colors">
            <div class="inline-flex items-center justify-center w-11 h-11 rounded-lg border border-[#E5E5E5] bg-white text-[#0D0D0D] mb-3"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></div>
            <p class="text-[15px] text-[#0D0D0D] font-semibold">{{ __('laracrate::uploader.upload') }}</p>
            <p class="mt-1 text-[13px] text-[#5D5D5D]">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="mt-2 text-xs text-[#5D5D5D]">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </label>
        <div x-show="uploading || queue.length > 0" x-cloak class="flex items-center gap-4 p-4 rounded-xl border border-[#E5E5E5] bg-white">
            <svg class="animate-spin h-6 w-6 text-[#0D0D0D]" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <div class="flex-1 min-w-0"><p class="text-[15px] text-[#0D0D0D] font-semibold truncate" x-text="queue[0]?.name ?? '...'"></p><div class="mt-1 h-1 w-full bg-[#E5E5E5] rounded-full overflow-hidden"><div class="h-full bg-[#0D0D0D] transition-all" :style="'width:'+batchProgress+'%'"></div></div></div>
        </div>
    @endif
</div>
