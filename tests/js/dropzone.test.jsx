import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render as renderReact } from '@testing-library/react';
import { render as renderSvelte } from '@testing-library/svelte';
import { createRef } from 'react';
import { Dropzone as ReactDropzone } from '../../resources/js/react/index.js';
import { Dropzone as SvelteDropzone } from '../../resources/js/svelte/index.js';
import { useEngine } from '../../resources/js/core/engine.js';
import { fakeEngine, file, tick } from './fakeEngine.js';

let engine;

beforeEach(() => {
    engine = fakeEngine();
    useEngine(engine);
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
    cleanup();
    useEngine(null);
});

const settle = () => act(async () => { await tick(); await tick(); });

/** Each framework's Dropzone, mounted with the same props: the tests run on both. */
const frameworks = {
    react(props) {
        const ref = createRef();
        const { container } = renderReact(<ReactDropzone ref={ref} {...props} />);
        return { container, startBatch: () => act(() => { ref.current.startBatch(); }) };
    },
    svelte(props) {
        const { className, onUploaded, onRejected, onBatchCompleted, onMaxFiles, ...rest } = props;
        const svelteProps = {
            ...rest, class: className, onuploaded: onUploaded, onrejected: onRejected,
            onbatchcompleted: onBatchCompleted, onmaxfiles: onMaxFiles,
        };
        const { container, component } = renderSvelte(SvelteDropzone, { props: svelteProps });
        return { container, startBatch: () => act(() => { component.startBatch(); }) };
    },
};

const pick = async (container, files) => {
    fireEvent.change(container.querySelector('input[type=file]'), { target: { files } });
    await settle();
};

describe.each(Object.keys(frameworks))('the %s Dropzone', (name) => {
    const mount = frameworks[name];

    it('uploads the files picked with the grant the app gives, and announces them', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 'case:9:documents' }));
        const uploaded = vi.fn();
        const completed = vi.fn();
        const { container } = mount({ grant, onUploaded: uploaded, onBatchCompleted: completed, persistQueue: true });

        expect(container.querySelector('.laracrate.laracrate-dropzone')).not.toBeNull();
        expect(container.querySelector('.laracrate-title').textContent).toBe('Upload file');

        await pick(container, [file('a.png'), file('b.pdf', 'application/pdf')]);

        expect(grant).toHaveBeenCalledTimes(1);
        expect(engine.items.map((item) => [item.name, item.scope])).toEqual([['a.png', 'case:9:documents'], ['b.pdf', 'case:9:documents']]);
        expect(container.querySelectorAll('.laracrate-tile')).toHaveLength(2);
        expect(container.querySelector('.laracrate-tile__preview').getAttribute('src')).toBe('blob:preview');

        act(() => engine.progress(engine.items[0], 500));
        await settle();
        expect(container.querySelector('.laracrate-status').textContent).toContain('uploading');
        expect(container.querySelector('.laracrate-progress__bar').style.width).toBe('25%');

        act(() => engine.items.forEach((item) => engine.finish(item)));
        await settle();

        expect(uploaded).toHaveBeenCalledTimes(2);
        expect(completed).toHaveBeenCalledWith({ ok: 2, error: 0 });
        expect(container.querySelector('.laracrate-status').textContent).toContain('2 OK');
    });

    it('stages the files of a deferred dropzone until Upload is pressed', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 's' }));
        const { container } = mount({ grant, deferred: true });

        expect(container.querySelector('.laracrate-title').textContent).toBe('Select file');

        await pick(container, [file('a.png'), file('b.png')]);
        expect(grant).not.toHaveBeenCalled();
        expect(container.querySelectorAll('.laracrate-tile__remove')).toHaveLength(2);
        expect(container.querySelector('.laracrate-button').textContent).toContain('(2)');

        fireEvent.click(container.querySelector('.laracrate-tile__remove'));
        await settle();
        expect(container.querySelectorAll('.laracrate-tile')).toHaveLength(1);

        fireEvent.click(container.querySelector('.laracrate-button'));
        await settle();
        expect(grant).toHaveBeenCalledTimes(1);
        expect(engine.items.map((item) => item.name)).toEqual(['b.png']);
        expect(container.querySelector('.laracrate-actions')).toBeNull();
    });

    it('starts a deferred batch from the page', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 's' }));
        const { container, startBatch } = mount({ grant, deferred: true, hideActions: true });

        await pick(container, [file('a.png')]);
        expect(container.querySelector('.laracrate-actions')).toBeNull();

        startBatch();
        await settle();
        expect(engine.items).toHaveLength(1);
    });

    it('lists the files with a thumbnail for images and an icon for the rest', async () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, layout: 'list', deferred: true });

        await pick(container, [file('a.png'), file('b.pdf', 'application/pdf', 2048)]);

        const rows = container.querySelectorAll('.laracrate-row');
        expect(rows).toHaveLength(2);
        expect(rows[0].querySelector('.laracrate-row__thumb')).not.toBeNull();
        expect(rows[1].querySelector('.laracrate-row__thumb')).toBeNull();
        expect(rows[1].querySelector('.laracrate-row__icon svg')).not.toBeNull();
        expect(rows[1].querySelector('.laracrate-row__size').textContent).toBe('2.0 KB');
    });

    it('shows a refused file as an error to retry', async () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, layout: 'list' });

        await pick(container, [file('a.png')]);
        act(() => engine.fail(engine.items[0], 'Network'));
        await settle();

        const retry = container.querySelector('.laracrate-row__retry');
        expect(retry.getAttribute('title')).toBe('Network');

        fireEvent.click(retry);
        await settle();
        expect(engine.items[0].status).toBe('pending');
    });

    it('closes once it holds maxFiles', async () => {
        const maxed = vi.fn();
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, maxFiles: 1, onMaxFiles: maxed, persistQueue: true });

        expect(container.querySelector('input[type=file]').multiple).toBe(false);

        await pick(container, [file('a.png'), file('b.png')]);
        expect(maxed).toHaveBeenCalledWith({ max: 1, rejected: 1 });
        expect(container.querySelector('.laracrate-drop.is-full')).not.toBeNull();
        expect(container.querySelector('input[type=file]')).toBeNull();
    });

    it('takes the theme, the look and the labels given', async () => {
        const { container } = mount({
            grant: { grant: 'token', scope: 's' }, theme: 'studio', rounded: 'xl', bordered: false, className: 'mine',
            locale: 'es', labels: { upload: 'Sube aquí' }, maxSizeKb: 5120,
        });
        const root = container.querySelector('.laracrate-dropzone');

        expect(root.getAttribute('data-wire-theme')).toBe('studio');
        expect(root.classList.contains('mine')).toBe(true);
        expect(root.style.getPropertyValue('--wire-laracrate-radius')).not.toBe('');
        expect(container.querySelector('.laracrate-title').textContent).toBe('Sube aquí');
        expect(container.querySelector('.laracrate-hint').textContent).toContain('5');
    });
});
