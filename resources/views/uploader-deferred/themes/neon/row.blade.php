<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="flex items-center gap-4 rounded-md bg-neutral-950 p-4 border border-cyan-400 shadow-[0_0_20px_rgba(34,211,238,0.45)]">
            @if($pendingPreviewUrl)
                <img src="{{ $pendingPreviewUrl }}" alt="" class="h-16 w-16 rounded object-cover border border-cyan-400/40 {{ $roundedClass }}" />
            @else
                <div class="h-16 w-16 rounded bg-neutral-900 border border-cyan-400/40 flex items-center justify-center text-cyan-300">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-[0.25em] text-cyan-300">{{ __('laracrate::uploader.pending_short') }}</p>
                <p class="text-sm font-semibold text-white truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-cyan-300">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
            </div>
            <button type="button" wire:click="cancel"
                class="rounded border border-fuchsia-500/40 bg-neutral-900 hover:bg-neutral-800 text-fuchsia-300 w-9 h-9 inline-flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
            </button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                class="inline-flex items-center px-4 h-9 rounded border border-cyan-400 bg-cyan-500/20 text-cyan-300 text-xs font-bold uppercase tracking-[0.15em] hover:bg-cyan-500/30 hover:shadow-[0_0_12px_rgba(34,211,238,0.5)] transition-all disabled:opacity-60">
                <span wire:loading.remove wire:target="submit">{{ __('laracrate::uploader.submit') }}</span>
                <span wire:loading wire:target="submit">{{ __('laracrate::uploader.uploading') }}</span>
            </button>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-400 font-mono">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="flex items-center gap-4 rounded-md bg-neutral-950 p-4 border border-fuchsia-500/60 shadow-[0_0_20px_rgba(217,70,239,0.35)]">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-16 w-16 rounded object-cover border border-fuchsia-500/40 {{ $roundedClass }}" />
            @else
                <div class="h-16 w-16 rounded bg-neutral-900 border border-fuchsia-500/40 flex items-center justify-center text-fuchsia-400">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
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
            </div>
            <button type="button" wire:click="delete" wire:confirm="{{ __('laracrate::uploader.delete_confirm') }}"
                class="rounded border border-fuchsia-500/40 bg-neutral-900 hover:bg-red-950 hover:border-red-500 text-fuchsia-400 hover:text-red-400 w-9 h-9 inline-flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-cyan-400 shadow-[0_0_30px_rgba(34,211,238,0.45)]' : 'border-fuchsia-500/60 shadow-[0_0_20px_rgba(217,70,239,0.30)] hover:border-fuchsia-400 hover:shadow-[0_0_25px_rgba(217,70,239,0.45)]'"
            class="flex flex-col items-center justify-center rounded-md p-7 text-center cursor-pointer bg-neutral-950 border-2 border-dashed transition-all">
            <svg class="w-8 h-8 text-fuchsia-400 mb-2 drop-shadow-[0_0_8px_rgba(217,70,239,0.6)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 4v12m0-12 4 4m-4-4-4 4M4 18h16"/></svg>
            <p class="text-sm font-bold text-white">{{ __('laracrate::uploader.select') }}</p>
            <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">{{ __('laracrate::uploader.preparing') }}</div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-400 font-mono">{{ $message }}</p> @enderror
    @endif
</div>
