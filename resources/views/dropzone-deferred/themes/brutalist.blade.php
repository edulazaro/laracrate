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
        x-show="!reachedMax"
        @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false"
        @drop.prevent="dragOver = false; handleFiles($event.dataTransfer.files)"
        @click="$refs.input.click()" role="button" tabindex="0"
        :class="dragOver ? 'border-neutral-900 bg-yellow-200 -translate-x-0.5 -translate-y-0.5 shadow-[6px_6px_0_0_rgba(10,10,10,1)]' : 'border-neutral-900 bg-white shadow-[4px_4px_0_0_rgba(10,10,10,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:shadow-[6px_6px_0_0_rgba(10,10,10,1)]'"
        class="rounded-[4px] border-2 border-dashed p-10 text-center cursor-pointer transition-all duration-150"
    >
        <svg class="w-10 h-10 text-neutral-900 mx-auto mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
        <p class="text-[11px] font-mono font-semibold uppercase tracking-[0.25em] text-neutral-500">{{ __('laracrate::uploader.select') }}</p>
        <p class="mt-1 text-sm text-neutral-950 font-bold">{{ __('laracrate::uploader.drag_or_click') }}</p>
        <p class="mt-2 text-xs text-neutral-500 font-mono">{{ __('laracrate::uploader.max_size_capital', ['size' => number_format($maxSizeKb / 1024, 1)]) }}</p>
        <input type="file" x-ref="input" @if($multiple) multiple @endif accept="{{ $acceptAttr }}" class="hidden"
            @change="handleFiles($event.target.files); $event.target.value = ''" />
    </div>

    <div x-show="queue.length > 0" class="mt-6 space-y-3" x-cloak>
        <div class="flex items-center justify-between gap-3">
            <p class="text-[10px] font-mono uppercase tracking-[0.2em] text-neutral-700">
                <span x-show="!uploading && pendingCount > 0" x-text="pendingCount + ' en cola'"></span>
                <span x-show="uploading">{{ __('laracrate::uploader.uploading') }}</span>
                <span x-show="!uploading && pendingCount === 0 && doneCount > 0" x-text="doneCount + ' OK' + (errorCount > 0 ? ' / ' + errorCount + ' err' : '')"></span>
            </p>
            <span x-show="uploading" class="text-xs text-neutral-700 font-mono" x-text="batchProgress + '%'"></span>
        </div>
        <div x-show="uploading" class="w-full border border-neutral-900 h-2 bg-yellow-100"><div class="bg-neutral-900 h-full transition-all duration-300" :style="'width: ' + batchProgress + '%'"></div></div>
        @if (($layout ?? 'grid') === 'list')
            @include('laracrate::dropzone-deferred._queue-list')
        @else
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <template x-for="(item, i) in queue" :key="item.id">
                <div class="relative aspect-square rounded-[3px] overflow-hidden border border-neutral-900 bg-white">
                    <template x-if="item.preview"><img :src="item.preview" class="w-full h-full object-cover"></template>
                    <template x-if="!item.preview"><div class="w-full h-full bg-yellow-200 flex items-center justify-center text-neutral-900"><svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></div></template>
                    <div class="absolute inset-0 flex items-center justify-center transition-opacity duration-300" :class="{ 'bg-neutral-900/50 opacity-100': item.status === 'uploading', 'bg-yellow-300/70 opacity-100': item.status === 'done', 'bg-red-500/60 opacity-100': item.status === 'error', 'opacity-0': item.status === 'fade' || item.status === 'pending' }">
                        <template x-if="item.status === 'uploading'"><svg class="animate-spin h-5 w-5 text-yellow-200" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg></template>
                        <template x-if="item.status === 'done'"><svg class="w-6 h-6 text-neutral-900" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></template>
                        <template x-if="item.status === 'error'"><button type="button" @click.stop="retryItem(i)" class="inline-flex items-center justify-center w-7 h-7 rounded-[3px] border border-neutral-900 bg-white text-red-700"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></button></template>
                    </div>
        @endif
                    <template x-if="item.status === 'pending'"><button type="button" @click.stop="removeItem(i)" class="absolute top-1 right-1 inline-flex items-center justify-center w-5 h-5 rounded-[3px] border border-neutral-900 bg-white text-neutral-700"><svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M6 18L18 6"/></svg></button></template>
                    <p class="absolute bottom-0 left-0 right-0 bg-neutral-950 text-yellow-100 text-[10px] font-mono px-1.5 py-0.5 truncate" x-text="item.name"></p>
                </div>
            </template>
        </div>

        {{-- Acciones primarias centradas --}}
        <div x-show="!uploading && pendingCount > 0" class="flex flex-col items-center gap-2 pt-2">
            <button type="button" @click="startBatch()"
                class="inline-flex items-center justify-center px-6 h-10 rounded-[4px] border border-neutral-900 bg-neutral-950 text-white text-xs font-bold uppercase tracking-[0.15em] hover:bg-neutral-800 shadow-[4px_4px_0_0_rgba(10,10,10,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:shadow-[6px_6px_0_0_rgba(10,10,10,1)] transition-all duration-150">
                {{ __('laracrate::uploader.submit') }}
                <span x-show="pendingCount > 1" class="ml-1.5 text-yellow-200 font-mono tabular-nums normal-case tracking-normal" x-text="'(' + pendingCount + ')'"></span>
            </button>
            <button type="button" @click="clearQueue()"
                class="text-[10px] font-mono uppercase tracking-[0.15em] text-neutral-700 hover:text-neutral-950 underline">
                {{ __('laracrate::uploader.cancel') }}
            </button>
        </div>
    </div>
</div>
