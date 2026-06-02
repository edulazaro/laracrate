@include('laracrate::dropzone._script')
<div x-data="laracrateDropzone({presignUrl:@js(route('laracrate.uploads.presign')),disk:@js($disk),fileableType:@js($fileableType),fileableId:@js($fileableId),collection:@js($collection),maxSizeKb:@js($maxSizeKb),persistQueue:false,autoStart:false,maxFiles:1})" class="w-full">
    @if ($existing)
        <div @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            :class="dragOver?'bg-indigo-50 ring-indigo-500':'bg-white ring-gray-200'"
            class="flex items-center gap-4 rounded-md ring-1 shadow-sm p-4 transition-all">
            @if(($iconCategory ?? 'mixed') === 'image')
                <img src="{{ $existing->url() }}" alt="" class="h-14 w-14 rounded object-cover shrink-0"/>
            @elseif($iconCategory === 'video')
                <video controls class="h-14 w-24 rounded bg-black object-cover shrink-0"><source src="{{ $existing->url() }}"></video>
            @else
                <div class="rounded-full bg-indigo-50 p-3 inline-flex items-center justify-center shrink-0"><svg class="w-6 h-6 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($existing->size / 1024, 0) }} KB</p>
            </div>
            <button type="button" @click="$refs.input.click()" class="inline-flex items-center justify-center w-9 h-9 rounded-md bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm shrink-0"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></button>
            <button type="button" wire:click="removeFile" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" class="inline-flex items-center justify-center w-9 h-9 rounded-md bg-white ring-1 ring-gray-200 text-gray-600 hover:bg-red-50 hover:text-red-600 shrink-0"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </div>
    @else
        <label x-show="!uploading && queue.length === 0" @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            :class="dragOver?'bg-indigo-50 ring-indigo-500':'bg-white ring-gray-200 hover:ring-indigo-300'"
            class="flex flex-col items-center justify-center rounded-md ring-1 shadow-sm p-8 text-center cursor-pointer transition-all">
            <div class="rounded-full bg-indigo-50 p-3 mb-3 inline-flex items-center justify-center"><svg class="w-7 h-7 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></div>
            <p class="text-sm font-medium text-gray-900">{{ __('laracrate::uploader.upload') }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="mt-2 text-xs text-gray-500">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </label>
        <div x-show="queue.length > 0 && queue[0]?.status === 'pending'" x-cloak class="flex items-center gap-4 p-4 rounded-md bg-indigo-50 ring-1 ring-indigo-200 shadow-sm">
            <div class="rounded-full bg-white p-2.5 inline-flex items-center justify-center shrink-0"><svg class="w-5 h-5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <div class="flex-1 min-w-0">
                <p class="text-xs text-indigo-700">{{ __('laracrate::uploader.pending') }}</p>
                <p class="text-sm font-medium text-gray-900 truncate" x-text="queue[0]?.name ?? '...'"></p>
            </div>
            <button type="button" @click="startBatch()" x-show="{{ ($hideActions ?? false) ? 'false' : 'true' }}" class="text-xs font-bold uppercase tracking-wider px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 shadow-sm shrink-0">{{ __('laracrate::uploader.submit') }}</button>
            <button type="button" @click="removeItem(0)" x-show="{{ ($hideActions ?? false) ? 'false' : 'true' }}" class="inline-flex items-center justify-center w-9 h-9 rounded-md bg-white ring-1 ring-gray-200 text-gray-600 hover:bg-red-50 hover:text-red-600 shrink-0" title="{{ __('laracrate::uploader.cancel') }}">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div x-show="queue.length > 0 && queue[0]?.status === 'uploading'" x-cloak class="flex items-center gap-4 p-4 rounded-md bg-white ring-1 ring-gray-200 shadow-sm">
            <svg class="animate-spin h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <div class="flex-1 min-w-0"><p class="text-sm font-medium text-gray-900 truncate" x-text="queue[0]?.name ?? '...'"></p><div class="mt-1 h-1 w-full bg-gray-200 rounded-full overflow-hidden"><div class="h-full bg-indigo-600 transition-all" :style="'width:'+batchProgress+'%'"></div></div></div>
        </div>
    @endif
</div>
