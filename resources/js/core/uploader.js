import { createQueue } from './queue.js';
import { createTarget } from './target.js';

const PASSED = ['autoStart', 'maxFiles', 'persistQueue', 'refusedMessage', 'onRejected', 'onBatchCompleted', 'onMaxFiles', 'onChange'];

/**
 * A component's target and queue, wired: the queue asks the target for each grant,
 * and a single file component (`single`) takes each saved file as its current one.
 * Both read `options` at call time, so an adapter refreshes that same object.
 */
export function createUploader(options) {
    const target = createTarget(options);
    const queue = createQueue(Object.defineProperties({
        grant: (files) => target.grantFor(files),
        onUploaded: (file, item) => {
            if (options.single) target.uploaded(file);
            options.onUploaded?.(file, item);
        },
    }, Object.fromEntries(PASSED.map((name) => [name, { get: () => options[name], enumerable: true }]))));

    return {
        target,
        queue,

        /** Mounted: returns what unmounts it. The uploads themselves keep going. */
        start() {
            queue.start();
            target.start().then((scope) => queue.restore(scope)).catch(() => {});

            return () => {
                queue.destroy();
                target.destroy();
            };
        },
    };
}
