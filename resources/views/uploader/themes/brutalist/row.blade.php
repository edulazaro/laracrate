<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            :class="over ? 'bg-yellow-200 -translate-x-0.5 -translate-y-0.5 shadow-[6px_6px_0_0_rgba(10,10,10,1)]' : 'bg-white shadow-[4px_4px_0_0_rgba(10,10,10,1)]'"
            class="flex items-center gap-4 rounded-[4px] border border-neutral-900 p-4 transition-all duration-150">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-16 w-16 rounded-[3px] border border-neutral-900 object-cover shrink-0 {{ $roundedClass }}" />
            @else
                <div class="h-16 w-16 rounded-[3px] border border-neutral-900 bg-yellow-200 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-neutral-900" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-500">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="text-sm font-semibold text-neutral-950 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-neutral-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700 inline-flex items-center gap-1">
                        <span class="inline-block w-1.5 h-1.5 bg-neutral-900 animate-pulse"></span>
                        {{ __('laracrate::uploader.processing') }}
                    </p>
                @elseif($state === 'failed')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-red-700">{{ __('laracrate::uploader.failed') }}</p>
                @endif
                <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-400" x-show="!over">{{ __('laracrate::uploader.drop_to_replace') }}</p>
                <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-900 font-bold" x-show="over" x-cloak>{{ __('laracrate::uploader.drop_to_replace') }}</p>
            </div>
            <div class="flex flex-col gap-2 shrink-0">
                <button type="button" @click="$refs.input.click()"
                    title="{{ __('laracrate::uploader.replace') }}"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-[4px] border border-neutral-900 bg-neutral-950 text-white hover:bg-neutral-800 transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                </button>
                <button type="button" wire:click="delete"
                    wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                    title="{{ __('laracrate::uploader.delete_short') }}"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-[4px] border border-neutral-900 bg-white text-neutral-700 hover:bg-red-50 hover:text-red-700 transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
            </div>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="absolute -mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700">{{ __('laracrate::uploader.uploading') }}</div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600 font-mono">{{ $message }}</p> @enderror
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-neutral-900 bg-yellow-200 -translate-x-0.5 -translate-y-0.5 shadow-[6px_6px_0_0_rgba(10,10,10,1)]' : 'border-neutral-900 bg-white shadow-[4px_4px_0_0_rgba(10,10,10,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:shadow-[6px_6px_0_0_rgba(10,10,10,1)]'"
            class="flex flex-col items-center justify-center rounded-[4px] border-2 border-dashed p-6 text-center cursor-pointer transition-all duration-150">
            <p class="text-[11px] font-mono font-semibold uppercase tracking-[0.25em] text-neutral-500">{{ __('laracrate::uploader.submit') }}</p>
            <p class="mt-1 text-sm text-neutral-950 font-bold">{{ __('laracrate::uploader.drag_or_click_long') }}</p>
            <p class="mt-1 text-xs text-neutral-500 font-mono">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="mt-2 text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700">{{ __('laracrate::uploader.uploading') }}</div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600 font-mono">{{ $message }}</p> @enderror
    @endif
</div>
