import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useHandover } from '@/composables/useHandover';

beforeEach(() => {
    // a decodable XSRF cookie so xsrf() returns a value
    document.cookie = 'XSRF-TOKEN=' + encodeURIComponent('tok123');
    global.fetch = vi.fn();
});
afterEach(() => {
    vi.restoreAllMocks();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useHandover', () => {
    it('saveHandover POSTs the admission handover URL with xsrf header + body, returns ok', async () => {
        global.fetch.mockResolvedValue({ ok: true });
        const { saveHandover, saving } = useHandover();
        const ok = await saveHandover(42, 'note text');
        expect(ok).toBe(true);
        expect(saving.value).toBe(false);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/admissions/42/handover');
        expect(opts.method).toBe('POST');
        expect(opts.headers['X-XSRF-TOKEN']).toBe('tok123');
        expect(JSON.parse(opts.body)).toEqual({ body: 'note text' });
    });

    it('saveHandover returns false on a non-ok response', async () => {
        global.fetch.mockResolvedValue({ ok: false });
        const { saveHandover } = useHandover();
        expect(await saveHandover(1, 'x')).toBe(false);
    });

    it('saveHandover posts checkpoints in the body when provided', async () => {
        global.fetch.mockResolvedValue({ ok: true });
        const { saveHandover } = useHandover();
        const checkpoints = { vte_completed: true, high_risk: false, code_status: 'dnr' };
        await saveHandover(42, 'note text', checkpoints);
        const [, opts] = global.fetch.mock.calls[0];
        expect(JSON.parse(opts.body)).toEqual({ body: 'note text', checkpoints });
    });

    it('saveHandover omits the checkpoints key when the arg is not provided', async () => {
        global.fetch.mockResolvedValue({ ok: true });
        const { saveHandover } = useHandover();
        await saveHandover(42, 'note text');
        const [, opts] = global.fetch.mock.calls[0];
        const parsed = JSON.parse(opts.body);
        expect(parsed).toEqual({ body: 'note text' });
        expect(parsed).not.toHaveProperty('checkpoints');
    });

    it('fetchHandover GETs the handover URL and returns parsed JSON', async () => {
        global.fetch.mockResolvedValue({ json: () => Promise.resolve({ body: 'hi', today: true }) });
        const { fetchHandover } = useHandover();
        const data = await fetchHandover(7);
        expect(global.fetch.mock.calls[0][0]).toBe('/admissions/7/handover');
        expect(data).toEqual({ body: 'hi', today: true });
    });

    it('preflight GETs the consultant preflight URL and returns parsed rows', async () => {
        global.fetch.mockResolvedValue({ json: () => Promise.resolve([{ id: 1 }]) });
        const { preflight } = useHandover();
        const rows = await preflight(9);
        expect(global.fetch.mock.calls[0][0]).toBe('/handovers/preflight?from_consultant_id=9');
        expect(rows).toEqual([{ id: 1 }]);
    });

    it('saving toggles true during the request and back to false after', async () => {
        let resolve;
        global.fetch.mockReturnValue(new Promise((r) => { resolve = r; }));
        const { saveHandover, saving } = useHandover();
        const p = saveHandover(1, 'x');
        expect(saving.value).toBe(true);
        resolve({ ok: true });
        await p;
        expect(saving.value).toBe(false);
    });
});
