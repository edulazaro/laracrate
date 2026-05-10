<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="rounded-[4px] border border-neutral-900 bg-yellow-100 shadow-[4px_4px_0_0_rgba(10,10,10,1)] overflow-hidden">
            <div class="aspect-square bg-yellow-200 border-b border-neutral-900 relative">
                @if($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center">
                        <svg class="w-12 h-12 text-neutral-900" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3">
                <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-900 font-bold">Pendiente</p>
                <p class="text-sm font-semibold text-neutral-950 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-neutral-700">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="flex-1 inline-flex items-center justify-center h-9 rounded-[4px] border border-neutral-900 bg-neutral-950 text-white text-xs font-bold uppercase tracking-[0.15em] hover:bg-neutral-800 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">Subir</span>
                        <span wire:loading wire:target="submit">Subiendo...</span>
                    </button>
                    <button type="button" wire:click="cancel"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-[4px] border border-neutral-900 bg-white text-neutral-700 hover:bg-neutral-100 transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600 font-mono">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="rounded-[4px] border border-neutral-900 bg-white shadow-[4px_4px_0_0_rgba(10,10,10,1)] overflow-hidden">
            <div class="aspect-square bg-neutral-100 border-b border-neutral-900 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center bg-yellow-200">
                        <svg class="w-12 h-12 text-neutral-900" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3">
                <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-500">Archivo</p>
                <p class="text-sm font-semibold text-neutral-950 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-neutral-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700 inline-flex items-center gap-1">
                        <span class="inline-block w-1.5 h-1.5 bg-neutral-900 animate-pulse"></span>Procesando
                    </p>
                @elseif($state === 'failed')
                    <p class="mt-1 text-[10px] font-mono uppercase tracking-[0.2em] text-red-700">Error</p>
                @endif
                <div class="mt-3">
                    <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                        class="w-full inline-flex items-center justify-center h-9 rounded-[4px] border border-neutral-900 bg-white text-neutral-900 text-xs font-bold uppercase tracking-[0.15em] hover:bg-red-50 hover:text-red-700 transition-colors">
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
            :class="over ? 'border-neutral-900 bg-yellow-200 -translate-x-0.5 -translate-y-0.5 shadow-[6px_6px_0_0_rgba(10,10,10,1)]' : 'border-neutral-900 bg-white shadow-[4px_4px_0_0_rgba(10,10,10,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:shadow-[6px_6px_0_0_rgba(10,10,10,1)]'"
            class="aspect-square flex flex-col items-center justify-center rounded-[4px] border-2 border-dashed p-6 text-center cursor-pointer transition-all duration-150">
            <svg class="w-10 h-10 text-neutral-900 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            <p class="text-[11px] font-mono font-semibold uppercase tracking-[0.25em] text-neutral-500">Subir</p>
            <p class="mt-1 text-sm text-neutral-950 font-bold">Selecciona</p>
            <p class="mt-1 text-xs text-neutral-500 font-mono">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700">Preparando...</div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600 font-mono">{{ $message }}</p> @enderror
    @endif
</div>
