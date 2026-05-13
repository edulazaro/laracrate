@include('laracrate::dropzone._script')
<div x-data="laracrateDropzone({presignUrl:@js(route('laracrate.uploads.presign')),disk:@js($disk),fileableType:@js($fileableType),fileableId:@js($fileableId),collection:@js($collection),maxSizeKb:@js($maxSizeKb),persistQueue:false,autoStart:false,maxFiles:1})" class="w-full">
    @if ($existing)
        <div @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            :class="dragOver?'border-blue-500':'border-gray-300'"
            class="flex items-center gap-3 border-l-2 pl-4 py-3 transition-colors">
            @if(($iconCategory ?? 'mixed') === 'image')<img src="{{ $existing->url() }}" alt="" class="h-10 w-10 object-cover shrink-0"/>@endif
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900 truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-xs text-gray-400">{{ number_format($existing->size / 1024, 0) }} KB</p>
            </div>
            <button type="button" @click="$refs.input.click()" class="text-xs text-gray-500 hover:text-gray-900 underline">{{ __('laracrate::uploader.replace') }}</button>
            <button type="button" wire:click="removeFile" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" class="text-xs text-gray-400 hover:text-red-600 underline">{{ __('laracrate::uploader.delete_short') }}</button>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </div>
    @else
        <label x-show="!uploading && queue.length === 0" @dragover.prevent="dragOver=true" @dragleave.prevent="dragOver=false" @drop.prevent="dragOver=false;handleFiles($event.dataTransfer.files)"
            :class="dragOver?'border-blue-500 bg-blue-50/50':'border-gray-300 hover:border-gray-500'"
            class="border-l-2 pl-4 py-6 cursor-pointer transition-colors block">
            <p class="text-sm text-gray-700">{{ __('laracrate::uploader.drag_or_click_long') }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden" @change="handleFiles($event.target.files); $event.target.value = ''"/>
        </label>
        <div x-show="uploading || queue.length > 0" x-cloak class="border-l-2 border-blue-500 pl-4 py-3 flex items-center gap-3">
            <svg class="animate-spin h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <p class="text-sm text-gray-700 truncate" x-text="queue[0]?.name ?? '...'"></p><span class="text-xs text-gray-400 tabular-nums" x-text="batchProgress + '%'"></span>
        </div>
    @endif
</div>
