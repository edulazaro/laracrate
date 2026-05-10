<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
    style="font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; letter-spacing: -0.005em;"
>
    @if($file)
        <div class="flex items-center gap-4 rounded-[10px] border border-[#1A1A1A]/[0.08] border-t-2 border-t-[#C15F3C] bg-[#FAF9F5] p-4 shadow-[0_8px_24px_rgba(26,26,26,0.06),0_2px_6px_rgba(26,26,26,0.04)]">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-14 w-14 rounded-md object-cover border border-[#1A1A1A]/[0.08] shrink-0" />
            @else
                <div class="h-14 w-14 rounded-md border border-[#1A1A1A]/[0.08] bg-white flex items-center justify-center text-[#6B6560] shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[15px] font-semibold text-[#1A1A1A] truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-[13px] text-[#6B6560]">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing')
                        · <span>procesando</span>
                    @elseif($state === 'failed')
                        · <span class="text-red-700">error</span>
                    @endif
                </p>
            </div>
            <button type="button" wire:click="delete" wire:confirm="¿Borrar este archivo?" title="Borrar"
                class="inline-flex items-center justify-center w-9 h-9 rounded-md border border-[#1A1A1A]/[0.08] bg-white text-[#6B6560] hover:bg-red-50 hover:text-red-700 hover:border-red-200 transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
        </div>
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-[#C15F3C] bg-[#FAF9F5]' : 'border-[#1A1A1A]/[0.12] bg-[#FAF9F5] hover:border-[#1A1A1A]/30'"
            class="flex items-center justify-between rounded-[10px] border px-4 py-4 cursor-pointer transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <div class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-md border border-[#1A1A1A]/[0.08] bg-white text-[#1A1A1A]">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[15px] text-[#1A1A1A] font-semibold">Subir archivo</p>
                    <p class="text-[13px] text-[#6B6560]">arrastra o haz clic · máx {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
                </div>
            </div>
            <span class="shrink-0 inline-flex items-center px-3 h-9 rounded-md bg-[#C15F3C] text-white text-[13px] font-semibold hover:bg-[#A84D2C] transition-colors">
                Elegir
            </span>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        <div wire:loading wire:target="upload" class="mt-2 text-[13px] text-[#6B6560] inline-flex items-center gap-1">
            <span class="inline-block w-1 h-1 bg-[#C15F3C] rounded-full animate-pulse"></span>subiendo...
        </div>
        @error('upload') <p class="mt-2 text-[13px] text-red-700">{{ $message }}</p> @enderror
    @endif
</div>
