/**
 * Laracrate JS helper.
 *
 * Funciones reutilizables para subida directa a R2/S3 sin pasar por PHP.
 *
 * Uso desde la app (sin npm install ni vendor:publish):
 *
 *   // Opción 1 — import con path relativo
 *   import { presignAndUpload, deleteTemp } from '../../packages/edulazaro/laracrate/resources/js/laracrate';
 *
 *   // Opción 2 — añadir un alias en vite.config.js:
 *   //   resolve: { alias: { 'laracrate': resolve(__dirname, 'packages/edulazaro/laracrate/resources/js') } }
 *   // y luego:
 *   import { presignAndUpload } from 'laracrate';
 *
 * Vite bundlea el código junto con app.js. Sin runtime extra.
 */

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const defaultEndpoints = {
    presign: '/laracrate/uploads/presign',
    cancel:  (disk, encodedKey) => `/laracrate/uploads/${disk}/${encodedKey}`,
};

/**
 * Pide presigned URL al server, sube directo a R2/S3 y devuelve los datos
 * para confirmar (key, mime, size, original_name) que la app envía a su
 * propio endpoint de attach.
 *
 * @param {File} file         Objeto File del input.
 * @param {Object} opts
 * @param {string} opts.disk                  Disk lógico (media, documents, ...).
 * @param {string} [opts.collection]          Colección. Si la pasas + fileable, la key es canónica directa.
 * @param {Object} [opts.fileable]            { type, id }. Habilita key canónica (sin temp/).
 * @param {number} [opts.maxSizeKb]           Validación cliente y server.
 * @param {string} [opts.presignUrl]          Override de la ruta presign.
 * @param {Function} [opts.onProgress]        Callback (0..1) para progress del PUT.
 * @returns {Promise<{ key: string, original_name: string, mime_type: string, size: number, disk: string }>}
 */
export async function presignAndUpload(file, opts = {}) {
    if (opts.maxSizeKb && file.size > opts.maxSizeKb * 1024) {
        throw new Error(`File excede ${opts.maxSizeKb} KB.`);
    }

    const presignUrl = opts.presignUrl ?? defaultEndpoints.presign;

    const presign = await fetch(presignUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            disk:           opts.disk,
            mime:           file.type || 'application/octet-stream',
            file_name:      file.name,
            max_size_kb:    opts.maxSizeKb,
            fileable_type:  opts.fileable?.type,
            fileable_id:    opts.fileable?.id,
            collection:     opts.collection,
        }),
    }).then(handleJson);

    await putWithProgress(presign.url, file, presign.headers ?? {}, opts.onProgress);

    return {
        key:           presign.key,
        disk:          presign.disk ?? opts.disk,
        original_name: file.name,
        mime_type:     file.type,
        size:          file.size,
    };
}

/**
 * Polling del estado de procesamiento de un único File. Resuelve cuando
 * el File está `completed` o `failed`, o cuando se agotan los intentos.
 *
 * @param {string} slug
 * @param {Object} opts
 * @param {number} [opts.interval]    ms entre rondas (default 2000)
 * @param {number} [opts.maxAttempts] cap de rondas (default 60 → 2min)
 * @param {Function} [opts.onProgress] callback con cada respuesta
 * @param {string} [opts.statusUrl]   override del endpoint
 * @returns {Promise<{slug, status, ready, url, preview, variants, error}>}
 */
export async function pollFileStatus(slug, opts = {}) {
    const interval    = opts.interval ?? 2000;
    const maxAttempts = opts.maxAttempts ?? 60;
    const url         = opts.statusUrl ?? `/laracrate/files/${slug}/status`;

    for (let i = 0; i < maxAttempts; i++) {
        const res = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        }).then(handleJson);

        opts.onProgress?.(res);

        if (res.ready || res.status === 'failed') return res;

        await sleep(interval);
    }

    throw new Error(`Polling timeout para ${slug}`);
}

/**
 * Polling batch de varios slugs. Una sola request por ronda. En cada ronda
 * solo pregunta por los que aún están pending/processing.
 *
 * @param {string[]} slugs
 * @param {Object} opts
 * @param {number} [opts.interval]
 * @param {number} [opts.maxAttempts]
 * @param {Function} [opts.onProgress] recibe el map { slug: payload }
 * @param {string} [opts.statusUrl]
 * @returns {Promise<Object>} map final { slug: payload }
 */
export async function pollFilesStatus(slugs, opts = {}) {
    const interval    = opts.interval ?? 2000;
    const maxAttempts = opts.maxAttempts ?? 60;
    const url         = opts.statusUrl ?? '/laracrate/files/status';

    let pending = [...slugs];
    const final = {};

    for (let i = 0; i < maxAttempts; i++) {
        if (pending.length === 0) return final;

        const statuses = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ slugs: pending }),
        }).then(handleJson);

        opts.onProgress?.(statuses);

        // Mover los completados/failed a final, dejar los pending para la siguiente ronda.
        pending = pending.filter((slug) => {
            const info = statuses[slug];
            if (!info) return false; // no devuelto → omitido por permisos, dropear
            if (info.ready || info.status === 'failed') {
                final[slug] = info;
                return false;
            }
            return true;
        });

        if (pending.length === 0) return final;

        await sleep(interval);
    }

    // Timeout: devolvemos lo que tengamos hasta ahora.
    return final;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Borra una key temp/ del backend si el usuario cancela el upload.
 */
export async function deleteTemp(disk, key, opts = {}) {
    if (!key?.startsWith('temp/')) return false;

    const cancelUrl = opts.cancelUrl
        ?? defaultEndpoints.cancel(disk, encodeURIComponent(btoa(key)));

    const res = await fetch(cancelUrl, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
    });

    return res.ok;
}

/* ------------------------------------------------------------------
 | Internos
 * ------------------------------------------------------------------ */

async function handleJson(res) {
    if (!res.ok) {
        const body = await res.text();
        throw new Error(`Laracrate request failed (${res.status}): ${body}`);
    }
    return res.json();
}

function putWithProgress(url, file, headers, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);
        Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));

        if (onProgress && xhr.upload) {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) onProgress(e.loaded / e.total);
            });
        }

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) resolve(xhr);
            else reject(new Error(`PUT failed (${xhr.status}): ${xhr.responseText}`));
        };
        xhr.onerror = () => reject(new Error('Network error during PUT.'));
        xhr.send(file);
    });
}
