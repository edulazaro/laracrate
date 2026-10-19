import { act, fireEvent, render as renderReact } from '@testing-library/react';
import { render as renderSvelte } from '@testing-library/svelte';
import { createRef } from 'react';
import { tick } from './fakeEngine.js';

/**
 * Mounts a component in React and in Svelte with the same props, written the React
 * way (onUploaded, className): the Svelte side gets them as it takes them (onuploaded,
 * class). The tests run once per framework.
 */
export function mounters(ReactComponent, SvelteComponent) {
    return {
        react(props) {
            const ref = createRef();
            const { container, rerender } = renderReact(<ReactComponent ref={ref} {...props} />);
            return {
                container,
                startBatch: () => act(() => { ref.current.startBatch(); }),
                update: (more) => act(() => rerender(<ReactComponent ref={ref} {...props} {...more} />)),
            };
        },
        svelte(props) {
            const { container, component, rerender } = renderSvelte(SvelteComponent, { props: svelteProps(props) });
            return {
                container,
                startBatch: () => act(() => { component.startBatch(); }),
                update: (more) => act(() => rerender(svelteProps({ ...props, ...more }))),
            };
        },
    };
}

function svelteProps(props) {
    return Object.fromEntries(Object.entries(props).map(([name, value]) => [
        name === 'className' ? 'class' : (/^on[A-Z]/.test(name) ? name.toLowerCase() : name),
        value,
    ]));
}

export const settle = () => act(async () => { await tick(); await tick(); });

export async function pick(container, files) {
    fireEvent.change(container.querySelector('input[type=file]'), { target: { files } });
    await settle();
}
