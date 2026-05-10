<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
    style="font-family: 'Söhne', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;"
>
    @if($state === 'staged')
        <div class="rounded-xl border border-[#0D0D0D]/30 bg-[#F7F7F8] shadow-[0_6px_24px_rgba(0,0,0,0.05),0_1px_2px_rgba(0,0,0,0.04)] overflow-hidden">
            <div class="aspect-square bg-white border-b border-[#E5E5E5] relative">
                @if($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover {{ $roundedClass }}" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-[#8E8EA0]">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3">
                <p class="text-[12px] text-[#5D5D5D]">{{ __('laracrate::uploader.pending') }}</p>
                <p class="text-[15px] font-semibold text-[#0D0D0D] truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-[13px] text-[#5D5D5D]">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="flex-1 inline-flex items-center justify-center h-10 rounded-lg bg-[#0D0D0D] text-white text-[14px] font-semibold hover:bg-[#1A1A1A] transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">{{ __('laracrate::uploader.submit') }}</span>
                        <span wire:loading wire:target="submit">{{ __('laracrate::uploader.uploading') }}</span>
                    </button>
                    <button type="button" wire:click="cancel" title="{{ __('laracrate::uploader.cancel') }}"
                        class="inline-flex items-center justify-center w-10 h-10 rounded-lg border border-[#E5E5E5] text-[#5D5D5D] hover:bg-white transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        @error('pending') <p class="mt-2 text-[13px] text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="rounded-xl border border-[#E5E5E5] bg-white shadow-[0_6px_24px_rgba(0,0,0,0.05),0_1px_2px_rgba(0,0,0,0.04)] overflow-hidden">
            <div class="aspect-square bg-[#F7F7F8] border-b border-[#E5E5E5] relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover {{ $roundedClass }}" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-[#8E8EA0]">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3">
                <p class="text-[15px] font-semibold text-[#0D0D0D] truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-[13px] text-[#5D5D5D]">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing')
                        · <span>{{ str(__('laracrate::uploader.processing'))->lower() }}</span>
                    @elseif($state === 'failed')
                        · <span class="text-red-600">{{ str(__('laracrate::uploader.failed'))->lower() }}</span>
                    @endif
                </p>
                <div class="mt-3">
                    <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                        class="w-full inline-flex items-center justify-center h-10 rounded-lg border border-[#E5E5E5] text-[#0D0D0D] text-[14px] font-semibold hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-colors">
                        {{ __('laracrate::uploader.delete') }}
                    </button>
                </div>
            </div>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-[#0D0D0D] bg-[#F7F7F8]' : 'border-[#E5E5E5] bg-white hover:border-[#0D0D0D]/40'"
            class="aspect-square flex flex-col items-center justify-center rounded-xl border p-6 text-center cursor-pointer transition-colors">
            <div class="inline-flex items-center justify-center w-11 h-11 rounded-lg border border-[#E5E5E5] bg-white text-[#0D0D0D] mb-3">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </div>
            <p class="text-[15px] text-[#0D0D0D] font-semibold">{{ __('laracrate::uploader.select') }}</p>
            <p class="mt-1 text-[13px] text-[#5D5D5D]">arrastra o haz clic</p>
            <p class="mt-3 text-[12px] text-[#8E8EA0]">{{ __('laracrate::uploader.max_size', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-[13px] text-[#5D5D5D] inline-flex items-center gap-1">
                <span class="inline-block w-1 h-1 bg-[#0D0D0D] rounded-full animate-pulse"></span>preparando...
            </div>
        </div>
        @error('pending') <p class="mt-2 text-[13px] text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
