<!--
    Several files, straight to the bucket: the Svelte side of laracrate-dropzone, with
    the same markup, so laracrate.css and its themes apply as they are.

      <Dropzone target="venue.gallery" params={{ venue: venue.id }} onuploaded={(file) => ...} />
      <Dropzone target="case.documents" params={{ case: id }} deferred layout="list" theme="studio" bind:this={dropzone} />

    `target` is one the app declared with UploadTargets::define: the component asks it
    for a grant and the collection's rules. Instead, `grant` takes an UploadGrant the
    app issued ({ grant, scope }), or a function that asks the app for one for the files
    picked. With `deferred` the files wait for the Upload button, or for
    dropzone.startBatch() from the page.
-->
<script>
    import Icon from './Icon.svelte';
    import { uploader } from './uploader.svelte.js';
    import { appearance, styleString } from '../core/appearance.js';
    import { fill, labelsFor } from '../core/labels.js';
    import { fileSize, megabytes } from '../core/format.js';

    let {
        grant, target, params, deferred = false, multiple = true, maxFiles = null, layout = 'grid', hideActions = false,
        persistQueue = false, accept, maxSizeKb, icon = 'upload', locale = 'en', labels: own = {},
        theme = null, rounded = null, bordered = null, shadow = null, size = null, class: className = null, style = '',
        onuploaded, onrejected, onbatchcompleted, onmaxfiles,
    } = $props();

    const labels = $derived(labelsFor(locale, own));
    const look = $derived(appearance({ theme, rounded, bordered, shadow, size, className }));

    const upload = uploader(() => ({
        grant, target, params, autoStart: !deferred, maxFiles, persistQueue, refusedMessage: labels.refused,
        onUploaded: onuploaded, onRejected: onrejected, onBatchCompleted: onbatchcompleted, onMaxFiles: onmaxfiles,
    }), () => [target, params]);
    const queue = upload.queue;
    const view = $derived(upload.view);
    const place = $derived(upload.target);
    const rules = $derived(place.rules ?? {});
    const maxSize = $derived(maxSizeKb ?? rules.max_size_kb);

    let input = $state();
    let over = $state(false);

    export function startBatch() {
        return queue.startBatch();
    }

    export function clear() {
        queue.clear();
    }

    function drop(event) {
        event.preventDefault();
        over = false;
        queue.handleFiles(event.dataTransfer.files);
    }
</script>

<div
    data-wire-theme={look.theme ?? undefined}
    class={['laracrate laracrate-dropzone', look.classes].filter(Boolean).join(' ')}
    style={styleString(look.vars, style) || undefined}
    data-deferred={deferred || undefined}
    aria-busy={!place.ready || undefined}
