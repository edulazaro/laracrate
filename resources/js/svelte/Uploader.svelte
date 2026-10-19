<!--
    One file shown big, an avatar or a logo: the Svelte side of laracrate-uploader, with
    the same markup and its two layouts, `row` and `portrait`. An upload replaces the
    current file:

      <Uploader target="avatar" layout="portrait" rounded="full" />

    The target (UploadTargets::define, with a grant that replaces) gives the grant, the
    collection's rules and the current file; the component shows the one just uploaded,
    polls its processing, and removes it. With `grant` instead, the app passes the
    current file as `file` (File::toComponentArray()). `rounded` rounds the preview, not
    the card. With `deferred` the file waits for the Upload button, or for
    uploader.startBatch() from the page.
-->
<script>
    import Icon from './Icon.svelte';
    import { uploader } from './uploader.svelte.js';
    import { appearance, styleString } from '../core/appearance.js';
    import { fill, labelsFor } from '../core/labels.js';
    import { megabytes } from '../core/format.js';
    import { currentFile, singleState } from '../core/single.js';

    let {
        grant, target, params, file, kind, layout = 'row', deferred = false, removable = true, accept, maxSizeKb, pollMs,
        locale = 'en', labels: own = {},
        theme = null, rounded = null, bordered = null, shadow = null, size = null, class: className = null, style = '',
        onuploaded, onrejected, onremoved,
    } = $props();

    const labels = $derived(labelsFor(locale, own));
    const look = $derived(appearance({ theme, rounded, bordered, shadow, size, className }, '--wire-laracrate-preview-radius'));

    const upload = uploader(() => ({
        grant, target, params, file, pollMs, single: true, autoStart: !deferred, maxFiles: null, persistQueue: false,
        refusedMessage: labels.refused, onUploaded: onuploaded, onRejected: onrejected, onRemoved: onremoved,
    }), () => [target, params]);
    const queue = upload.queue;
    const view = $derived(upload.view);
    const place = $derived(upload.target);
    const rules = $derived(place.rules ?? {});
    const maxSize = $derived(maxSizeKb ?? rules.max_size_kb);
    const current = $derived(singleState(view));
    const existing = $derived(currentFile(place.file, kind ?? rules.kind ?? 'image'));

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
        if (window.confirm(labels.delete_confirm)) upload.actions.remove();
    }
</script>

{#snippet picker()}
    <input
        bind:this={input}
        type="file"
        class="laracrate-hidden"
        accept={accept ?? rules.accept ?? '*/*'}
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
    aria-busy={!place.ready || undefined}
>
    {#if !place.available}
        <!-- Asking the target for a grant, or refused: the error is below. -->
    {:else if current.staged}
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
                {#if removable}
                    <button type="button" onclick={remove} disabled={place.removing} title={labels.delete_short} class="laracrate-icon-button is-danger"><Icon name="trash" /></button>
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
                {#if maxSize}<p class="laracrate-hint">{fill(labels.max_size_capital, { size: megabytes(maxSize) })}</p>{/if}
            </div>
            <span class="laracrate-button is-small laracrate-uploader__choose">{labels.choose}</span>
            {@render picker()}
        </div>
    {/if}

    {#if current.failed}<p class="laracrate-error">{current.item.error || labels.failed}</p>{/if}
    {#if place.error}<p class="laracrate-error">{place.error}</p>{/if}
</div>
