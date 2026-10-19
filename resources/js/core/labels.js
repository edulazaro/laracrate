/**
 * The components' texts, the same as lang/{en,es}/uploader.php. An app passes its own
 * in `labels` (all of them, or only the ones it changes).
 */
export const LABELS = {
    en: {
        cancel: 'Cancel', choose: 'Choose', delete_confirm: 'Delete this file?', delete_short: 'Remove',
        drag_or_click: 'Drag or click', drop_to_replace: 'Drop to replace', failed: 'Error', file_label: 'File',
        in_queue: 'in queue', max_reached_title: 'No more files can be added', max_reached_unit: 'file(s)',
        max_size_capital: 'Max. :size MB', pending: 'Pending upload', processing: 'Processing', replace: 'Replace',
        retry: 'Retry', select: 'Select file', submit: 'Upload', upload: 'Upload file', uploading: 'Uploading...',
        view: 'View', refused: 'The upload could not start.',
    },
    es: {
        cancel: 'Cancelar', choose: 'Elegir', delete_confirm: '¿Borrar este archivo?', delete_short: 'Borrar',
        drag_or_click: 'Arrastra o haz clic', drop_to_replace: 'Suelta para reemplazar', failed: 'Error', file_label: 'Archivo',
        in_queue: 'en cola', max_reached_title: 'No puedes añadir más archivos', max_reached_unit: 'archivo(s)',
        max_size_capital: 'Máx. :size MB', pending: 'Pendiente de subir', processing: 'Procesando', replace: 'Reemplazar',
        retry: 'Reintentar', select: 'Seleccionar archivo', submit: 'Subir', upload: 'Subir archivo', uploading: 'Subiendo...',
        view: 'Ver', refused: 'No se pudo iniciar la subida.',
    },
};

/** The texts of a locale (English when it has none), with the app's own on top. */
export function labelsFor(locale = 'en', overrides = {}) {
    return { ...LABELS.en, ...(LABELS[locale] ?? {}), ...overrides };
}

/** "Max. :size MB" with its :placeholders filled. */
export function fill(text, params = {}) {
    return Object.entries(params).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text);
}
