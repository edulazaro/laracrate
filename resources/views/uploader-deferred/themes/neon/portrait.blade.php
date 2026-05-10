<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="rounded-md bg-neutral-950 border border-cyan-400 shadow-[0_0_20px_rgba(34,211,238,0.45)] overflow-hidden">
            <div class="aspect-square bg-neutral-900 relative">
                @if($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-cyan-300">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3 border-t border-cyan-400/40">
                <p class="text-[10px] font-mono uppercase tracking-[0.25em] text-cyan-300">Pendiente</p>
                <p class="text-sm font-semibold text-white truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-cyan-300">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="flex-1 inline-flex items-center justify-center h-10 rounded border border-cyan-400 bg-cyan-500/20 text-cyan-300 text-xs font-bold uppercase tracking-[0.15em] hover:bg-cyan-500/30 hover:shadow-[0_0_12px_rgba(34,211,238,0.5)] transition-all disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">Subir</span>
                        <span wire:loading wire:target="submit">Subiendo...</span>
                    </button>
                    <button type="button" wire:click="cancel"
                        class="rounded border border-fuchsia-500/40 bg-neutral-900 hover:bg-neutral-800 text-fuchsia-300 w-10 h-10 inline-flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-400 font-mono">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="rounded-md bg-neutral-950 border border-fuchsia-500/60 shadow-[0_0_20px_rgba(217,70,239,0.35)] overflow-hidden">
            <div class="aspect-square bg-neutral-900 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-fuchsia-400">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3 border-t border-fuchsia-500/40">
                <p class="text-[10px] font-mono uppercase tracking-[0.25em] text-fuchsia-400">Archivo</p>
                <p class="text-sm font-semibold text-white truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-cyan-300">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300 inline-flex items-center gap-1">
                        <span class="inline-block w-1.5 h-1.5 bg-cyan-300 animate-pulse rounded-full shadow-[0_0_8px_rgba(103,232,249,0.8)]"></span>Procesando
                    </p>
                @elseif($state === 'failed')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-red-400">Error</p>
                @endif
                <div class="mt-3">
                    <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                        class="w-full inline-flex items-center justify-center h-10 rounded border border-fuchsia-500/60 bg-neutral-900 text-fuchsia-300 text-xs font-bold uppercase tracking-[0.15em] hover:bg-red-950 hover:border-red-500 hover:text-red-400 transition-colors">
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-cyan-400 shadow-[0_0_30px_rgba(34,211,238,0.45)]' : 'border-fuchsia-500/60 shadow-[0_0_20px_rgba(217,70,239,0.30)] hover:border-fuchsia-400'"
            class="aspect-square flex flex-col items-center justify-center rounded-md p-6 text-center cursor-pointer bg-neutral-950 border-2 border-dashed transition-all">
            <svg class="w-10 h-10 text-fuchsia-400 mb-3 drop-shadow-[0_0_8px_rgba(217,70,239,0.6)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            <p class="text-sm font-bold text-white">Selecciona un archivo</p>
            <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-[10px] font-mono uppercase tracking-[0.2em] text-cyan-300">Preparando...</div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-400 font-mono">{{ $message }}</p> @enderror
    @endif
</div>
