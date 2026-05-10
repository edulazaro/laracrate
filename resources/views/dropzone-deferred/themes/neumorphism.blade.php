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
    })"
    class="w-full"
    style="--neu-bg: #e0e5ec;"
>
    <div
        @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
        @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
        @click="$refs.input.click()" role="button" tabindex="0"
        class="rounded-2xl p-10 text-center cursor-pointer transition-all"
        :style="dragOver
            ? 'background: var(--neu-bg); box-shadow: inset 6px 6px 12px rgba(163,177,198,0.5), inset -6px -6px 12px rgba(255,255,255,0.85);'
            : 'background: var(--neu-bg); box-shadow: 8px 8px 16px rgba(163,177,198,0.6), -8px -8px 16px rgba(255,255,255,0.9);'"
    >
        <div class="rounded-2xl w-14 h-14 mx-auto flex items-center justify-center text-gray-600 mb-3"
            style="background: var(--neu-bg); box-shadow: inset 4px 4px 8px rgba(163,177,198,0.45), inset -4px -4px 8px rgba(255,255,255,0.7);">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
        </div>
        <p class="text-sm font-medium text-gray-700">{{ __('laracrate::uploader.select') }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ str(__('laracrate::uploader.drag_or_click'))->lower() }}</p>
        <p class="mt-2 text-xs text-gray-500">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
        <input type="file" x-ref="input" @if($multiple) multiple @endif accept="{{ $acceptAttr }}" class="hidden"
            @change="handleFiles($event.target.files); $event.target.value = ''" />
    </div>

    <div x-show="queue.length > 0" class="mt-6 space-y-3" x-cloak>
        <div class="flex items-center justify-between gap-3">
            <p class="text-xs text-gray-600 italic">
                <span x-show="!uploading && pendingCount > 0" x-text="pendingCount + ' en cola'"></span>
                <span x-show="uploading">{{ __('laracrate::uploader.uploading') }}</span>
                <span x-show="!uploading && pendingCount === 0 && doneCount > 0" x-text="doneCount + ' OK' + (errorCount > 0 ? ' / ' + errorCount + ' err' : '')"></span>
            </p>
            <div class="flex items-center gap-2">
                <span x-show="uploading" class="text-xs text-gray-600" x-text="batchProgress + '%'"></span>
                <button x-show="!uploading && pendingCount > 0" type="button" @click="clearQueue()" class="text-xs text-gray-600 hover:text-gray-900 underline">{{ __('laracrate::uploader.cancel') }}</button>
                <button x-show="!uploading && pendingCount > 0" type="button" @click="startBatch()" class="inline-flex items-center px-3 h-8 rounded-xl text-gray-700 text-xs font-semibold transition-all" style="background: var(--neu-bg); box-shadow: 4px 4px 8px rgba(163,177,198,0.5), -4px -4px 8px rgba(255,255,255,0.85);">{{ __('laracrate::uploader.submit') }}</button>
            </div>
        </div>
        <div x-show="uploading" class="w-full rounded-full h-1.5" style="background: var(--neu-bg); box-shadow: inset 2px 2px 4px rgba(163,177,198,0.45), inset -2px -2px 4px rgba(255,255,255,0.7);">
            <div class="h-full rounded-full transition-all duration-300 bg-gray-700" :style="'width: ' + batchProgress + '%'"></div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <template x-for="(item, i) in queue" :key="item.id">
                <div class="relative aspect-square rounded-xl overflow-hidden"
                     style="background: var(--neu-bg); box-shadow: inset 4px 4px 8px rgba(163,177,198,0.45), inset -4px -4px 8px rgba(255,255,255,0.7);">
                    <template x-if="item.preview"><img :src="item.preview" class="w-full h-full object-cover"></template>
                    <template x-if="!item.preview"><div class="w-full h-full flex items-center justify-center text-gray-500"><svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></div></template>
                    <div class="absolute inset-0 flex items-center justify-center transition-opacity duration-300" :class="{ 'bg-gray-700/40 opacity-100': item.status === 'uploading', 'bg-emerald-500/40 opacity-100': item.status === 'done', 'bg-red-500/50 opacity-100': item.status === 'error', 'opacity-0': item.status === 'fade' || item.status === 'pending' }">
                        <template x-if="item.status === 'uploading'"><svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg></template>
                        <template x-if="item.status === 'done'"><svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></template>
                        <template x-if="item.status === 'error'"><button type="button" @click.stop="retryItem(i)" class="inline-flex items-center justify-center w-7 h-7 rounded-xl text-red-600" style="background: var(--neu-bg); box-shadow: 2px 2px 4px rgba(163,177,198,0.5), -2px -2px 4px rgba(255,255,255,0.85);"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></button></template>
                    </div>
                    <template x-if="item.status === 'pending'"><button type="button" @click.stop="removeItem(i)" class="absolute top-1 right-1 inline-flex items-center justify-center w-6 h-6 rounded-xl text-gray-600" style="background: var(--neu-bg); box-shadow: 2px 2px 4px rgba(163,177,198,0.5), -2px -2px 4px rgba(255,255,255,0.85);"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg></button></template>
                    <p class="absolute bottom-0 left-0 right-0 bg-gray-700/70 text-white text-[10px] px-1.5 py-0.5 truncate" x-text="item.name"></p>
                </div>
            </template>
        </div>
    </div>
</div>
