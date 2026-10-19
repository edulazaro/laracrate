<!--
    One file shown big, an avatar or a logo: the Svelte side of laracrate-uploader, with
    the same markup and its two layouts, `row` and `portrait`. An upload replaces the
    current file, so the app issues the grant with `replace: true`, and passes the new
    file back once it is saved:

      <Uploader {grant} file={avatar} layout="portrait" rounded="full" onuploaded={reloadAvatar} onremove={deleteAvatar} />

    `file` is the app's own JSON of the current one ({ url, preview?, name, size,
    processing_status? }); while it is pending or processing the app passes it again
    when it changes. `rounded` rounds the preview, not the card. With `deferred` the
    file waits for the Upload button, or for uploader.startBatch() from the page.
-->
<script>
    import Icon from './Icon.svelte';
    import { uploadQueue } from './queue.svelte.js';
    import { appearance, styleString } from '../core/appearance.js';
    import { fill, labelsFor } from '../core/labels.js';
    import { megabytes } from '../core/format.js';
    import { currentFile, singleState } from '../core/single.js';

    let {
        grant, file = null, kind = 'image', layout = 'row', deferred = false, accept = '*/*', maxSizeKb = null,
        locale = 'en', labels: own = {},
        theme = null, rounded = null, bordered = null, shadow = null, size = null, class: className = null, style = '',
        onuploaded, onrejected, onremove,
    } = $props();

    const labels = $derived(labelsFor(locale, own));
    const look = $derived(appearance({ theme, rounded, bordered, shadow, size, className }, '--wire-laracrate-preview-radius'));

    const upload = uploadQueue(() => ({
        grant, autoStart: !deferred, maxFiles: null, persistQueue: false, refusedMessage: labels.refused,
        onUploaded: onuploaded, onRejected: onrejected,
    }));
    const queue = upload.queue;
    const view = $derived(upload.view);
    const current = $derived(singleState(view));
    const existing = $derived(currentFile(file, kind));

    let input = $state();
    let over = $state(false);

    export function startBatch() {
        return queue.startBatch();
    }

    export function clear() {
        queue.clear();
    }

    function take(files) {
        if (current.failed) queue.clear();
        queue.handleFiles(Array.from(files || []).slice(0, 1));
    }

    function dragover(event) {
        event.preventDefault();
        over = true;
    }

    function dragleave(event) {
        event.preventDefault();
        over = false;
    }

    function drop(event) {
        event.preventDefault();
        over = false;
        take(event.dataTransfer.files);
    }

    function remove() {
        if (window.confirm(labels.delete_confirm)) onremove(file);
    }
</script>

{#snippet picker()}
    <input
        bind:this={input}
        type="file"
        class="laracrate-hidden"
        {accept}
        onchange={(event) => { take(event.target.files); event.target.value = ''; }}
    />
{/snippet}

{#snippet media(src)}
    <div class="laracrate-uploader__media">
        {#if src}
            <img {src} alt="" class="laracrate-uploader__image" />
        {:else}
            <span class="laracrate-uploader__placeholder"><Icon name="file" /></span>
        {/if}
        {#if !deferred && over}<div class="laracrate-uploader__drop-hint">{labels.drop_to_replace.toLowerCase()}</div>{/if}
    </div>
{/snippet}

<div
    data-wire-theme={look.theme ?? undefined}
    class={[`laracrate laracrate-uploader is-${layout === 'portrait' ? 'portrait' : 'row'}`, look.classes].filter(Boolean).join(' ')}
    style={styleString(look.vars, style) || undefined}
>
    {#if current.staged}
        <div class="laracrate-uploader__card is-staged">
            {@render media(current.item.preview)}
            <div class="laracrate-uploader__body">
                <p class="laracrate-label">{labels.pending}</p>
                <p class="laracrate-name">{current.item.name}</p>
                <p class="laracrate-meta">{Math.round(current.item.size / 1024)} KB</p>
            </div>
            <div class="laracrate-uploader__actions">
                <button type="button" onclick={() => queue.startBatch()} class="laracrate-button">{labels.submit}</button>
                <button type="button" onclick={() => queue.clear()} title={labels.cancel} class="laracrate-icon-button"><Icon name="close" /></button>
            </div>
        </div>
    {:else if current.busy}
        <div class="laracrate-uploader__card is-busy">
            {@render media(current.item.preview)}
            <div class="laracrate-uploader__body">
                <p class="laracrate-label">{labels.file_label}</p>
                <p class="laracrate-name">{current.item.name}</p>
                <p class="laracrate-meta">
                    {Math.round(current.item.size / 1024)} KB · <span class="laracrate-pulse">{labels.uploading.toLowerCase()} {current.progress}%</span>
                </p>
            </div>
        </div>
    {:else if existing}
        <div
            class="laracrate-uploader__card"
            role="group"
            ondragover={deferred ? undefined : dragover}
            ondragleave={deferred ? undefined : dragleave}
            ondrop={deferred ? undefined : drop}
        >
            {@render media(existing.preview)}
            <div class="laracrate-uploader__body">
                <p class="laracrate-label">{labels.file_label}</p>
                <p class="laracrate-name">{existing.name}</p>
                <p class="laracrate-meta">
                    {Math.round(existing.size / 1024)} KB
                    {#if ['pending', 'processing'].includes(existing.status)}
                        · <span class="laracrate-pulse">{labels.processing.toLowerCase()}</span>
                    {:else if existing.status === 'failed'}
                        · <span class="is-error">{labels.failed.toLowerCase()}</span>
                    {/if}
                </p>
            </div>
            <div class="laracrate-uploader__actions">
                {#if !deferred}
                    <button type="button" onclick={() => input.click()} class="laracrate-button laracrate-uploader__replace">{labels.replace}</button>
                {/if}
                {#if onremove}
                    <button type="button" onclick={remove} title={labels.delete_short} class="laracrate-icon-button is-danger"><Icon name="trash" /></button>
                {/if}
            </div>
            {#if !deferred}{@render picker()}{/if}
        </div>
    {:else}
        <div
            ondragover={dragover}
            ondragleave={dragleave}
            ondrop={drop}
            onclick={() => input.click()}
            onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); input.click(); } }}
            role="button"
            tabindex="0"
            class="laracrate-drop laracrate-uploader__drop"
            class:is-dragover={over}
        >
            <span class="laracrate-icon"><Icon name="upload" /></span>
            <div class="laracrate-uploader__drop-body">
                <p class="laracrate-title">{deferred ? labels.select : labels.upload}</p>
                <p class="laracrate-subtitle">{labels.drag_or_click.toLowerCase()}</p>
                {#if maxSizeKb}<p class="laracrate-hint">{fill(labels.max_size_capital, { size: megabytes(maxSizeKb) })}</p>{/if}
            </div>
            <span class="laracrate-button is-small laracrate-uploader__choose">{labels.choose}</span>
            {@render picker()}
        </div>
    {/if}

    {#if current.failed}<p class="laracrate-error">{current.item.error || labels.failed}</p>{/if}
</div>
