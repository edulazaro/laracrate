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
    <div
        @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
        @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
        @click="$refs.input.click()" role="button" tabindex="0"
        :class="dragOver ? 'border-blue-500 bg-blue-50/50' : 'border-gray-300 hover:border-gray-500'"
        class="border-l-2 pl-4 py-6 cursor-pointer transition-colors"
    >
        <p class="text-sm text-gray-700">{{ __('laracrate::uploader.drag_or_click_long') }}</p>
        <p class="text-xs text-gray-400 mt-1">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
        <input type="file" x-ref="input" @if($multiple) multiple @endif accept="{{ $acceptAttr }}" class="hidden"
            @change="handleFiles($event.target.files); $event.target.value = ''" />
    </div>

    <div x-show="queue.length > 0" class="mt-4 space-y-2" x-cloak>
        <div class="flex items-center justify-between text-xs text-gray-500">
            <span>
                <span x-show="!uploading && pendingCount > 0" x-text="pendingCount + ' en cola'"></span>
                <span x-show="uploading">{{ str(__('laracrate::uploader.uploading'))->lower() }}</span>
                <span x-show="!uploading && pendingCount === 0 && doneCount > 0" x-text="doneCount + ' OK' + (errorCount > 0 ? ' / ' + errorCount + ' err' : '')"></span>
            </span>
            <span x-show="uploading" class="text-blue-600" x-text="batchProgress + '%'"></span>
        </div>
        <div x-show="uploading" class="w-full bg-gray-200 h-px"><div class="bg-blue-500 h-px transition-all duration-300" :style="'width: ' + batchProgress + '%'"></div></div>
        @if (($layout ?? 'grid') === 'list')
            @include('laracrate::dropzone-deferred._queue-list')
        @else
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-2">
            <template x-for="(item, i) in queue" :key="item.id">
                <div class="relative aspect-square overflow-hidden">
                    <template x-if="item.preview"><img :src="item.preview" class="w-full h-full object-cover"></template>
                    <template x-if="!item.preview"><div class="w-full h-full bg-gray-50"></div></template>
                    <div class="absolute inset-0 flex items-center justify-center transition-opacity duration-300" :class="{ 'bg-blue-500/30 opacity-100': item.status === 'uploading', 'bg-blue-500/20 opacity-100': item.status === 'done', 'bg-red-500/40 opacity-100': item.status === 'error', 'opacity-0': item.status === 'fade' || item.status === 'pending' }">
                        <template x-if="item.status === 'uploading'"><svg class="animate-spin h-4 w-4 text-blue-700" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg></template>
                        <template x-if="item.status === 'done'"><svg class="w-5 h-5 text-blue-700" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></template>
                        <template x-if="item.status === 'error'"><button type="button" @click.stop="retryItem(i)" class="text-[10px] text-white underline">retry</button></template>
                    </div>
        @endif
                    <template x-if="item.status === 'pending'"><button type="button" @click.stop="removeItem(i)" class="absolute top-0.5 right-0.5 text-gray-500 hover:text-gray-900 text-[10px]">×</button></template>
                </div>
            </template>
        </div>

        {{-- Acciones primarias centradas --}}
        <div x-show="!uploading && pendingCount > 0" class="flex flex-col items-center gap-1.5 pt-2">
            <button type="button" @click="startBatch()"
                class="inline-flex items-center justify-center px-5 h-9 border-l-2 border-blue-500 bg-blue-50 text-blue-700 text-sm hover:bg-blue-100 transition-colors">
                {{ str(__('laracrate::uploader.submit'))->lower() }}
                <span x-show="pendingCount > 1" class="ml-1.5 text-blue-500" x-text="'(' + pendingCount + ')'"></span>
            </button>
            <button type="button" @click="clearQueue()"
                class="text-xs text-gray-500 hover:text-gray-900 underline">
                {{ str(__('laracrate::uploader.cancel'))->lower() }}
            </button>
        </div>
    </div>
</div>
