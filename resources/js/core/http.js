/**
 * The JSON endpoints the components call themselves, besides the upload queue's: a
 * target's grant, removing the current file, and its processing status. Same CSRF
 * token as the queue, from <meta name="csrf-token">.
 */
const routes = {
    targetUrl: '/laracrate/uploads/targets/:target',
    removeUrl: '/laracrate/uploads/remove',
    statusUrl: '/laracrate/files/:slug/status',
};

/** Other paths, when the app changed the route prefixes: { targetUrl, removeUrl, statusUrl }. */
export function setRoutes(options = {}) {
    for (const name of Object.keys(routes)) {
        if (options[name]) routes[name] = options[name];
    }
}

export function route(name, params = {}) {
    return routes[name].replace(/:(\w+)/g, (_, key) => encodeURIComponent(params[key] ?? ''));
}

export async function request(method, url, body) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw Object.assign(new Error(data.message || `HTTP ${response.status}`), { status: response.status });
    }

    return data;
}