>
    {#if !place.available}
        <!-- Asking the target for a grant, or refused: the error is below. -->
    {:else if !view.reachedMax}
        <div
            role="button"
            tabindex="0"
            class="laracrate-drop"
            class:is-dragover={over}
            ondragover={(event) => { event.preventDefault(); over = true; }}
            ondragleave={(event) => { event.preventDefault(); over = false; }}
            ondrop={drop}
            onclick={() => input.click()}
            onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); input.click(); } }}
        >
            <span class="laracrate-icon"><Icon name={icon} /></span>
            <p class="laracrate-title">{deferred ? labels.select : labels.upload}</p>
            <p class="laracrate-subtitle">{labels.drag_or_click.toLowerCase()}</p>
            {#if maxSize}<p class="laracrate-hint">{fill(labels.max_size_capital, { size: megabytes(maxSize) })}</p>{/if}
            <input
                bind:this={input}
                type="file"
                class="laracrate-hidden"
                multiple={multiple && (maxFiles === null || maxFiles > 1)}
                accept={accept ?? rules.accept ?? '*/*'}
                onchange={(event) => { queue.handleFiles(event.target.files); event.target.value = ''; }}
            />
        </div>
    {:else}
        <div class="laracrate-drop is-full">
            <span class="laracrate-icon"><Icon name="check" /></span>
            <p class="laracrate-title">{labels.max_reached_title}</p>
            <p class="laracrate-subtitle">{maxFiles ?? '∞'} {labels.max_reached_unit}</p>
        </div>
    {/if}

    {#if view.queue.length > 0}
        <div class="laracrate-queue">
            <div class="laracrate-status">
                <p>
                    {#if !view.uploading && view.pendingCount > 0}<span>{view.pendingCount} {labels.in_queue}</span>{/if}
                    {#if view.uploading}<span>{labels.uploading.toLowerCase()}</span>{/if}
                    {#if !view.uploading && view.pendingCount === 0 && view.doneCount > 0}
                        <span>{view.doneCount} OK{view.errorCount > 0 ? ` / ${view.errorCount} err` : ''}</span>
                    {/if}
                </p>
                {#if view.uploading}<span>{view.batchProgress}%</span>{/if}
            </div>

            {#if view.uploading}
                <div class="laracrate-progress"><div class="laracrate-progress__bar" style="width: {view.batchProgress}%"></div></div>
            {/if}

            {#if layout === 'list'}
                <div class="laracrate-list">
                    {#each view.queue as item (item.key)}
                        <div class="laracrate-row" data-state={item.status}>
                            {#if item.preview}
                                <img src={item.preview} alt="" class="laracrate-row__thumb" />
                            {:else}
                                <span class="laracrate-row__icon"><Icon name="file" /></span>
                            {/if}
                            <p class="laracrate-row__name">{item.name}</p>
                            <p class="laracrate-row__size">{fileSize(item.size)}</p>
                            {#if item.status === 'uploading'}<span class="laracrate-spinner"><Icon name="spinner" /></span>{/if}
                            {#if item.status === 'done'}<span class="laracrate-row__done"><Icon name="check" /></span>{/if}
                            {#if item.status === 'error'}
                                <button type="button" title={item.error || labels.retry} class="laracrate-row__retry"
                                    onclick={(event) => { event.stopPropagation(); queue.retry(item.key); }}><Icon name="retry" /></button>
                            {/if}
                            {#if item.status === 'pending'}
                                <button type="button" title={labels.delete_short} class="laracrate-row__remove"
                                    onclick={(event) => { event.stopPropagation(); queue.remove(item.key); }}><Icon name="close" /></button>
                            {/if}
                        </div>
                    {/each}
                </div>
            {:else}
                <div class="laracrate-grid">
                    {#each view.queue as item (item.key)}
                        <div class="laracrate-tile" data-state={item.status}>
                            {#if item.preview}
                                <img src={item.preview} alt="" class="laracrate-tile__preview" />
                            {:else}
                                <div class="laracrate-tile__placeholder"><Icon name="file" /></div>
                            {/if}
                            <div class="laracrate-tile__overlay">
                                {#if item.status === 'uploading' || (!deferred && item.status === 'pending')}
                                    <span class="laracrate-spinner"><Icon name="spinner" /></span>
                                {/if}
                                {#if item.status === 'done'}<span><Icon name="check" /></span>{/if}
                                {#if item.status === 'error'}
                                    <button type="button" title={item.error || labels.retry} class="laracrate-tile__retry"
                                        onclick={(event) => { event.stopPropagation(); queue.retry(item.key); }}><Icon name="retry" /></button>
                                {/if}
                            </div>
                            {#if deferred && item.status === 'pending'}
                                <button type="button" title={labels.delete_short} class="laracrate-tile__remove"
                                    onclick={(event) => { event.stopPropagation(); queue.remove(item.key); }}><Icon name="close" /></button>
                            {/if}
                            <p class="laracrate-tile__name">{item.name}</p>
                        </div>
                    {/each}
                </div>
            {/if}

            {#if deferred && !hideActions && !view.uploading && view.pendingCount > 0}
                <div class="laracrate-actions">
                    <button type="button" class="laracrate-button" onclick={() => queue.startBatch()}>
                        {labels.submit}
                        {#if view.pendingCount > 1}<span class="laracrate-button__count"> ({view.pendingCount})</span>{/if}
                    </button>
                    <button type="button" class="laracrate-link" onclick={() => queue.clear()}>{labels.cancel}</button>
                </div>
            {/if}
        </div>
    {/if}

    {#if place.error}<p class="laracrate-error">{place.error}</p>{/if}
</div>
