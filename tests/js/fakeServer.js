import { vi } from 'vitest';

const reply = (status, data) => ({ ok: status < 400, status, json: async () => data });

/** ?params[venue]=12 back to { venue: '12' }: strings, as the server gets them. */
const fromQuery = (search = '') => Object.fromEntries([...new URLSearchParams(search)]
    .map(([key, value]) => [key.replace(/^params\[(.*)\]$/, '$1'), value]));

/**
 * A stand-in for the Laracrate endpoints the components call with fetch: a target
 * (what to show, and its grant), removing the current file, and a file's status.
 * `targets` answers each target by name from its params, as the query string or the
 * body sends them (null refuses it): the read gets all but the grant, the grant its
 * grant and scope. `statuses` lists what each slug's status answers, one per call, the
 * last one from then on.
 */
export function fakeServer({ targets = {}, statuses = {} } = {}) {
    const server = { calls: [], removed: [] };

    globalThis.fetch = vi.fn(async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : undefined;
        server.calls.push({ method: init.method, url, body });
        let match;

        if ((match = url.match(/^\/laracrate\/uploads\/targets\/([^?]+)(?:\?(.*))?$/))) {
            const answer = targets[decodeURIComponent(match[1])];
            if (!answer) return reply(404, { message: 'Not Found' });

            const params = init.method === 'GET' ? fromQuery(match[2]) : body.params;
            const data = answer(params);
            if (!data) return reply(403, { message: 'You cannot upload files here.' });

            const { grant, ...shown } = data;

            return reply(200, init.method === 'GET' ? shown : { grant, scope: data.scope });
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

    server.targetCalls = (method) => server.calls.filter((call) => call.url.startsWith('/laracrate/uploads/targets/') && call.method === method);

    return server;
}
