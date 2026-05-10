<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div class="flex items-center gap-4 rounded-md bg-neutral-950 p-4 border border-fuchsia-500/60 shadow-[0_0_20px_rgba(217,70,239,0.35)]">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-16 w-16 rounded object-cover border border-fuchsia-500/40" />
            @else
                <div class="h-16 w-16 rounded bg-neutral-900 border border-fuchsia-500/40 flex items-center justify-center text-fuchsia-400">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-[0.25em] text-fuchsia-400">Archivo</p>
                <p class="text-sm font-semibold text-white truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-cyan-300">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300 inline-flex items-center gap-1">
                        <span class="inline-block w-1.5 h-1.5 bg-cyan-300 animate-pulse rounded-full shadow-[0_0_8px_rgba(103,232,249,0.8)]"></span>
                        Procesando
                    </p>
                @elseif($state === 'failed')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-red-400">Error</p>
                @endif
            </div>
            <button type="button" wire:click="delete"
                wire:confirm="Borrar este archivo?"
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
            <p class="text-sm font-bold text-white">Arrastra un archivo o haz clic</p>
            <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="mt-2 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">Subiendo...</div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-400 font-mono">{{ $message }}</p> @enderror
    @endif
</div>
