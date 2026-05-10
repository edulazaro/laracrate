<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
    style="font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui;"
>
    @if($state === 'staged')
        <div class="flex items-center gap-3 rounded-2xl bg-blue-50/70 backdrop-blur-xl p-3 ring-1 ring-blue-300/60">
            @if($pendingPreviewUrl)
                <img src="{{ $pendingPreviewUrl }}" alt="" class="h-14 w-14 rounded-xl object-cover {{ $roundedClass }}" />
            @else
                <div class="h-14 w-14 rounded-xl bg-white/60 flex items-center justify-center text-blue-600">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[12px] text-blue-700">{{ __('laracrate::uploader.pending_short') }}</p>
                <p class="text-[15px] font-medium text-gray-900 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-[13px] text-gray-500">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
            </div>
            <button type="button" wire:click="cancel"
                class="rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 w-8 h-8 inline-flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                class="inline-flex items-center px-4 h-9 rounded-full bg-blue-600 text-white text-[14px] font-semibold hover:bg-blue-700 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="submit">{{ __('laracrate::uploader.submit') }}</span>
                <span wire:loading wire:target="submit">{{ __('laracrate::uploader.uploading') }}</span>
            </button>
        </div>
        @error('pending') <p class="mt-2 text-[13px] text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="flex items-center gap-3 rounded-2xl bg-white/70 backdrop-blur-xl p-3 ring-1 ring-black/5 shadow-sm">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-14 w-14 rounded-xl object-cover {{ $roundedClass }}" />
            @else
                <div class="h-14 w-14 rounded-xl bg-gray-100 flex items-center justify-center text-gray-400">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[15px] font-medium text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-[13px] text-gray-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="text-[12px] text-blue-600">{{ __('laracrate::uploader.processing') }}</p>
                @elseif($state === 'failed')
                    <p class="text-[12px] text-red-600">{{ __('laracrate::uploader.failed') }}</p>
                @endif
            </div>
            <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                class="rounded-full bg-gray-100 hover:bg-red-100 hover:text-red-600 text-gray-500 w-8 h-8 inline-flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'bg-blue-50/80 ring-blue-400' : 'bg-white/60 ring-black/5 hover:bg-white/80'"
            class="flex flex-col items-center justify-center rounded-2xl p-7 text-center cursor-pointer backdrop-blur-xl ring-1 transition-all">
            <div class="rounded-2xl bg-blue-100/60 p-3 mb-2">
                <svg class="w-6 h-6 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v12m0-12 4 4m-4-4-4 4M4 18h16"/></svg>
            </div>
            <p class="text-[15px] font-medium text-gray-900">{{ __('laracrate::uploader.select') }}</p>
            <p class="mt-0.5 text-[13px] text-gray-500">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-[13px] text-blue-600">{{ __('laracrate::uploader.preparing') }}</div>
        </div>
        @error('pending') <p class="mt-2 text-[13px] text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
