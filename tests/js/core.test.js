import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createQueue } from '../../resources/js/core/queue.js';
import { appearance, styleString } from '../../resources/js/core/appearance.js';
import { fill, labelsFor } from '../../resources/js/core/labels.js';
import { useEngine } from '../../resources/js/core/engine.js';
import { fakeEngine, file, tick } from './fakeEngine.js';

let engine;

beforeEach(() => {
    engine = fakeEngine();
    useEngine(engine);
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
});

afterEach(() => useEngine(null));

const queue = (options = {}) => {
    const q = createQueue({ grant: { grant: 'token', scope: 'user:1:avatar' }, persistQueue: true, ...options });
    q.start();
    return q;
};

describe('the upload queue of a component', () => {
    it('hands the files picked to the page queue with the grant the app gave', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 'case:9:documents' }));
        const uploaded = vi.fn();
        const completed = vi.fn();
        const q = queue({ grant, onUploaded: uploaded, onBatchCompleted: completed });

        q.handleFiles([file('a.png'), file('b.pdf', 'application/pdf')]);
        await tick();

        expect(grant).toHaveBeenCalledWith([expect.any(File), expect.any(File)]);
        expect(engine.items.map((item) => [item.name, item.grant, item.scope])).toEqual([
            ['a.png', 'token', 'case:9:documents'],
            ['b.pdf', 'token', 'case:9:documents'],
        ]);

        engine.items.forEach((item) => engine.finish(item));
        await tick();

        expect(uploaded).toHaveBeenCalledTimes(2);
        expect(uploaded.mock.calls[0][0]).toMatchObject({ slug: 'file-1' });
        expect(completed).toHaveBeenCalledWith({ ok: 2, error: 0 });
    });

    it('stages the files of a deferred component until the batch starts', async () => {
        const grant = vi.fn(async () => ({ grant: 'token', scope: 's' }));
        const q = queue({ grant, autoStart: false });

        q.handleFiles([file('a.png')]);
        await tick();
        expect(grant).not.toHaveBeenCalled();
        expect(q.snapshot().stagedCount).toBe(1);

        q.startBatch();
        await tick();
        expect(grant).toHaveBeenCalledTimes(1);
        expect(engine.items).toHaveLength(1);
    });

    it('takes no more files than maxFiles', async () => {
        const maxed = vi.fn();
        const q = queue({ maxFiles: 2, autoStart: false, onMaxFiles: maxed });

        q.handleFiles([file('a.png'), file('b.png'), file('c.png')]);

        expect(q.snapshot().queue.map((item) => item.name)).toEqual(['a.png', 'b.png']);
        expect(q.snapshot().reachedMax).toBe(true);
        expect(maxed).toHaveBeenCalledWith({ max: 2, rejected: 1 });
    });

    it('lists the files as refused when the app gives no grant', async () => {
        const rejected = vi.fn();
        const q = queue({ grant: async () => { throw new Error('Not allowed here'); }, onRejected: rejected });

        q.handleFiles([file('a.png')]);
        await tick();

        expect(q.snapshot().queue[0]).toMatchObject({ status: 'error', error: 'Not allowed here', retryable: false });
        expect(rejected).toHaveBeenCalledTimes(1);
    });

    it('shows again the uploads still going for its target when mounted again', async () => {
        const first = queue();
        first.handleFiles([file('a.png')]);
        await tick();
        first.destroy();

        const again = queue();
        await tick();

        expect(again.snapshot().queue.map((item) => item.name)).toEqual(['a.png']);
        expect(again.snapshot().uploading).toBe(true);
    });

    it('retries a failed upload and releases a removed one', async () => {
        const q = queue();
        q.handleFiles([file('a.png')]);
        await tick();
        const [item] = engine.items;
        engine.fail(item, 'The connection was lost.');

        q.retry(q.key(item));
        expect(item.status).toBe('pending');

        q.remove(q.key(item));
        expect(q.snapshot().queue).toHaveLength(0);
        expect(engine.items).toHaveLength(0);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview');
    });

    it('renders copies, so a framework sees each change', async () => {
        const q = queue();
        q.handleFiles([file('a.png')]);
        await tick();

        const before = q.snapshot();
        engine.progress(engine.items[0], 500);

        expect(before.queue[0].status).toBe('pending');
        expect(q.snapshot().queue[0]).toMatchObject({ status: 'uploading', loaded: 500 });
        expect(q.snapshot().batchProgress).toBe(50);
    });
});

describe('the look of a component', () => {
    it('turns the appearance props into the same variables and classes as Livewire', () => {
        const look = appearance({ theme: 'neon', rounded: 'none', bordered: false, shadow: false, size: 'sm', className: 'mine' });

        expect(look.theme).toBe('neon');
        expect(look.classes).toBe('is-sm mine');
        expect(look.vars['--wire-laracrate-radius']).toBe('0');
        expect(look.vars['--wire-laracrate-border-width']).toBe('0');
        expect(look.vars['--wire-laracrate-drop-shadow']).toBe('none');
        expect(styleString({ '--wire-laracrate-radius': '0' }, '--wire-accent: red')).toBe('--wire-laracrate-radius: 0; --wire-accent: red');
    });

    it('rounds only the preview of an uploader', () => {
        expect(appearance({ rounded: 'full' }, '--wire-laracrate-preview-radius').vars).toEqual({ '--wire-laracrate-preview-radius': '9999px' });
    });

    it('speaks the locale asked for, with the app texts on top', () => {
        const labels = labelsFor('es', { submit: 'Enviar' });

        expect(labels.cancel).toBe('Cancelar');
        expect(labels.submit).toBe('Enviar');
        expect(fill(labels.max_size_capital, { size: '2.0' })).toBe('Máx. 2.0 MB');
    });
});
