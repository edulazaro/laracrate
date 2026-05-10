<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
    style="--neu-bg: #e0e5ec;"
>
    @if($state === 'staged')
        <div class="rounded-2xl overflow-hidden p-3"
            style="background: var(--neu-bg); box-shadow: inset 6px 6px 12px rgba(163,177,198,0.5), inset -6px -6px 12px rgba(255,255,255,0.85);">
            <div class="aspect-square rounded-xl relative overflow-hidden"
                style="background: var(--neu-bg); box-shadow: inset 4px 4px 8px rgba(163,177,198,0.45), inset -4px -4px 8px rgba(255,255,255,0.7);">
                @if($pendingPreviewUrl)
                    <img src="{{ $pendingPreviewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-gray-500">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
            </div>
            <div class="pt-3">
                <p class="text-xs text-gray-500 italic">Pendiente</p>
                <p class="text-sm font-semibold text-gray-700 truncate">{{ $pending?->getClientOriginalName() }}</p>
                <p class="text-xs text-gray-500">{{ number_format(($pending?->getSize() ?? 0) / 1024, 0) }} KB</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="flex-1 inline-flex items-center justify-center h-10 rounded-xl text-gray-700 text-sm font-semibold transition-all disabled:opacity-60"
                        style="background: var(--neu-bg); box-shadow: 4px 4px 8px rgba(163,177,198,0.5), -4px -4px 8px rgba(255,255,255,0.85);">
                        <span wire:loading.remove wire:target="submit">Subir</span>
                        <span wire:loading wire:target="submit">Subiendo...</span>
                    </button>
                    <button type="button" wire:click="cancel"
                        class="rounded-xl w-10 h-10 inline-flex items-center justify-center text-gray-600 transition-colors"
                        style="background: var(--neu-bg); box-shadow: 4px 4px 8px rgba(163,177,198,0.5), -4px -4px 8px rgba(255,255,255,0.85);">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
                    </button>
                </div>
            </div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

    @elseif($file)
        <div class="rounded-2xl overflow-hidden p-3"
            style="background: var(--neu-bg); box-shadow: 8px 8px 16px rgba(163,177,198,0.6), -8px -8px 16px rgba(255,255,255,0.9);">
            <div class="aspect-square rounded-xl relative overflow-hidden"
                style="background: var(--neu-bg); box-shadow: inset 4px 4px 8px rgba(163,177,198,0.45), inset -4px -4px 8px rgba(255,255,255,0.7);">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-gray-500">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
            </div>
            <div class="pt-3">
                <p class="text-sm font-semibold text-gray-700 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="text-xs text-gray-500">Procesando...</p>
                @elseif($state === 'failed')
                    <p class="text-xs text-red-600">Error</p>
                @endif
                <div class="mt-3">
                    <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                        class="w-full inline-flex items-center justify-center h-10 rounded-xl text-gray-700 text-sm font-semibold transition-all"
                        style="background: var(--neu-bg); box-shadow: 4px 4px 8px rgba(163,177,198,0.5), -4px -4px 8px rgba(255,255,255,0.85);">
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
            class="aspect-square flex flex-col items-center justify-center rounded-2xl p-6 text-center cursor-pointer transition-all"
            :style="over
                ? 'background: var(--neu-bg); box-shadow: inset 6px 6px 12px rgba(163,177,198,0.5), inset -6px -6px 12px rgba(255,255,255,0.85);'
                : 'background: var(--neu-bg); box-shadow: 8px 8px 16px rgba(163,177,198,0.6), -8px -8px 16px rgba(255,255,255,0.9);'">
            <div class="rounded-2xl w-14 h-14 flex items-center justify-center text-gray-600 mb-3"
                style="background: var(--neu-bg); box-shadow: inset 4px 4px 8px rgba(163,177,198,0.45), inset -4px -4px 8px rgba(255,255,255,0.7);">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </div>
            <p class="text-sm font-medium text-gray-700">Selecciona un archivo</p>
            <p class="mt-1 text-xs text-gray-500">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="pending" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="pending" class="mt-2 text-xs text-gray-600">Preparando...</div>
        </div>
        @error('pending') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
