<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
    style="font-family: 'Söhne', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;"
>
    @if($file)
        <div class="flex items-center gap-4 rounded-xl border border-[#E5E5E5] bg-white p-3 shadow-[0_6px_24px_rgba(0,0,0,0.05),0_1px_2px_rgba(0,0,0,0.04)]">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="" class="h-14 w-14 rounded-lg object-cover border border-[#E5E5E5] shrink-0" />
            @else
                <div class="h-14 w-14 rounded-lg border border-[#E5E5E5] bg-[#F7F7F8] flex items-center justify-center text-[#8E8EA0] shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-[15px] font-semibold text-[#0D0D0D] truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-[13px] text-[#5D5D5D]">
                    {{ number_format($file->size / 1024, 0) }} KB
                    @if($state === 'pending' || $state === 'processing')
                        · <span class="text-[#5D5D5D]">procesando</span>
                    @elseif($state === 'failed')
                        · <span class="text-red-600">error</span>
                    @endif
                </p>
            </div>
            <button type="button" wire:click="delete" wire:confirm="¿Borrar este archivo?" title="Borrar"
                class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-[#E5E5E5] text-[#5D5D5D] hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
        </div>
    @else
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            @click="$refs.input.click()" role="button" tabindex="0"
            :class="over ? 'border-[#0D0D0D] bg-[#F7F7F8]' : 'border-[#E5E5E5] bg-white hover:border-[#0D0D0D]/40'"
            class="flex items-center justify-between rounded-xl border px-4 py-4 cursor-pointer transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <div class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg border border-[#E5E5E5] bg-white text-[#0D0D0D]">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[15px] text-[#0D0D0D] font-semibold">Subir archivo</p>
                    <p class="text-[13px] text-[#5D5D5D]">arrastra o haz clic · máx {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
                </div>
            </div>
            <span class="shrink-0 inline-flex items-center px-3 h-9 rounded-lg bg-[#0D0D0D] text-white text-[13px] font-semibold hover:bg-[#1A1A1A] transition-colors">
                Elegir
            </span>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
        </div>
        <div wire:loading wire:target="upload" class="mt-2 text-[13px] text-[#5D5D5D] inline-flex items-center gap-1">
            <span class="inline-block w-1 h-1 bg-[#0D0D0D] rounded-full animate-pulse"></span>subiendo...
        </div>
        @error('upload') <p class="mt-2 text-[13px] text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
