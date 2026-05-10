<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div class="flex items-center gap-3 border-l-2 border-blue-500 bg-white pl-3 py-2">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-10 w-10 rounded object-cover {{ $roundedClass }}" />
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing') · <span class="text-blue-600">{{ str(__('laracrate::uploader.processing_dots'))->lower() }}</span> @endif
                    @if($state === 'failed') · <span class="text-red-600">{{ str(__('laracrate::uploader.failed'))->lower() }}</span> @endif
                </p>
            </div>
            <button type="button" wire:click="delete"
                wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                class="text-xs text-gray-500 hover:text-red-600 underline">{{ str(__('laracrate::uploader.delete_short'))->lower() }}</button>
        </div>
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-blue-500 bg-blue-50/50' : 'border-gray-300 hover:border-gray-500'"
            class="flex items-center justify-between border-l-2 pl-3 py-3 cursor-pointer transition-colors">
            <div>
                <p class="text-sm text-gray-700">{{ __('laracrate::uploader.drag_or_click') }}</p>
                <p class="text-xs text-gray-400">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            </div>
            <span class="text-xs text-gray-500 underline">{{ str(__('laracrate::uploader.choose'))->lower() }}</span>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        <div wire:loading wire:target="upload" class="mt-1 text-xs text-blue-600">{{ str(__('laracrate::uploader.uploading'))->lower() }}</div>
        @error('upload') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
