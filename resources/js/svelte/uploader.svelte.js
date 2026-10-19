import { untrack } from 'svelte';
import { createUploader } from '../core/uploader.js';

/**
 * A component's target and queue (core/uploader.js), with a version that changes on
 * every change of them: read `view` and `target` in the markup and it renders again.
 * `options()` returns the component's current props, read again on each use, so a
 * new `grant`, `file` or callback is the one used next; `key()` returns what asks for
 * a new grant when it changes (the target and its params).
 */
export function uploader(options, key) {
    let version = $state(0);
    const current = { onChange: () => { version++; } };
    const sync = () => Object.assign(current, options(), { onChange: current.onChange });
    sync();

    const parts = createUploader(current);
    parts.target.sync();

    $effect(() => {
        sync();
        if (parts.target.sync()) untrack(() => { version++; });
    });

    $effect(() => {
        JSON.stringify(key());
        return untrack(() => parts.start());
    });

    return {
        get view() {
            version;
            return parts.queue.snapshot();
        },
        get target() {
            version;
            return parts.target.snapshot();
        },
        queue: parts.queue,
        actions: parts.target,
    };
}
