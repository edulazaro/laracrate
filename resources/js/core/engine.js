/**
 * The page's upload queue (../laracrate-uploader.js) for a bundled app: a plain
 * script that defines window.Laracrate.uploads once. It is loaded on first use, in
 * the browser only, so a component can render on the server (Inertia SSR) too.
 */
let loading = null;
let override = null;

export function loadEngine() {
    if (override) return Promise.resolve(override);
    if (typeof window === 'undefined') return Promise.reject(new Error('Laracrate uploads run in the browser only.'));
    if (window.Laracrate?.uploads) return Promise.resolve(window.Laracrate.uploads);

    loading ??= import('../laracrate-uploader.js').then(() => window.Laracrate.uploads);

    return loading;
}

/**
 * Sets the upload routes or the messages, as the Blade components do from the app's
 * config and locale: { signUrl, confirmUrl, registerUrl, messages: {...} }.
 */
export async function configure(options) {
    (await loadEngine()).configure(options);
}

/** Tests only: an engine to use instead of the real one (null to restore). */
export function useEngine(engine) {
    override = engine;
}
