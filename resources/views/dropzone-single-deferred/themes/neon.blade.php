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
    @laracrate-start-batch.window="
        if (($event.detail.fileableType ?? null) === @js($fileableType)
            && String($event.detail.fileableId ?? '') === @js((string) $fileableId)
            && ($event.detail.collection ?? null) === @js($collection)) {
            startBatch();
        }
    "
    class="w-full"
>
    @if ($existing)
        <div class="flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-lg">
            @if(($iconCategory ?? 'mixed') === 'image')
                <img src="{{ $existing->url() }}" alt="" class="w-14 h-14 rounded-lg object-cover flex-shrink-0">
            @elseif($iconCategory === 'video')
                <video controls class="w-24 h-14 rounded-lg bg-black object-cover flex-shrink-0"><source src="{{ $existing->url() }}"></video>
            @elseif($iconCategory === 'audio')
                <audio controls class="flex-shrink-0 max-w-[200px]"><source src="{{ $existing->url() }}"></audio>
            @else
                <div class="w-12 h-12 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                </div>
            @endif
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $existing->title ?? $existing->original_name }}</p>
                <p class="text-xs text-gray-500">{{ number_format($existing->size / 1024, 1) }} KB</p>
            </div>
            <button type="button" wire:click="removeFile" wire:confirm="¿Quitar este archivo?"
                    class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-md" title="Quitar">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    @else
        <label
            x-show="queue.length === 0"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
            :class="dragOver ? 'border-blue-400 bg-blue-50' : 'border-gray-300 bg-gray-50 hover:bg-gray-100'"
            class="flex flex-col items-center justify-center w-full py-8 px-4 border-2 border-dashed rounded-xl cursor-pointer transition"
        >
            <svg class="w-10 h-10 text-gray-400 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                @switch($iconCategory ?? 'mixed')
                    @case('image')<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>@break
                    @case('video')<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z"/>@break
                    @case('audio')<path stroke-linecap="round" stroke-linejoin="round" d="M9 9l10.5-3m0 6.553v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 11-.99-3.467l2.31-.66a2.25 2.25 0 001.632-2.163zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 01-.99-3.467l2.31-.66A2.25 2.25 0 009 15.553z"/>@break
                    @case('document')<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>@break
                    @default<path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 00-2.25 2.25v9a2.25 2.25 0 002.25 2.25h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25H15M9 12l3 3m0 0l3-3m-3 3V2.25"/>
                @endswitch
            </svg>
            <p class="text-sm text-gray-600"><span class="font-semibold">{{ __('laracrate::uploader.drag_or_click') }}</span></p>
            <p class="text-xs text-gray-500 mt-1">
                {{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}
            </p>
            <input type="file" x-ref="input" accept="{{ $acceptAttr }}" class="hidden"
                @change="handleFiles($event.target.files); $event.target.value = ''" />
        </label>

        {{-- Staged: pending file --}}
        <div x-show="queue.length > 0 && queue[0]?.status === 'pending'" x-cloak
             class="flex items-center gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <svg class="w-6 h-6 text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900 truncate" x-text="queue[0].name"></p>
                <p class="text-xs text-amber-700">{{ __('laracrate::uploader.pending') }}</p>
            </div>
            @if (!$hideActions)
                <button type="button" @click="startBatch()" class="text-xs font-semibold px-3 py-1.5 bg-gray-900 text-white rounded-md hover:bg-black">{{ __('laracrate::uploader.submit') }}</button>
            @endif
            <button type="button" @click="removeItem(0)" class="p-1.5 text-gray-400 hover:text-red-600" title="Quitar">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Uploading --}}
        <div x-show="queue.length > 0 && queue[0]?.status === 'uploading'" x-cloak
             class="flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-lg">
            <svg class="animate-spin w-5 h-5 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900 truncate" x-text="queue[0]?.name ?? '...'"></p>
                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                    <div class="bg-blue-500 h-1.5 rounded-full transition-all" :style="'width: ' + batchProgress + '%'"></div>
                </div>
            </div>
        </div>
    @endif
</div>
