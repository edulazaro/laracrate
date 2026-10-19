import { vi } from 'vitest';

const reply = (status, data) => ({ ok: status < 400, status, json: async () => data });

/**
 * A stand-in for the Laracrate endpoints the components call with fetch: a target's
 * grant, removing the current file, and a file's status. `targets` answers each
 * target by name from its params (null refuses it); `statuses` lists what each slug's
 * status answers, one per call, the last one from then on.
 */
export function fakeServer({ targets = {}, statuses = {} } = {}) {
    const server = { calls: [], removed: [] };

    globalThis.fetch = vi.fn(async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : undefined;
        server.calls.push({ method: init.method, url, body });
        let match;

        if ((match = url.match(/^\/laracrate\/uploads\/targets\/(.+)$/))) {
            const answer = targets[decodeURIComponent(match[1])];
            if (!answer) return reply(404, { message: 'Not Found' });
            const data = answer(body.params);

            return data ? reply(200, data) : reply(403, { message: 'You cannot upload files here.' });
        }

        if (url === '/laracrate/uploads/remove') {
            server.removed.push(body.grant);

            return reply(200, { deleted: true });
        }

        if ((match = url.match(/^\/laracrate\/files\/(.+)\/status$/))) {
            const list = statuses[match[1]] ?? [];

            return reply(200, list.length > 1 ? list.shift() : list[0]);
        }

        return reply(404, {});
    });

    server.targetCalls = () => server.calls.filter((call) => call.url.startsWith('/laracrate/uploads/targets/'));

    return server;
}
