<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            class="rounded-md bg-neutral-950 border border-fuchsia-500/60 shadow-[0_0_20px_rgba(217,70,239,0.35)] overflow-hidden">
            <div class="aspect-square bg-neutral-900 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover {{ $roundedClass }}" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-fuchsia-400">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
                <div x-show="over" x-cloak class="absolute inset-0 bg-cyan-500/40 flex items-center justify-center">
                    <p class="text-xs font-mono uppercase tracking-[0.25em] font-bold text-white drop-shadow-[0_0_8px_rgba(34,211,238,0.8)]">{{ __('laracrate::uploader.drop_to_replace') }}</p>
                </div>
            </div>
            <div class="p-3 border-t border-fuchsia-500/40">
                <p class="text-[10px] font-mono uppercase tracking-[0.25em] text-fuchsia-400">{{ __('laracrate::uploader.file_label') }}</p>
                <p class="text-sm font-semibold text-white truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-cyan-300">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300 inline-flex items-center gap-1">
                        <span class="inline-block w-1.5 h-1.5 bg-cyan-300 animate-pulse rounded-full shadow-[0_0_8px_rgba(103,232,249,0.8)]"></span>{{ __('laracrate::uploader.processing') }}
                    </p>
                @elseif($state === 'failed')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-red-400">{{ __('laracrate::uploader.failed') }}</p>
                @endif
                <div class="mt-3 flex gap-2">
                    <button type="button" @click="$refs.input.click()"
                        class="flex-1 inline-flex items-center justify-center h-10 rounded border border-fuchsia-500 bg-fuchsia-600/20 text-fuchsia-300 text-xs font-bold uppercase tracking-[0.15em] hover:bg-fuchsia-500/30 hover:shadow-[0_0_12px_rgba(217,70,239,0.5)] transition-all">
                        {{ __('laracrate::uploader.replace') }}
                    </button>
                    <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                        class="rounded border border-fuchsia-500/40 bg-neutral-900 hover:bg-red-950 hover:border-red-500 text-fuchsia-400 hover:text-red-400 w-10 h-10 inline-flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    </button>
                </div>
            </div>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-400 font-mono">{{ $message }}</p> @enderror
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-cyan-400 shadow-[0_0_30px_rgba(34,211,238,0.45)]' : 'border-fuchsia-500/60 shadow-[0_0_20px_rgba(217,70,239,0.30)] hover:border-fuchsia-400'"
            class="aspect-square flex flex-col items-center justify-center rounded-md p-6 text-center cursor-pointer bg-neutral-950 border-2 border-dashed transition-all">
            <svg class="w-10 h-10 text-fuchsia-400 mb-3 drop-shadow-[0_0_8px_rgba(217,70,239,0.6)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            <p class="text-sm font-bold text-white">{{ __('laracrate::uploader.drag_or_click') }}</p>
            <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="mt-2 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">{{ __('laracrate::uploader.uploading') }}</div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-400 font-mono">{{ $message }}</p> @enderror
    @endif
</div>
