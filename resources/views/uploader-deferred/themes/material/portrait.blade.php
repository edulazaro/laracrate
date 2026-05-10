<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="rounded-md bg-indigo-50 shadow-md ring-1 ring-indigo-200 overflow-hidden">
            <div class="aspect-square bg-indigo-100 relative">
                @if($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-indigo-400">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3">
                <p class="text-[10px] font-medium uppercase tracking-wider text-indigo-700">Pendiente</p>
                <p class="text-sm font-medium text-gray-900 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-gray-500">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="flex-1 inline-flex items-center justify-center h-10 rounded-md bg-indigo-600 text-white text-sm font-bold uppercase tracking-wider shadow-sm hover:bg-indigo-700 hover:shadow-md transition-all disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">Subir</span>
                        <span wire:loading wire:target="submit">Subiendo...</span>
                    </button>
                    <button type="button" wire:click="cancel"
                        class="rounded-full p-2.5 text-gray-500 hover:bg-gray-100 transition-colors">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                    </button>
                </div>
            </div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="rounded-md bg-white shadow-md ring-1 ring-black/5 overflow-hidden">
            <div class="aspect-square bg-indigo-50 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-indigo-400">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
            </div>
            <div class="p-3">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <div class="mt-1 h-0.5 w-full bg-indigo-100 overflow-hidden rounded"><div class="h-full w-1/3 bg-indigo-600 animate-pulse"></div></div>
                @elseif($state === 'failed')
                    <p class="mt-0.5 text-xs text-red-600">Error</p>
                @endif
                <div class="mt-3">
                    <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                        class="w-full inline-flex items-center justify-center h-10 rounded-md ring-1 ring-gray-200 bg-white text-gray-700 text-sm font-bold uppercase tracking-wider hover:bg-red-50 hover:text-red-600 hover:ring-red-200 transition-colors">
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
            :class="over ? 'bg-indigo-50 ring-indigo-500' : 'bg-white ring-gray-200 hover:ring-indigo-300'"
            class="aspect-square flex flex-col items-center justify-center rounded-md p-6 text-center cursor-pointer ring-1 shadow-sm transition-all">
            <div class="rounded-full bg-indigo-50 p-3 mb-3">
                <svg class="w-7 h-7 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </div>
            <p class="text-sm font-medium text-gray-900">Selecciona un archivo</p>
            <p class="mt-1 text-xs text-gray-500">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-xs text-indigo-600 font-medium">Preparando...</div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
