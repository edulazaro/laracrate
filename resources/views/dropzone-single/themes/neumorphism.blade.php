@include('laracrate::dropzone._script')
<div x-data="laracrateDropzone({presignUrl:@js(route('laracrate.uploads.presign')),disk:@js($disk),fileableType:@js($fileableType),fileableId:@js($fileableId),collection:@js($collection),maxSizeKb:@js($maxSizeKb),persistQueue:false,autoStart:true,maxFiles:1})" class="w-full">
    @if ($existing)
        <div @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            class="flex items-center gap-4 rounded-2xl p-4 transition-all bg-gray-100"
            style="box-shadow: inset 4px 4px 8px rgba(0,0,0,0.07), inset -4px -4px 8px rgba(255,255,255,0.7);">
            @if(($iconCategory ?? 'mixed') === 'image')
                <img src="{{ $existing->url() }}" alt="" class="h-14 w-14 rounded-2xl object-cover shrink-0"/>
            @else
                <div class="h-14 w-14 rounded-2xl bg-gray-100 flex items-center justify-center text-gray-600 shrink-0" style="box-shadow: 4px 4px 8px rgba(0,0,0,0.07), -4px -4px 8px rgba(255,255,255,0.7);"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-700 truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($existing->size / 1024, 0) }} KB</p>
            </div>
            <button type="button" @click="$refs.input.click()" class="w-9 h-9 rounded-xl bg-gray-100 text-gray-700 hover:text-gray-900 shrink-0 inline-flex items-center justify-center" style="box-shadow: 3px 3px 6px rgba(0,0,0,0.07), -3px -3px 6px rgba(255,255,255,0.7);"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></button>
            <button type="button" wire:click="removeFile" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" class="w-9 h-9 rounded-xl bg-gray-100 text-gray-500 hover:text-red-600 shrink-0 inline-flex items-center justify-center" style="box-shadow: 3px 3px 6px rgba(0,0,0,0.07), -3px -3px 6px rgba(255,255,255,0.7);"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </div>
    @else
        <label x-show="!uploading && queue.length === 0" @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            class="rounded-2xl p-8 text-center cursor-pointer transition-all bg-gray-100 flex flex-col items-center"
            style="box-shadow: 8px 8px 16px rgba(0,0,0,0.07), -8px -8px 16px rgba(255,255,255,0.7);">
            <div class="rounded-2xl w-14 h-14 flex items-center justify-center text-gray-600 mb-3 bg-gray-100" style="box-shadow: inset 4px 4px 8px rgba(0,0,0,0.07), inset -4px -4px 8px rgba(255,255,255,0.7);"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></div>
            <p class="text-sm font-medium text-gray-700">{{ __('laracrate::uploader.upload') }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="mt-2 text-xs text-gray-500">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </label>
        <div x-show="uploading || queue.length > 0" x-cloak class="flex items-center gap-4 rounded-2xl p-4 bg-gray-100" style="box-shadow: inset 4px 4px 8px rgba(0,0,0,0.07), inset -4px -4px 8px rgba(255,255,255,0.7);">
            <svg class="animate-spin h-6 w-6 text-gray-700" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <div class="flex-1 min-w-0"><p class="text-sm font-medium text-gray-700 truncate" x-text="queue[0]?.name ?? '...'"></p><div class="mt-1 h-1 w-full bg-gray-200 rounded-full overflow-hidden"><div class="h-full bg-gray-600 transition-all" :style="'width:'+batchProgress+'%'"></div></div></div>
        </div>
    @endif
</div>
