<div
    @if(in_array($state, ['pending', 'processing'])) wire:poll.{{ $pollMs }}ms="$refresh" @endif
    x-data="{ over: false }"
    class="w-full"
>
    @if($file)
        <div
            @dragover.prevent="over = true" @dragleave.prevent="over = false"
            @drop.prevent="over = false; if ($event.dataTransfer.files.length) { const dt = new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files = dt.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
            class="rounded-md bg-white shadow-md ring-1 ring-black/5 overflow-hidden">
            <div class="aspect-square bg-indigo-50 relative">
                @if($previewUrl)
                    <img src="{{ $previewUrl }}" alt="" class="absolute inset-0 w-full h-full object-cover" />
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-indigo-400">
                        <svg class="w-12 h-12" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                    </div>
                @endif
                <div x-show="over" x-cloak class="absolute inset-0 bg-indigo-600/40 flex items-center justify-center">
                    <p class="text-sm font-medium text-white">Suelta para reemplazar</p>
                </div>
            </div>
            <div class="p-3">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $file->original_name ?: $file->name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($file->size / 1024, 0) }} KB</p>
                @if($state === 'pending' || $state === 'processing')
                    <div class="mt-1 h-0.5 w-full bg-indigo-100 overflow-hidden rounded"><div class="h-full w-1/3 bg-indigo-600 animate-pulse"></div></div>
                @elseif($state === 'failed')
                    <p class="mt-0.5 text-xs text-red-600">Error</p>
                @endif
                <div class="mt-3 flex gap-2">
                    <button type="button" @click="$refs.input.click()"
                        class="flex-1 inline-flex items-center justify-center gap-1.5 h-10 rounded-md bg-indigo-600 text-white text-sm font-bold uppercase tracking-wider shadow-sm hover:bg-indigo-700 hover:shadow-md transition-all">
                        Reemplazar
                    </button>
                    <button type="button" wire:click="delete" wire:confirm="Borrar este archivo?"
                        class="rounded-full p-2.5 text-gray-500 hover:bg-red-50 hover:text-red-600 transition-colors">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3zm0 5h2v9H9zm4 0h2v9h-2z"/></svg>
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
            :class="over ? 'bg-indigo-50 ring-indigo-500' : 'bg-white ring-gray-200 hover:ring-indigo-300'"
            class="aspect-square flex flex-col items-center justify-center rounded-md p-6 text-center cursor-pointer ring-1 shadow-sm transition-all">
            <div class="rounded-full bg-indigo-50 p-3 mb-3">
                <svg class="w-7 h-7 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            </div>
            <p class="text-sm font-medium text-gray-900">Arrastra o haz clic</p>
            <p class="mt-1 text-xs text-gray-500">Máx. {{ number_format($maxSizeKb / 1024, 1) }} MB</p>
            <input type="file" x-ref="input" wire:model="upload" accept="{{ $acceptAttr }}" class="hidden" />
            <div wire:loading wire:target="upload" class="mt-2 text-xs text-indigo-600 font-medium">Subiendo...</div>
        </div>
        @error('upload') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    @endif
</div>
