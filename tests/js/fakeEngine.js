/**
 * A stand-in for window.Laracrate.uploads: it takes the items as the real queue does
 * and lets a test decide how each upload ends, with no network.
 */
export function fakeEngine() {
    const listeners = new Set();
    const items = [];
    const waiters = [];
    let id = 0;

    const settle = () => {
        for (const waiter of [...waiters]) {
            if (waiter.items.every((item) => !['pending', 'uploading'].includes(item.status))) {
                waiters.splice(waiters.indexOf(waiter), 1);
                waiter.resolve(waiter.items);
            }
        }
    };
    const emit = (type, item) => {
        listeners.forEach((listener) => listener(type, item));
        settle();
    };

    return {
        items,
        enqueue(item, { grant, scope, collection = null }) {
            item.id ??= ++id;
            Object.assign(item, { grant, scope, collection, queued: true, status: 'pending', error: null });
            items.push(item);
            emit('added', item);
            return item;
        },
        reject(item, message, { scope = null } = {}) {
            item.id ??= ++id;
            Object.assign(item, { scope, queued: true, status: 'error', error: message, retryable: false });
            items.push(item);
            emit('error', item);
            return item;
        },
        on(scope, callback) {
            const listener = (type, item) => callback(type, item);
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        waitFor(list) {
            return new Promise((resolve) => {
                waiters.push({ items: Array.from(list), resolve });
                settle();
            });
        },
        itemsFor(scope) {
            return items.filter((item) => item.scope === scope && item.queued);
        },
        retry(item) {
            Object.assign(item, { status: 'pending', error: null });
            emit('retry', item);
        },
        dismiss(item) {
            const at = items.indexOf(item);
            if (at !== -1) items.splice(at, 1);
        },
        progress(item, loaded) {
            Object.assign(item, { status: 'uploading', loaded });
            emit('progress', item);
        },
        finish(item, result = { id: item.id, slug: `file-${item.id}` }) {
            Object.assign(item, { status: 'done', progress: 100, loaded: item.size, result });
            emit('done', item);
        },
        fail(item, error, retryable = true) {
            Object.assign(item, { status: 'error', error, retryable });
            emit('error', item);
        },
    };
}

export const file = (name, type = 'image/png', size = 1000) => new File(['x'.repeat(size)], name, { type });

export const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
