<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div>
            <div class="aspect-square bg-gray-50 relative">
                @if($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover {{ $roundedClass }}" />
                @endif
            </div>
            <div class="border-l-2 border-gray-900 bg-white pl-3 py-2 mt-2">
                <p class="text-xs text-gray-500">pendiente</p>
                <p class="text-sm text-gray-900 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-gray-500">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
                <div class="mt-2 flex gap-3 text-xs">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="text-blue-600 hover:underline disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">{{ str(__('laracrate::uploader.submit'))->lower() }}</span>
                        <span wire:loading wire:target="submit">{{ str(__('laracrate::uploader.uploading'))->lower() }}</span>
                    </button>
                    <button type="button" wire:click="cancel" class="text-gray-500 hover:text-gray-900 hover:underline">{{ str(__('laracrate::uploader.cancel'))->lower() }}</button>
                </div>
            </div>
        </div>
        @error('pending') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div>
            <div class="aspect-square bg-gray-50 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover {{ $roundedClass }}" />
                @endif
            </div>
            <div class="border-l-2 border-blue-500 bg-white pl-3 py-2 mt-2">
                <p class="text-sm text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing') · <span class="text-blue-600">{{ str(__('laracrate::uploader.processing'))->lower() }}</span> @endif
                    @if($state === 'failed') · <span class="text-red-600">{{ str(__('laracrate::uploader.failed'))->lower() }}</span> @endif
                </p>
                <div class="mt-2">
                    <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" class="text-xs text-gray-500 hover:text-red-600 hover:underline">{{ str(__('laracrate::uploader.delete_short'))->lower() }}</button>
                </div>
            </div>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-blue-500 bg-blue-50/50' : 'border-gray-300 hover:border-gray-500'"
            class="aspect-square flex flex-col items-center justify-center border-l-2 cursor-pointer transition-colors">
            <p class="text-sm text-gray-700">{{ __('laracrate::uploader.select') }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        <div wire:loading wire:target="pending" class="mt-1 text-xs text-blue-600">{{ str(__('laracrate::uploader.preparing'))->lower() }}</div>
        @error('pending') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
