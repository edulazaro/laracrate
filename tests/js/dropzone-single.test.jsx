import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent } from '@testing-library/react';
import { DropzoneSingle as ReactSingle } from '../../resources/js/react/index.js';
import { DropzoneSingle as SvelteSingle } from '../../resources/js/svelte/index.js';
import { useEngine } from '../../resources/js/core/engine.js';
import { fakeEngine, file } from './fakeEngine.js';
import { mounters, pick, settle } from './mount.jsx';

let engine;

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    engine = fakeEngine();
    useEngine(engine);
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
    cleanup();
    useEngine(null);
    vi.useRealTimers();
});

const frameworks = mounters(ReactSingle, SvelteSingle);
const cover = { url: 'https://cdn.test/cover.jpg', original_name: 'cover.jpg', size: 2048, type: 'image' };

describe.each(Object.keys(frameworks))('the %s DropzoneSingle', (name) => {
    const mount = frameworks[name];

    it('shows the drop area with the size and extensions it takes', () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, kind: 'image', maxSizeKb: 2048, extensions: ['jpg', 'png'] });

        expect(container.querySelector('.laracrate.laracrate-single')).not.toBeNull();
        expect(container.querySelector('.laracrate-drop.is-compact')).not.toBeNull();
        expect(container.querySelector('.laracrate-title').textContent).toBe('Select file');
        expect(container.querySelector('.laracrate-hint').textContent).toBe('Max. 2.0 MB · JPG, PNG');
        expect(container.querySelector('input[type=file]').multiple).toBe(false);
    });

    it('uploads one file, shows its progress and leaves the new file to the app', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 'user:1:cover' }));
        const uploaded = vi.fn();
        const { container, update } = mount({ grant, onUploaded: uploaded });

        await pick(container, [file('a.png'), file('b.png')]);
        expect(engine.items.map((item) => item.name)).toEqual(['a.png']);
        expect(container.querySelector('.laracrate-drop')).toBeNull();
        expect(container.querySelector('.laracrate-card.is-busy .laracrate-name').textContent).toBe('a.png');

        act(() => engine.progress(engine.items[0], 500));
        await settle();
        expect(container.querySelector('.laracrate-progress__bar').style.width).toBe('50%');

        act(() => engine.finish(engine.items[0]));
        await settle();
        expect(uploaded).toHaveBeenCalledWith(expect.objectContaining({ slug: 'file-1' }), expect.anything());

        update({ file: cover });
        await act(async () => { await vi.advanceTimersByTimeAsync(1600); });
        expect(container.querySelector('.laracrate-card.is-busy')).toBeNull();
        expect(container.querySelector('.laracrate-card__thumb').getAttribute('src')).toBe(cover.url);
    });

    it('shows the current file, to replace, view or remove', async () => {
        const remove = vi.fn();
        window.confirm = vi.fn(() => true);
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, file: cover, onRemove: remove });

        expect(container.querySelector('.laracrate-name').textContent).toBe('cover.jpg');
        expect(container.querySelector('.laracrate-meta').textContent).toBe('2.0 KB · JPG');
        expect(container.querySelector('a.laracrate-icon-button').getAttribute('href')).toBe(cover.url);

        fireEvent.click(container.querySelector('.laracrate-icon-button.is-danger'));
        expect(window.confirm).toHaveBeenCalledWith('Delete this file?');
        expect(remove).toHaveBeenCalledWith(cover);

        await pick(container, [file('new.png')]);
        expect(engine.items.map((item) => item.name)).toEqual(['new.png']);
    });

    it('has no delete button when the app does not handle it', () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, file: { ...cover, type: 'document' } });

        expect(container.querySelector('.laracrate-icon-button.is-danger')).toBeNull();
        expect(container.querySelector('.laracrate-card .laracrate-icon svg')).not.toBeNull();
    });

    it('stages the file of a deferred one until Upload is pressed', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 's' }));
        const { container } = mount({ grant, deferred: true, locale: 'es' });

        await pick(container, [file('a.png')]);
        expect(grant).not.toHaveBeenCalled();
        expect(container.querySelector('.laracrate-label').textContent).toBe('Pendiente de subir');

        fireEvent.click(container.querySelector('.laracrate-button'));
        await settle();
        expect(engine.items.map((item) => item.name)).toEqual(['a.png']);
        expect(container.querySelector('.laracrate-label')).toBeNull();
        expect(container.querySelector('.laracrate-card.is-busy')).not.toBeNull();
    });

    it('drops a staged file on cancel', async () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, deferred: true });

        await pick(container, [file('a.png')]);
        fireEvent.click(container.querySelector('.laracrate-icon-button.is-danger'));
        await settle();

        expect(container.querySelector('.laracrate-drop')).not.toBeNull();
        expect(engine.items).toHaveLength(0);
    });

    it('shows a failed upload to retry or dismiss', async () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' } });

        await pick(container, [file('a.png')]);
        act(() => engine.fail(engine.items[0], 'Network'));
        await settle();
        expect(container.querySelector('.laracrate-meta .is-error').textContent).toBe('Network');

        fireEvent.click(container.querySelector('button[title=Retry]'));
        await settle();
        expect(container.querySelector('.laracrate-card.is-busy')).not.toBeNull();

        act(() => engine.fail(engine.items[0], 'Network'));
        await settle();
        fireEvent.click(container.querySelector('.laracrate-icon-button.is-danger'));
        await settle();
        expect(container.querySelector('.laracrate-drop')).not.toBeNull();
    });

    it('replaces the file again and again', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 's' }));
        const { container } = mount({ grant });

        for (const name of ['a.png', 'b.png']) {
            await pick(container, [file(name)]);
            act(() => engine.finish(engine.items.at(-1)));
            await settle();
            await act(async () => { await vi.advanceTimersByTimeAsync(1600); });
        }

        expect(engine.items.map((item) => item.name)).toEqual(['a.png', 'b.png']);
    });
});
