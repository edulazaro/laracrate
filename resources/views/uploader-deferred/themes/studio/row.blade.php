<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="flex items-center gap-4 rounded-sm border border-gray-300 bg-white p-3">
            @if($pendingPreviewUrl)
                <img src="{{ $pendingPreviewUrl }}" alt="" class="h-14 w-14 rounded-sm object-cover border border-gray-200 shrink-0 {{ $roundedClass }}" />
            @else
                <div class="h-14 w-14 rounded-sm border border-gray-200 bg-gray-50 flex items-center justify-center text-gray-400 shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-wide text-gray-400">{{ __('laracrate::uploader.pending') }}</p>
                <p class="text-sm font-medium text-gray-900 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-[11px] font-mono text-gray-500 tabular-nums">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
            </div>
            <button type="button" wire:click="cancel" title="{{ __('laracrate::uploader.cancel') }}"
                class="inline-flex items-center justify-center w-8 h-8 rounded-sm border border-gray-200 text-gray-500 hover:border-gray-900 hover:text-gray-900 transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
            </button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                class="inline-flex items-center gap-1.5 px-3 h-8 rounded-sm bg-gray-900 text-white text-xs font-medium hover:bg-gray-800 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="submit">{{ __('laracrate::uploader.submit') }}</span>
                <span wire:loading wire:target="submit" class="inline-flex items-center gap-1">
                    <span class="inline-block w-1 h-1 bg-white rounded-full animate-pulse"></span>
                    {{ __('laracrate::uploader.uploading') }}
                </span>
            </button>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="flex items-center gap-4 rounded-sm border border-gray-200 bg-white p-3">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-14 w-14 rounded-sm object-cover border border-gray-200 shrink-0 {{ $roundedClass }}" />
            @else
                <div class="h-14 w-14 rounded-sm border border-gray-200 bg-gray-50 flex items-center justify-center text-gray-400 shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
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
            </div>
            <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}" title="{{ __('laracrate::uploader.delete_short') }}"
                class="inline-flex items-center justify-center w-8 h-8 rounded-sm border border-gray-200 text-gray-500 hover:border-red-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-gray-900 bg-gray-50' : 'border-gray-300 bg-white hover:border-gray-500'"
            class="flex items-center justify-between rounded-sm border border-dashed px-4 py-4 cursor-pointer transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <div class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-sm border border-gray-300 bg-white text-gray-700">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm text-gray-900 font-medium">{{ __('laracrate::uploader.select') }}</p>
                    <p class="text-[11px] font-mono text-gray-500 tabular-nums">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }} · {{ __('laracrate::uploader.max_size', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
                </div>
            </div>
            <span class="shrink-0 inline-flex items-center px-3 h-8 rounded-sm border border-gray-300 bg-white text-gray-900 text-xs font-medium hover:border-gray-900 transition-colors">
                {{ __('laracrate::uploader.choose') }}
            </span>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        <div wire:loading wire:target="pending" class="mt-2 text-[11px] font-mono text-gray-500 inline-flex items-center gap-1">
            <span class="inline-block w-1 h-1 bg-gray-700 rounded-full animate-pulse"></span>{{ str(__('laracrate::uploader.preparing'))->lower() }}
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
