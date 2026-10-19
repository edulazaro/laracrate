import { createQueue } from '../core/queue.js';

/**
 * The core queue of a component, with a version that changes on every change of it:
 * read `queue.view` in the markup and it renders again. `options()` returns the
 * component's current props, read again on each use, so a new `grant` or callback
 * from the parent is the one used next.
 */
export function uploadQueue(options) {
    let version = $state(0);
    const current = { onChange: () => { version++; } };
    const sync = () => Object.assign(current, options(), { onChange: current.onChange });
    sync();

    const queue = createQueue(current);

    $effect(() => {
        sync();
    });

    $effect(() => {
        queue.start();
        return () => queue.destroy();
    });

    return {
        get view() {
            version;
            return queue.snapshot();
        },
        queue,
    };
}
