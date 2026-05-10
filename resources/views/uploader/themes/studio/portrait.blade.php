<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            class="rounded-sm border border-gray-200 bg-white overflow-hidden">
            <div class="aspect-square bg-gray-50 border-b border-gray-200 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover {{ $roundedClass }}" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-gray-300">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                @endif
                <div x-show="over" x-cloak class="absolute inset-0 bg-gray-900/70 flex items-center justify-center">
                    <p class="text-[10px] font-mono uppercase tracking-widest text-white">{{ str(__('laracrate::uploader.drop_to_replace'))->lower() }}</p>
                </div>
            </div>

            <div class="p-3">
                <p class="text-[10px] font-mono uppercase tracking-wide text-gray-400">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="text-sm font-medium text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-[11px] font-mono text-gray-500 tabular-nums">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing')
                        · <span class="inline-flex items-center gap-1 text-gray-700"><span class="inline-block w-1 h-1 bg-gray-700 rounded-full animate-pulse"></span>{{ str(__('laracrate::uploader.processing'))->lower() }}</span>
                    @elseif($state === 'failed')
                        · <span class="text-red-600">{{ str(__('laracrate::uploader.failed'))->lower() }}</span>
                    @endif
                </p>

                <div class="mt-3 flex gap-2">
                    <button type="button" @click="$refs.input.click()"
                        class="flex-1 inline-flex items-center justify-center h-9 rounded-sm bg-gray-900 text-white text-sm font-medium hover:bg-gray-800 transition-colors">
                        {{ __('laracrate::uploader.replace') }}
                    </button>
                    <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                        title="{{ __('laracrate::uploader.delete_short') }}"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-sm border border-gray-200 text-gray-500 hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    </button>
                </div>
            </div>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-gray-900 bg-gray-50' : 'border-gray-300 bg-white hover:border-gray-500'"
            class="aspect-square flex flex-col items-center justify-center rounded-sm border border-dashed p-6 text-center cursor-pointer transition-colors">
            <div class="inline-flex items-center justify-center w-10 h-10 rounded-sm border border-gray-300 bg-white text-gray-700 mb-3">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </div>
            <p class="text-sm text-gray-900 font-medium">{{ __('laracrate::uploader.upload') }}</p>
            <p class="mt-1 text-[11px] font-mono text-gray-500 tabular-nums">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="mt-3 text-[10px] font-mono uppercase tracking-wide text-gray-400">{{ __('laracrate::uploader.max_size', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="mt-2 text-[11px] font-mono text-gray-500 inline-flex items-center gap-1">
                <span class="inline-block w-1 h-1 bg-gray-700 rounded-full animate-pulse"></span>{{ str(__('laracrate::uploader.uploading'))->lower() }}
            </div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
