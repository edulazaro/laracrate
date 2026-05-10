<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="flex items-center gap-4 rounded-lg border-2 border-blue-300 bg-blue-50/40 p-3">
            @if($pendingPreviewUrl)
                <img src="{{ $pendingPreviewUrl }}" alt="" class="h-16 w-16 rounded-md object-cover border border-gray-200 shrink-0" />
            @else
                <div class="h-16 w-16 rounded-md bg-gray-100 flex items-center justify-center text-gray-400 shrink-0">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-xs text-blue-700">Pendiente de subir</p>
                <p class="text-sm font-medium text-gray-900 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-gray-500">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
            </div>
            <button type="button" wire:click="cancel" title="Cancelar"
                class="rounded-md border border-gray-200 text-gray-500 hover:bg-gray-50 w-9 h-9 inline-flex items-center justify-center transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
            </button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                class="inline-flex items-center gap-1.5 px-3 h-9 rounded-md bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="submit">Subir</span>
                <span wire:loading wire:target="submit">Subiendo...</span>
            </button>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="flex items-center gap-4 rounded-lg border border-gray-200 bg-white p-3">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-16 w-16 rounded-md object-cover border border-gray-200" />
            @else
                <div class="h-16 w-16 rounded-md bg-gray-100 flex items-center justify-center text-gray-400">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="mt-0.5 text-xs text-gray-400 inline-flex items-center gap-1">
                        <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10"/></svg>
                        Procesando...
                    </p>
                @elseif($state === 'failed')
                    <p class="mt-0.5 text-xs text-red-600">Error al procesar</p>
                @endif
            </div>
            <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                class="text-gray-400 hover:text-red-600 transition-colors">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-blue-500 bg-blue-50' : 'border-gray-300 bg-white hover:border-gray-400'"
            class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 text-center cursor-pointer transition-colors">
            <svg class="w-8 h-8 text-gray-400 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            <p class="text-sm text-gray-700 font-medium">Selecciona un archivo</p>
            <p class="mt-0.5 text-xs text-gray-500">Arrastra o haz clic · máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-xs text-gray-500">Preparando...</div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
