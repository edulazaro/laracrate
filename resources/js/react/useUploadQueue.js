import { useEffect, useRef, useState } from 'react';
import { createQueue } from '../core/queue.js';
import { appearance } from '../core/appearance.js';

/**
 * The core queue of a component, rendered again on every change. The options object
 * is the same one the queue reads, refreshed on every render, so a new `grant` or
 * callback from the parent is the one used next.
 */
export function useUploadQueue(current) {
    const [, setVersion] = useState(0);
    const options = useRef({}).current;
    Object.assign(options, current, { onChange: () => setVersion((version) => version + 1) });

    const queue = useRef(null);
    queue.current ??= createQueue(options);

    useEffect(() => {
        queue.current.start();
        return () => queue.current.destroy();
    }, []);

    return queue.current;
}

/** The root attributes of a component: its theme, classes and style. */
export function rootProps(props, base, roundedVariable) {
    const look = appearance(props, roundedVariable);

    return {
        'data-wire-theme': look.theme ?? undefined,
        className: [base, look.classes].filter(Boolean).join(' '),
        style: { ...look.vars, ...(props.style || {}) },
    };
}
