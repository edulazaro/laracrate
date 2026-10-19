<!--
    One file, straight to the bucket: the Svelte side of laracrate-dropzone-single, with
    the same markup. An upload replaces the current file:

      <DropzoneSingle target="venue.cover" params={{ venue: venue.id }} />

    The target (UploadTargets::define, with a grant that replaces) gives the grant, the
    collection's rules and the current file; the component shows the one just uploaded,
    polls its processing, and removes it. With `grant` instead, the app passes the
    current file as `file` (File::toComponentArray()). With `deferred` the file waits
    for the Upload button, or for single.startBatch() from the page.
-->
<script>
    import Icon from './Icon.svelte';
    import { uploader } from './uploader.svelte.js';
    import { appearance, styleString } from '../core/appearance.js';
    import { fill, labelsFor } from '../core/labels.js';
    import { megabytes } from '../core/format.js';
    import { currentFile, singleState } from '../core/single.js';

    let {
        grant, target, params, file, kind, deferred = false, hideActions = false, removable = true, accept, maxSizeKb,
        extensions, icon = null, pollMs, locale = 'en', labels: own = {},
        theme = null, rounded = null, bordered = null, shadow = null, size = null, class: className = null, style = '',
        onuploaded, onrejected, onbatchcompleted, onremoved,
    } = $props();

    const labels = $derived(labelsFor(locale, own));
    const look = $derived(appearance({ theme, rounded, bordered, shadow, size, className }));

    const upload = uploader(() => ({
        grant, target, params, file, pollMs, single: true, autoStart: !deferred, maxFiles: null, persistQueue: false,
        refusedMessage: labels.refused, onUploaded: onuploaded, onRejected: onrejected, onBatchCompleted: onbatchcompleted,
        onRemoved: onremoved,
    }), () => [target, params]);
    const queue = upload.queue;
    const view = $derived(upload.view);
    const place = $derived(upload.target);
    const rules = $derived(place.rules ?? {});
    const types = $derived(kind ?? rules.kind ?? 'mixed');
    const maxSize = $derived(maxSizeKb ?? rules.max_size_kb);
    const current = $derived(singleState(view));
    const existing = $derived(currentFile(place.file, types));
    const hint = $derived([
        maxSize && fill(labels.max_size_capital, { size: megabytes(maxSize) }),
        (extensions ?? rules.extensions ?? []).map((extension) => extension.toUpperCase()).join(', '),
    ].filter(Boolean).join(' · '));

    let input = $state();
    let over = $state(false);

    export function startBatch() {
        return queue.startBatch();
    }

    export function clear() {
        queue.clear();
    }

    function take(files) {
        queue.handleFiles(Array.from(files || []).slice(0, 1));
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

<div
    data-wire-theme={look.theme ?? undefined}
    class={['laracrate laracrate-single', look.classes].filter(Boolean).join(' ')}
    style={styleString(look.vars, style) || undefined}
    aria-busy={!place.ready || undefined}
>
    {#if place.available && !current.item && existing}
        <div class="laracrate-card">
            {#if existing.kind === 'image'}
                <img src={existing.url} alt={existing.name} class="laracrate-card__thumb" />
            {:else if existing.kind === 'video'}
                <video controls class="laracrate-card__thumb is-video"><source src={existing.url} /></video>
            {:else if existing.kind === 'audio'}
                <audio controls class="laracrate-card__audio"><source src={existing.url} /></audio>
            {:else}
                <span class="laracrate-icon"><Icon name="document" /></span>
            {/if}
            <div class="laracrate-card__body">
                <p class="laracrate-name">{existing.name}</p>
                <p class="laracrate-meta">{(existing.size / 1024).toFixed(1)} KB · {existing.extension}</p>
            </div>
            <button type="button" onclick={() => input.click()} class="laracrate-icon-button is-accent" title={labels.replace}><Icon name="upload" /></button>
            <a href={existing.url} target="_blank" rel="noreferrer" class="laracrate-icon-button" title={labels.view}><Icon name="eye" /></a>
            {#if removable}
                <button type="button" onclick={remove} disabled={place.removing} class="laracrate-icon-button is-danger" title={labels.delete_short}><Icon name="close" /></button>
            {/if}
            {@render picker()}
        </div>
    {/if}

    {#if place.available && !current.item && !existing}
        <div
            role="button"
            tabindex="0"
            class="laracrate-drop is-compact"
            class:is-dragover={over}
            ondragover={(event) => { event.preventDefault(); over = true; }}
            ondragleave={(event) => { event.preventDefault(); over = false; }}
            ondrop={(event) => { event.preventDefault(); over = false; take(event.dataTransfer.files); }}
            onclick={() => input.click()}
            onkeydown={(event) => { if (event.key === 'Enter') { event.preventDefault(); input.click(); } }}
        >
            <span class="laracrate-icon"><Icon name={icon ?? types} /></span>
            <p class="laracrate-title">{labels.select}</p>
            <p class="laracrate-subtitle">{labels.drag_or_click.toLowerCase()}</p>
            {#if hint}<p class="laracrate-hint">{hint}</p>{/if}
            {@render picker()}
        </div>
    {/if}

    {#if current.staged}
        <div class="laracrate-card">
            <span class="laracrate-icon"><Icon name="file" /></span>
            <div class="laracrate-card__body">
                <p class="laracrate-label">{labels.pending}</p>
                <p class="laracrate-name">{current.item.name}</p>
            </div>
            {#if !hideActions}
                <button type="button" onclick={() => queue.startBatch()} class="laracrate-button is-small">{labels.submit}</button>
                <button type="button" onclick={() => queue.clear()} class="laracrate-icon-button is-danger" title={labels.cancel}><Icon name="close" /></button>
            {/if}
        </div>
    {/if}

    {#if current.busy}
        <div class="laracrate-card is-busy">
            <span class="laracrate-spinner"><Icon name="spinner" /></span>
            <div class="laracrate-card__body">
                <p class="laracrate-name">{current.item.name}</p>
                <div class="laracrate-progress"><div class="laracrate-progress__bar" style="width: {current.progress}%"></div></div>
            </div>
        </div>
    {/if}

    {#if current.failed}
        <div class="laracrate-card">
            <span class="laracrate-icon"><Icon name="file" /></span>
            <div class="laracrate-card__body">
                <p class="laracrate-name">{current.item.name}</p>
                <p class="laracrate-meta"><span class="is-error">{current.item.error || labels.failed}</span></p>
            </div>
            {#if current.item.retryable !== false}
                <button type="button" onclick={() => queue.retry(current.item.key)} class="laracrate-icon-button" title={labels.retry}><Icon name="retry" /></button>
            {/if}
            <button type="button" onclick={() => queue.clear()} class="laracrate-icon-button is-danger" title={labels.cancel}><Icon name="close" /></button>
        </div>
    {/if}

    {#if place.error}<p class="laracrate-error">{place.error}</p>{/if}
</div>
