<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            class="rounded-2xl bg-white/30 backdrop-blur-2xl border border-white/40 shadow-[0_8px_32px_rgba(31,38,135,0.15)] overflow-hidden">
            <div class="aspect-square bg-white/20 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-white/70">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
                <div x-show="over" x-cloak class="absolute inset-0 bg-purple-500/30 backdrop-blur-md flex items-center justify-center">
                    <p class="text-sm font-semibold text-white drop-shadow">Suelta para reemplazar</p>
                </div>
            </div>
            <div class="p-3">
                <p class="text-sm font-semibold text-gray-900 truncate drop-shadow-sm">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-700/80">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <p class="text-xs text-purple-700">Procesando</p>
                @elseif($state === 'failed')
                    <p class="text-xs text-red-600">Error</p>
                @endif
                <div class="mt-3 flex gap-2">
                    <button type="button" @click="$refs.input.click()"
                        class="flex-1 inline-flex items-center justify-center h-10 rounded-xl bg-white/40 backdrop-blur-md border border-white/60 text-gray-900 text-sm font-semibold hover:bg-white/60 transition-colors">
                        Reemplazar
                    </button>
                    <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                        class="rounded-xl bg-white/30 backdrop-blur-md border border-white/50 hover:bg-red-100/60 hover:text-red-700 text-gray-700 w-10 h-10 inline-flex items-center justify-center transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    </button>
                </div>
            </div>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'bg-white/50 border-white/70' : 'bg-white/20 border-white/30 hover:bg-white/35'"
            class="aspect-square flex flex-col items-center justify-center rounded-2xl p-6 text-center cursor-pointer backdrop-blur-2xl border-2 border-dashed shadow-[0_8px_32px_rgba(31,38,135,0.10)] transition-all">
            <div class="rounded-2xl bg-white/40 backdrop-blur-md border border-white/50 p-3 mb-3">
                <svg class="w-7 h-7 text-purple-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </div>
            <p class="text-sm font-semibold text-gray-900 drop-shadow-sm">Arrastra o haz clic</p>
            <p class="mt-1 text-xs text-gray-700/80">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="mt-2 text-xs text-purple-700">Subiendo...</div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
