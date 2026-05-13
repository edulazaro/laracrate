@include('laracrate::dropzone._script')

<div
    x-data="laracrateDropzone({
        presignUrl:   @js(route('laracrate.uploads.presign')),
        disk:         @js($disk),
        fileableType: @js($fileableType),
        fileableId:   @js($fileableId),
        collection:   @js($collection),
        maxSizeKb:    @js($maxSizeKb),
        persistQueue: false,
        autoStart:    false,
        maxFiles:     1,
    })"
    class="w-full"
>
    @if ($existing)
        {{-- Estado: archivo ya subido — muestra preview/info + botón quitar --}}
        <div class="flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-sm">
            @switch($iconCategory ?? 'mixed')
                @case('image')
                    <img src="{{ $existing->url() }}" alt="{{ $existing->original_name }}"
                         class="w-12 h-12 rounded-sm object-cover flex-shrink-0">
                    @break
                @case('video')
                    <video controls class="w-20 h-12 rounded-sm bg-black object-cover flex-shrink-0">
                        <source src="{{ $existing->url() }}">
                    </video>
                    @break
                @case('audio')
                    <audio controls class="flex-shrink-0 max-w-[180px]">
                        <source src="{{ $existing->url() }}">
                    </audio>
                    @break
                @default
                    <div class="w-10 h-10 rounded-sm border border-gray-300 bg-white text-gray-700 inline-flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                        </svg>
                    </div>
            @endswitch
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-[10px] font-mono text-gray-400 tabular-nums">
                    {{ number_format($existing->size / 1024, 1) }} KB · {{ strtoupper($existing->extension ?? '') }}
                </p>
            </div>
            <a href="{{ $existing->url() }}" target="_blank"
               class="p-1.5 text-gray-600 hover:bg-gray-100 rounded-sm transition-colors" title="Ver">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>
            <button type="button" wire:click="removeFile"
                    wire:confirm="¿Quitar este archivo?"
                    class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-sm transition-colors" title="Quitar">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    @else
        {{-- Estado: vacío — muestra dropzone --}}
        <label
            x-show="!uploading && queue.length === 0"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            role="button"
            tabindex="0"
            :class="dragOver ? 'border-gray-900 bg-gray-50' : 'border-gray-300 bg-white hover:border-gray-500'"
            class="flex flex-col items-center justify-center w-full py-6 px-4 border border-dashed rounded-sm cursor-pointer transition-colors"
        >
            <div class="inline-flex items-center justify-center w-10 h-10 rounded-sm border border-gray-300 bg-white text-gray-700 mb-3">
                @switch($iconCategory ?? 'mixed')
                    @case('image')
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
                        @break
                    @case('video')
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/></svg>
                        @break
                    @case('audio')
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9l10.5-3m0 6.553v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 11-.99-3.467l2.31-.66a2.25 2.25 0 001.632-2.163zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 01-.99-3.467l2.31-.66A2.25 2.25 0 009 15.553z"/></svg>
                        @break
                    @case('document')
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                        @break
                    @default
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                @endswitch
            </div>
            <p class="text-sm text-gray-900 font-medium">{{ __('laracrate::uploader.select') }}</p>
            <p class="mt-1 text-[11px] font-mono text-gray-500 tabular-nums">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
            <p class="mt-3 text-[10px] font-mono uppercase tracking-wide text-gray-400">
                {{ __('laracrate::uploader.max_size', ['size' => number_format($maxSizeKb / 1024, 1)]) }}
                @if(!empty($extensions))
                    · {{ implode(', ', array_map('strtoupper', $extensions)) }}
                @endif
            </p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden"
                @change="handleFiles($event.target.files); $event.target.value = ''" />
        </label>

        {{-- Pending: staged, waiting confirm --}}
        <div x-show="queue.length > 0 && queue[0]?.status === 'pending'" x-cloak
             class="flex items-center gap-3 p-3 bg-white border border-gray-300 rounded-sm">
            <div class="w-10 h-10 rounded-sm border border-gray-300 bg-gray-50 text-gray-700 inline-flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[10px] font-mono uppercase tracking-wide text-gray-400">{{ __('laracrate::uploader.pending') }}</p>
                <p class="text-sm font-medium text-gray-900 truncate" x-text="queue[0]?.name ?? '...'"></p>
            </div>
            <button type="button" @click="startBatch()"
                class="inline-flex items-center justify-center h-9 px-4 rounded-sm bg-gray-900 text-white text-[11px] font-mono uppercase tracking-wide hover:bg-gray-800 flex-shrink-0">{{ __('laracrate::uploader.submit') }}</button>
            <button type="button" @click="removeItem(0)" title="{{ __('laracrate::uploader.cancel') }}"
                class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-sm flex-shrink-0">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Subiendo: progreso compacto in-place --}}
        <div x-show="queue.length > 0 && queue[0]?.status === 'uploading'" x-cloak
             class="flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-sm">
            <svg class="animate-spin w-5 h-5 text-gray-500 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900 truncate" x-text="queue[0]?.name ?? '...'"></p>
                <div class="w-full bg-gray-200 h-0.5 mt-1">
                    <div class="bg-gray-900 h-0.5 transition-all duration-300" :style="'width: ' + batchProgress + '%'"></div>
                </div>
            </div>
        </div>
    @endif
</div>
