@include('laracrate::dropzone._script')

<div
    x-data="laracrateDropzone({
        presignUrl:   @js(route('laracrate.uploads.presign')),
        disk:         @js($disk),
        fileableType: @js($fileableType),
        fileableId:   @js($fileableId),
        collection:   @js(request()->route()?->parameter('collection') ?? null),
        maxSizeKb:    @js($maxSizeKb),
        persistQueue: @js($persistQueue),
        autoStart:    false,
        maxFiles:     @js($maxFiles ?? null),
    })"
    class="w-full"
>
    {{-- Dropzone --}}
    <div
        x-show="!reachedMax"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
        @click="$refs.input.click()"
        role="button"
        tabindex="0"
        :class="dragOver ? 'border-blue-400 bg-blue-50' : 'border-gray-300 bg-gray-50 hover:bg-gray-100'"
        class="border-2 border-dashed rounded-xl p-10 text-center transition cursor-pointer"
    >
        <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
        <p class="text-sm text-gray-600 mb-2">
            <span class="font-semibold">{{ __('laracrate::uploader.drag_or_click') }}</span>
        </p>
        <p class="text-xs text-gray-500 mb-4">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
        <input type="file" x-ref="input" @if($multiple) multiple @endif accept="{{ $acceptAttr }}" class="hidden"
            @change="handleFiles($event.target.files); $event.target.value = ''" />
    </div>

    {{-- Cola staged + acciones --}}
    <div x-show="queue.length > 0" class="mt-6 space-y-3" x-cloak>
        <div class="flex items-center justify-between gap-3">
            <h4 class="text-sm font-medium text-gray-700">
                <span x-show="!uploading && pendingCount > 0" x-text="pendingCount + ' archivo(s) en cola'"></span>
                <span x-show="uploading">{{ __('laracrate::uploader.uploading') }}</span>
                <span x-show="!uploading && pendingCount === 0 && doneCount > 0" x-text="doneCount + ' OK' + (errorCount > 0 ? ' / ' + errorCount + ' err' : '')"></span>
            </h4>
            <span x-show="uploading" class="text-xs text-gray-500" x-text="batchProgress + '%'"></span>
        </div>

        <div x-show="uploading" class="w-full bg-gray-200 rounded-full h-1.5">
            <div class="bg-blue-600 h-1.5 rounded-full transition-all duration-300" :style="'width: ' + batchProgress + '%'"></div>
        </div>

        @if (($layout ?? 'grid') === 'list')

            @include('laracrate::dropzone-deferred._queue-list')

        @else

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <template x-for="(item, i) in queue" :key="item.id">
                <div class="relative aspect-square rounded-lg overflow-hidden border border-gray-200">
                    <template x-if="item.preview">
                        <img :src="item.preview" class="w-full h-full object-cover">
                    </template>
                    <template x-if="!item.preview">
                        <div class="w-full h-full bg-gray-100 flex items-center justify-center text-gray-400">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </div>
                    </template>

                    <div class="absolute inset-0 flex items-center justify-center transition-opacity duration-300"
                         :class="{
                             'bg-black/30 opacity-100': item.status === 'uploading',
                             'bg-green-500/40 opacity-100': item.status === 'done',
                             'bg-red-500/50 opacity-100': item.status === 'error',
                             'opacity-0': item.status === 'fade' || item.status === 'pending',
                         }">
                        <template x-if="item.status === 'uploading'">
                            <svg class="animate-spin h-6 w-6 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                            </svg>
                        </template>
                        <template x-if="item.status === 'done'">
                            <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </template>
                        <template x-if="item.status === 'error'">
                            <button type="button" @click.stop="retryItem(i)" title="Retry"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-white/90 hover:bg-white text-red-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </button>
                        </template>
                    </div>

        @endif

                    {{-- Botón quitar item antes de subir (solo en estado pending) --}}
                    <template x-if="item.status === 'pending'">
                        <button type="button" @click.stop="removeItem(i)" title="Quitar"
                            class="absolute top-1 right-1 inline-flex items-center justify-center w-6 h-6 rounded-full bg-white/90 hover:bg-white text-gray-700 shadow">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg>
                        </button>
                    </template>

                    <p class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-[10px] px-1.5 py-0.5 truncate" x-text="item.name"></p>
                </div>
            </template>
        </div>

        {{-- Acciones primarias centradas --}}
        <div x-show="!uploading && pendingCount > 0 && {{ ($hideActions ?? false) ? 'false' : 'true' }}" class="flex flex-col items-center gap-2 pt-2">
            <button type="button" @click="startBatch()"
                class="inline-flex items-center justify-center px-6 h-10 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 transition-colors">
                {{ __('laracrate::uploader.submit') }}
                <span x-show="pendingCount > 1" class="ml-1.5 text-blue-200 font-mono tabular-nums" x-text="'(' + pendingCount + ')'"></span>
            </button>
            <button type="button" @click="clearQueue()"
                class="text-xs text-gray-500 hover:text-gray-900 underline">
                {{ __('laracrate::uploader.cancel') }}
            </button>
        </div>
    </div>
</div>
