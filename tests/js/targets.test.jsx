import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent } from '@testing-library/react';
import * as react from '../../resources/js/react/index.js';
import * as svelte from '../../resources/js/svelte/index.js';
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

const images = { accept: 'image/jpeg,image/png', extensions: ['jpg', 'png'], max_size_kb: 2048, kind: 'image' };
const avatar = {
    id: 7, slug: 'me', name: 'me.jpg', size: 4096, type: 'image', extension: 'jpg',
    url: '/me.jpg', preview: '/me-thumb.jpg', processing_status: 'completed',
};
const fade = () => act(async () => { await vi.advanceTimersByTimeAsync(1600); });

describe.each(['react', 'svelte'])('%s components with a target', (name) => {
    const frameworks = name === 'react' ? react : svelte;
    const dropzone = mounters(react.Dropzone, svelte.Dropzone)[name];
    const single = mounters(react.DropzoneSingle, svelte.DropzoneSingle)[name];
    const uploader = mounters(react.Uploader, svelte.Uploader)[name];

    it('reads the collection rules when it mounts, and asks for a grant only once files are picked', async () => {
        const server = fakeServer({ targets: { 'venue.gallery': (params) => String(params.venue) === '12' && { grant: 'g1', scope: 'sc', replace: false, rules: images, file: null } } });
        const { container } = dropzone({ target: 'venue.gallery', params: { venue: 12 } });
        await settle();

        expect(server.targetCalls('GET').map((call) => call.url)).toEqual(['/laracrate/uploads/targets/venue.gallery?params%5Bvenue%5D=12']);
        expect(server.targetCalls('POST')).toHaveLength(0);
        expect(container.querySelector('input[type=file]').getAttribute('accept')).toBe('image/jpeg,image/png');
        expect(container.querySelector('.laracrate-hint').textContent).toBe('Max. 2.0 MB');

        await pick(container, [file('a.png'), file('b.png')]);
        expect(server.targetCalls('POST').map((call) => call.body)).toEqual([{ params: { venue: 12 } }]);
        expect(engine.items.map((item) => [item.name, item.grant, item.scope])).toEqual([['a.png', 'g1', 'sc'], ['b.png', 'g1', 'sc']]);
    });

    it('does not upload a file removed while its grant is on its way', async () => {
        fakeServer({ targets: { 'venue.gallery': () => ({ grant: 'g', scope: 'sc', rules: images }) } });
        const { container } = dropzone({ target: 'venue.gallery', params: { venue: 1 }, deferred: true });
        await settle();

        await pick(container, [file('a.png'), file('b.png')]);
        fireEvent.click(container.querySelector('.laracrate-button'));
        fireEvent.click(container.querySelector('.laracrate-tile__remove'));
        await settle();

        expect(engine.items.map((item) => item.name)).toEqual(['b.png']);
    });

    it('shows why a target refuses, with nothing to upload', async () => {
        fakeServer({ targets: { 'venue.gallery': () => null } });
        const { container } = dropzone({ target: 'venue.gallery', params: { venue: 1 } });
        await settle();

        expect(container.querySelector('.laracrate-drop')).toBeNull();
        expect(container.querySelector('.laracrate-error').textContent).toBe('You cannot upload files here.');
    });

    it('asks again when the params change', async () => {
        const server = fakeServer({ targets: { 'venue.gallery': (params) => ({ grant: `g${params.venue}`, scope: `s${params.venue}`, rules: images }) } });
        const { container, update } = dropzone({ target: 'venue.gallery', params: { venue: 1 } });
        await settle();

        update({ params: { venue: 2 } });
        await settle();
        expect(server.targetCalls('GET').map((call) => call.url.split('?')[1])).toEqual(['params%5Bvenue%5D=1', 'params%5Bvenue%5D=2']);

        await pick(container, [file('a.png')]);
        expect(engine.items[0].grant).toBe('g2');
    });

    it('shows again the uploads still running for the target', async () => {
        fakeServer({ targets: { avatar: () => ({ grant: 'g', scope: 'sc', replace: true, rules: images, file: null }) } });
        engine.enqueue({ name: 'carried.png', size: 10, status: 'pending' }, { grant: 'g', scope: 'sc' });

        const { container } = uploader({ target: 'avatar' });
        await settle();

        expect(container.querySelector('.laracrate-uploader__card.is-busy .laracrate-name').textContent).toBe('carried.png');
    });

    it('shows the current file of a single file target, and its kind and rules', async () => {
        fakeServer({ targets: { 'venue.cover': () => ({ grant: 'g', scope: 'sc', replace: true, rules: images, file: null }) } });
        const { container } = single({ target: 'venue.cover', params: { venue: 3 } });
        await settle();

        expect(container.querySelector('.laracrate-hint').textContent).toBe('Max. 2.0 MB · JPG, PNG');
        expect(container.querySelector('.laracrate-drop .laracrate-icon svg')).not.toBeNull();
    });

    it('shows the file just uploaded without the app, and follows its processing', async () => {
        const uploaded = { ...avatar, id: 8, slug: 'new', name: 'new.png', url: '/new.png', preview: '/new.png', processing_status: 'pending' };
        const server = fakeServer({
            targets: { avatar: () => ({ grant: 'g', scope: 'sc', replace: true, rules: images, file: avatar }) },
            statuses: { new: [{ status: 'processing', url: null, preview: null }, { status: 'completed', url: '/new.png', preview: '/new-thumb.png' }] },
        });
        const { container } = uploader({ target: 'avatar', pollMs: 2000 });
        await settle();

        expect(container.querySelector('.laracrate-uploader__image').getAttribute('src')).toBe('/me-thumb.jpg');

        await pick(container, [file('new.png')]);
        act(() => engine.finish(engine.items[0], uploaded));
        await settle();
        await fade();

        expect(container.querySelector('.laracrate-name').textContent).toBe('new.png');
        expect(container.querySelector('.laracrate-pulse').textContent).toBe('processing');

        await act(async () => { await vi.advanceTimersByTimeAsync(4500); });
        expect(server.calls.filter((call) => call.url === '/laracrate/files/new/status')).toHaveLength(2);
        expect(container.querySelector('.laracrate-pulse')).toBeNull();
        expect(container.querySelector('.laracrate-uploader__image').getAttribute('src')).toBe('/new-thumb.png');
    });

    it('removes the current file with the target grant', async () => {
        const server = fakeServer({ targets: { avatar: () => ({ grant: 'g', scope: 'sc', replace: true, rules: images, file: avatar }) } });
        const removed = vi.fn();
        window.confirm = vi.fn(() => true);
        const { container } = uploader({ target: 'avatar', onRemoved: removed });
        await settle();

        fireEvent.click(container.querySelector('.laracrate-icon-button.is-danger'));
        await settle();

        expect(server.removed).toEqual(['g']);
        expect(removed).toHaveBeenCalledWith(avatar);
        expect(container.querySelector('.laracrate-uploader__drop')).not.toBeNull();
    });

    it('takes the current file from the app over the target one', async () => {
        fakeServer({ targets: { avatar: () => ({ grant: 'g', scope: 'sc', replace: true, rules: images, file: avatar }) } });
        const { container } = single({ target: 'avatar', file: null });
        await settle();

        expect(container.querySelector('.laracrate-drop')).not.toBeNull();
        expect(frameworks.configure).toBeTypeOf('function');
    });
});
