/**
 * The current file of a single-file component, as the app passes it: its own JSON of
 * the File ({ url, name or title or original_name, size, extension?, type?, preview?,
 * processing_status? }). `preview` is the image shown for it, its url for an image.
 */
export function currentFile(file, kind = 'mixed') {
    if (!file) return null;

    const name = file.title ?? file.name ?? file.original_name ?? '';
    const type = file.type ?? kind;

    return {
        url: file.url,
        name,
        size: file.size ?? 0,
        extension: (file.extension ?? (name.includes('.') ? name.split('.').pop() : '')).toUpperCase(),
        kind: type,
        preview: file.preview ?? (type === 'image' ? file.url : null),
        status: file.processing_status ?? 'completed',
    };
}

/** What a single-file component shows for the file it holds: staged, on its way, or failed. */
export function singleState(view) {
    const item = view.queue[0] ?? null;
    const staged = !!item && item.status === 'pending' && !item.queued;

    return {
        item,
        staged,
        failed: item?.status === 'error',
        busy: !!item && !staged && item.status !== 'error',
        // Full once it is saved, while it fades out: the batch's own progress is reset by then.
        progress: ['done', 'fade'].includes(item?.status) ? 100 : view.batchProgress,
    };
}
