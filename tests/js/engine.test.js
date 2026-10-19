import { expect, it } from 'vitest';
import { loadEngine } from '../../resources/js/core/engine.js';

it('loads the page upload queue once, from the same script the Blade components print', async () => {
    const first = await loadEngine();
    const second = await loadEngine();

    expect(first).toBe(window.Laracrate.uploads);
    expect(second).toBe(first);
    expect(typeof first.enqueue).toBe('function');
});
