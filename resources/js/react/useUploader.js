import { useEffect, useRef, useState } from 'react';
import { createUploader } from '../core/uploader.js';
import { appearance } from '../core/appearance.js';

/**
 * A component's target and queue (core/uploader.js), rendered again on every change.
 * The options object is the one they read, refreshed on every render, so a new
 * `grant`, `file` or callback from the parent is the one used next. A new `target` or
 * `params` asks for a new grant.
 */
export function useUploader(current) {
    const [, setVersion] = useState(0);
    const options = useRef({}).current;
    const onChange = useRef(() => setVersion((version) => version + 1)).current;
    Object.assign(options, current, { onChange });

    const uploader = useRef(null);
    uploader.current ??= createUploader(options);
    uploader.current.target.sync();

    const key = `${current.target ?? ''}|${JSON.stringify(current.params ?? {})}`;
    useEffect(() => uploader.current.start(), [key]);

    return uploader.current;
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
