<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div class="flex items-center gap-4 rounded-md bg-white p-3 shadow-md ring-1 ring-black/5">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-16 w-16 rounded object-cover {{ $roundedClass }}" />
            @else
                <div class="h-16 w-16 rounded bg-indigo-100 flex items-center justify-center text-indigo-600">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <div class="mt-1 h-0.5 w-full bg-indigo-100 overflow-hidden rounded">
                        <div class="h-full w-1/3 bg-indigo-600 animate-pulse"></div>
                    </div>
                @elseif($state === 'failed')
                    <p class="mt-0.5 text-xs text-red-600">{{ __('laracrate::uploader.failed_long') }}</p>
                @endif
            </div>
            <button type="button" wire:click="delete"
                wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                class="rounded-full p-2 text-gray-500 hover:bg-red-50 hover:text-red-600 transition-colors">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3zm0 5h2v9H9zm4 0h2v9h-2z"/></svg>
            </button>
        </div>
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'bg-indigo-50 ring-indigo-500' : 'bg-white ring-gray-200 hover:ring-indigo-300'"
            class="flex flex-col items-center justify-center rounded-md p-8 text-center cursor-pointer ring-1 shadow-sm transition-all">
            <div class="rounded-full bg-indigo-50 p-3 mb-3">
                <svg class="w-7 h-7 text-indigo-600" viewBox="0 0 24 24" fill="currentColor"><path d="M19 13v6H5v-6H3v8h18v-8zm-7-1.6L7.4 7l-1.4 1.4L13 15.4 20 8.4 18.6 7z" transform="rotate(180 12 12)"/><path d="M11 3h2v12h-2z"/></svg>
            </div>
            <p class="text-sm font-medium text-gray-900">{{ __('laracrate::uploader.drag_or_click_long') }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="mt-3 text-xs text-indigo-600 font-medium">{{ __('laracrate::uploader.uploading') }}</div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
