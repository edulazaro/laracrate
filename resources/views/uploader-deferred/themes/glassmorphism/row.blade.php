<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="flex items-center gap-4 rounded-2xl bg-purple-100/40 backdrop-blur-2xl p-4 border border-purple-300/60 shadow-[0_8px_32px_rgba(127,86,217,0.18)]">
            @if($pendingPreviewUrl)
                <img src="{{ $pendingPreviewUrl }}" alt="" class="h-16 w-16 rounded-xl object-cover border border-white/50" />
            @else
                <div class="h-16 w-16 rounded-xl bg-white/40 backdrop-blur-md border border-white/50 flex items-center justify-center text-purple-700">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-xs text-purple-700/90">Pendiente</p>
                <p class="text-sm font-semibold text-gray-900 truncate drop-shadow-sm">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-gray-700/80">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
            </div>
            <button type="button" wire:click="cancel"
                class="rounded-xl bg-white/30 backdrop-blur-md border border-white/50 hover:bg-white/50 text-gray-700 w-9 h-9 inline-flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
            </button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                class="inline-flex items-center px-4 h-9 rounded-xl bg-purple-600/90 backdrop-blur-md border border-purple-400/60 text-white text-sm font-semibold hover:bg-purple-700 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="submit">Subir</span>
                <span wire:loading wire:target="submit">Subiendo...</span>
            </button>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="flex items-center gap-4 rounded-2xl bg-white/30 backdrop-blur-2xl p-4 border border-white/40 shadow-[0_8px_32px_rgba(31,38,135,0.15)]">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-16 w-16 rounded-xl object-cover border border-white/50" />
            @else
                <div class="h-16 w-16 rounded-xl bg-white/40 backdrop-blur-md border border-white/50 flex items-center justify-center text-white/80">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-900 truncate drop-shadow-sm">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-700/80">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="mt-0.5 text-xs text-purple-700">Procesando</p>
                @elseif($state === 'failed')
                    <p class="mt-0.5 text-xs text-red-600">Error</p>
                @endif
            </div>
            <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                class="rounded-xl bg-white/30 backdrop-blur-md border border-white/50 hover:bg-red-100/60 hover:text-red-700 text-gray-700 w-9 h-9 inline-flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'bg-white/50 border-white/70' : 'bg-white/20 border-white/30 hover:bg-white/35'"
            class="flex flex-col items-center justify-center rounded-2xl p-8 text-center cursor-pointer backdrop-blur-2xl border-2 border-dashed shadow-[0_8px_32px_rgba(31,38,135,0.10)] transition-all">
            <div class="rounded-2xl bg-white/40 backdrop-blur-md border border-white/50 p-3 mb-3">
                <svg class="w-7 h-7 text-purple-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 4v12m0-12 4 4m-4-4-4 4M4 18h16"/></svg>
            </div>
            <p class="text-sm font-semibold text-gray-900 drop-shadow-sm">Selecciona un archivo</p>
            <p class="mt-1 text-xs text-gray-700/80">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-xs text-purple-700">Preparando...</div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
