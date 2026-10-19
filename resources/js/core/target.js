import { request, route } from './http.js';

const PROCESSING = ['pending', 'processing'];
// A grant lives 24 hours; one this old is asked for again, so a page left open still uploads.
const GRANT_MAX_AGE = 30 * 60 * 1000;

/**
 * Where a component uploads, and the file it holds, with no framework.
 *
 * With `target` (one the app declared with UploadTargets::define) it asks the server
 * for a grant, the collection's rules and, for a single file, the current one; with
 * `grant` it takes the one the app issued. It keeps the current file: the one the app
 * passes as `file`, or the target's, then the one just uploaded, polling its status
 * while it is processed. And it removes it.
 *
 * @param {object} options
 * @param {string} [options.target] a target name
 * @param {object} [options.params] the target's params: { venue: 12 }
 * @param {object|function} [options.grant] { grant, scope } or a function of the files, instead of a target
 * @param {object|null} [options.file] the current file from the app; undefined leaves it to the target
 * @param {number} [options.pollMs=1500] how often the status of a file being processed is asked for
 * @param {function} [options.onRemoved] (file) once the current file is removed
 * @param {function} [options.onChange] () on every change, to render again
 */
export function createTarget(options = {}) {
    const state = { ready: !options.target, available: !options.target, rules: null, file: null, error: null, removing: false };
    let issued = null;
    let loading = null;
    let given;
    let timer = null;
    let live = false;

    const changed = () => { if (live) options.onChange?.(); };

    async function load() {
        try {
            const data = await request('POST', route('targetUrl', { target: options.target }), { params: options.params ?? {} });
            issued = { grant: data.grant, scope: data.scope, at: Date.now() };
            Object.assign(state, { ready: true, available: true, rules: data.rules ?? null, error: null });
            if (options.file === undefined) setFile(data.file ?? null);
            changed();

            return issued;
        } catch (error) {
            Object.assign(state, { ready: true, error: error.message });
            changed();
            throw error;
        }
    }

    function fresh() {
        if (issued && Date.now() - issued.at < GRANT_MAX_AGE) return Promise.resolve(issued);
        loading ??= load().finally(() => { loading = null; });

        return loading;
    }

    async function grantFor(files) {
        if (options.target) return fresh();

        return typeof options.grant === 'function' ? options.grant(files) : options.grant;
    }

    function setFile(file) {
        state.file = file ?? null;
        poll();
    }

    function poll() {
        clearTimeout(timer);
        const file = state.file;
        if (!live || !file?.slug || !PROCESSING.includes(file.processing_status)) return;

        timer = setTimeout(async () => {
            try {
                const status = await request('GET', route('statusUrl', { slug: file.slug }));
                if (state.file !== file) return;
                state.file = { ...file, processing_status: status.status, url: status.url ?? file.url, preview: status.preview ?? file.preview };
                changed();
                poll();
            } catch {
                // Behind auth by default: a guest's file is shown as it was.
            }
        }, options.pollMs ?? 1500);
    }

    return {
        state,

        /** Mounted, or the target changed: a fresh grant. Resolves the scope, to show the uploads still running for it. */
        start() {
            live = true;
            issued = null;
            poll();

            if (!options.target) {
                return Promise.resolve(typeof options.grant === 'object' ? options.grant?.scope ?? null : null);
            }

            return fresh().then((grant) => grant.scope);
        },

        destroy() {
            live = false;
            clearTimeout(timer);
        },

        /** The `file` the app passes, when it changes. True when it did. */
        sync() {
            if (options.file === undefined || options.file === given) return false;
            given = options.file;
            setFile(options.file);

            return true;
        },

        /** The grant for the files picked, as the queue asks for it. */
        grantFor,

        /** A file just saved: it is the current one now. */
        uploaded(file) {
            state.error = null;
            setFile(file);
            changed();
        },

        /** Removes the current file, with a grant that replaces it. */
        async remove() {
            const file = state.file;
            if (!file || state.removing) return;

            state.removing = true;
            changed();
            try {
                const grant = await grantFor([]);
                await request('POST', route('removeUrl'), { grant: grant?.grant });
                Object.assign(state, { file: null, removing: false, error: null });
                clearTimeout(timer);
                changed();
                options.onRemoved?.(file);
            } catch (error) {
                Object.assign(state, { removing: false, error: error.message });
                changed();
            }
        },

        snapshot() {
            return { ...state };
        },
    };
}
