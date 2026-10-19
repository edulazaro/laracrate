import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent } from '@testing-library/react';
import { Uploader as ReactUploader } from '../../resources/js/react/index.js';
import { Uploader as SvelteUploader } from '../../resources/js/svelte/index.js';
import { useEngine } from '../../resources/js/core/engine.js';
import { fakeEngine, file } from './fakeEngine.js';
import { fakeServer } from './fakeServer.js';
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

const frameworks = mounters(ReactUploader, SvelteUploader);
const avatar = { url: 'https://cdn.test/me.jpg', preview: 'https://cdn.test/me-thumb.jpg', original_name: 'me.jpg', size: 4096 };

describe.each(Object.keys(frameworks))('the %s Uploader', (name) => {
    const mount = frameworks[name];

    it('shows the drop area in its layout', () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, layout: 'portrait', maxSizeKb: 1024 });
        const root = container.querySelector('.laracrate.laracrate-uploader');

        expect(root.classList.contains('is-portrait')).toBe(true);
        expect(container.querySelector('.laracrate-uploader__drop .laracrate-title').textContent).toBe('Upload file');
        expect(container.querySelector('.laracrate-uploader__choose').textContent).toBe('Choose');
        expect(container.querySelector('.laracrate-hint').textContent).toBe('Max. 1.0 MB');
    });

    it('rounds the preview, not the card', () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, rounded: 'full' });
        const root = container.querySelector('.laracrate-uploader');

        expect(root.classList.contains('is-row')).toBe(true);
        expect(root.style.getPropertyValue('--wire-laracrate-preview-radius')).toBe('9999px');
        expect(root.style.getPropertyValue('--wire-laracrate-radius')).toBe('');
    });

    it('uploads the file picked with its local preview, then shows the one the app passes', async () => {
        const uploaded = vi.fn();
        const { container, update } = mount({ grant: { grant: 'token', scope: 'user:1:avatar' }, onUploaded: uploaded });

        await pick(container, [file('me.png', 'image/png', 3072)]);
        expect(engine.items.map((item) => [item.name, item.scope])).toEqual([['me.png', 'user:1:avatar']]);
        expect(container.querySelector('.laracrate-uploader__card.is-busy .laracrate-uploader__image').getAttribute('src')).toBe('blob:preview');
        expect(container.querySelector('.laracrate-meta').textContent).toContain('3 KB');

        act(() => engine.progress(engine.items[0], 1536));
        await settle();
        expect(container.querySelector('.laracrate-pulse').textContent).toBe('uploading... 50%');

        act(() => engine.finish(engine.items[0]));
        await settle();
        expect(uploaded).toHaveBeenCalledTimes(1);
        expect(container.querySelector('.laracrate-pulse').textContent).toBe('uploading... 100%');

        update({ file: avatar });
        await act(async () => { await vi.advanceTimersByTimeAsync(1600); });
        expect(container.querySelector('.is-busy')).toBeNull();
        expect(container.querySelector('.laracrate-uploader__image').getAttribute('src')).toBe(avatar.preview);
        expect(container.querySelector('.laracrate-name').textContent).toBe('me.jpg');
    });

    it('shows the current file with its processing state, to replace or remove', async () => {
        const server = fakeServer();
        window.confirm = vi.fn(() => false);
        const { container, update } = mount({
            grant: { grant: 'token', scope: 's' }, file: { ...avatar, processing_status: 'processing' },
        });

        expect(container.querySelector('.laracrate-meta').textContent).toBe('4 KB · processing');
        expect(container.querySelector('.laracrate-uploader__replace').textContent).toBe('Replace');

        fireEvent.click(container.querySelector('.laracrate-icon-button.is-danger'));
        await settle();
        expect(window.confirm).toHaveBeenCalled();
        expect(server.removed).toEqual([]);

        update({ file: { ...avatar, processing_status: 'failed' } });
        await settle();
        expect(container.querySelector('.laracrate-meta .is-error').textContent).toBe('error');

        fireEvent.drop(container.querySelector('.laracrate-uploader__card'), { dataTransfer: { files: [file('new.png')] } });
        await settle();
        expect(engine.items.map((item) => item.name)).toEqual(['new.png']);
    });

    it('shows a document with no image as a placeholder', () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, file: { url: '/cv.pdf', name: 'cv.pdf', size: 1024, type: 'document' }, removable: false });

        expect(container.querySelector('.laracrate-uploader__image')).toBeNull();
        expect(container.querySelector('.laracrate-uploader__placeholder svg')).not.toBeNull();
        expect(container.querySelector('.laracrate-icon-button.is-danger')).toBeNull();
    });

    it('stages the file of a deferred one until Upload is pressed', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 's' }));
        const { container } = mount({ grant, deferred: true });

        expect(container.querySelector('.laracrate-title').textContent).toBe('Select file');

        await pick(container, [file('me.png')]);
        expect(grant).not.toHaveBeenCalled();
        expect(container.querySelector('.laracrate-uploader__card.is-staged .laracrate-label').textContent).toBe('Pending upload');

        fireEvent.click(container.querySelector('.laracrate-button'));
        await settle();
        expect(grant).toHaveBeenCalledTimes(1);
        expect(container.querySelector('.is-staged')).toBeNull();
        expect(container.querySelector('.is-busy')).not.toBeNull();
    });

    it('cancels a staged file', async () => {
        const { container } = mount({ grant: { grant: 'token', scope: 's' }, deferred: true });

        await pick(container, [file('me.png')]);
        fireEvent.click(container.querySelector('.laracrate-uploader__actions .laracrate-icon-button'));
        await settle();

        expect(container.querySelector('.laracrate-uploader__drop')).not.toBeNull();
    });

    it('shows a refusal under it, and takes another file', async () => {
        const grant = vi.fn()
            .mockRejectedValueOnce(new Error('Too big for this collection.'))
            .mockResolvedValue({ grant: 'token', scope: 's' });
        const { container } = mount({ grant });

        await pick(container, [file('huge.png')]);
        expect(container.querySelector('.laracrate-error').textContent).toBe('Too big for this collection.');
        expect(container.querySelector('.laracrate-uploader__drop')).not.toBeNull();

        await pick(container, [file('small.png')]);
        expect(container.querySelector('.laracrate-error')).toBeNull();
        expect(engine.items.at(-1).name).toBe('small.png');
    });
});
