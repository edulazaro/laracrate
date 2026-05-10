<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($state === 'staged')
        <div class="flex items-center gap-3 border-l-2 border-gray-900 bg-white pl-3 py-2">
            @if($pendingPreviewUrl)
                <img src="{{ $pendingPreviewUrl }}" alt="" class="h-10 w-10 rounded object-cover" />
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-xs text-gray-500">pendiente</p>
                <p class="text-sm text-gray-900 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-gray-500">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
            </div>
            <button type="button" wire:click="cancel" class="text-xs text-gray-500 hover:text-gray-900 underline">cancelar</button>
            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                class="text-xs text-blue-600 hover:underline disabled:opacity-60">
                <span wire:loading.remove wire:target="submit">subir</span>
                <span wire:loading wire:target="submit">subiendo...</span>
            </button>
        </div>
        @error('pending') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="flex items-center gap-3 border-l-2 border-blue-500 bg-white pl-3 py-2">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-10 w-10 rounded object-cover" />
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing') · <span class="text-blue-600">procesando...</span> @endif
                    @if($state === 'failed') · <span class="text-red-600">error</span> @endif
                </p>
            </div>
            <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                class="text-xs text-gray-500 hover:text-red-600 underline">borrar</button>
        </div>

    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-blue-500 bg-blue-50/50' : 'border-gray-300 hover:border-gray-500'"
            class="flex items-center justify-between border-l-2 pl-3 py-3 cursor-pointer transition-colors">
            <div>
                <p class="text-sm text-gray-700">Selecciona un archivo</p>
                <p class="text-xs text-gray-400">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            </div>
            <span class="text-xs text-gray-500 underline">elegir</span>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        <div wire:loading wire:target="pending" class="mt-1 text-xs text-blue-600">preparando...</div>
        @error('pending') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
