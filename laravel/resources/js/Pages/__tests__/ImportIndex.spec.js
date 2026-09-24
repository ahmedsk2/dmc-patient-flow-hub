import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Import/Index — Problem #19 (2026-09-24 role/UX review): committing a bulk historical import had
 * no confirmation step at all, unlike every other high-impact admin action. doImport() must now
 * ask via the themed ConfirmDialog (useConfirm), stating how many rows will actually be written,
 * and post to /import only once confirmed.
 */
const { post, reset, ask } = vi.hoisted(() => ({ post: vi.fn(), reset: vi.fn(), ask: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => ({ ...obj, post, reset, errors: {}, processing: false }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));

import ImportIndex from '@/Pages/Import/Index.vue';

const columns = ['MRN', 'Name', 'Age', 'Gender', 'Nationality', 'AdmitDate', 'DischargeDate', 'Outcome', 'Location'];

beforeEach(() => { post.mockClear(); reset.mockClear(); ask.mockReset(); });

describe('Import/Index — confirm before commit (Problem #19)', () => {
    it('a truncated (>200-row) preview asks with the server-reported valid count, and posts once confirmed', async () => {
        const preview = { valid: 3, invalid: 1, truncated: true, sample: [] };
        const w = mount(ImportIndex, { props: { columns, rows: 'x', preview } });
        ask.mockResolvedValue(true);

        await w.vm.doImport();
        await flushPromises();

        expect(ask).toHaveBeenCalledTimes(1);
        const [title, body, tone] = ask.mock.calls[0];
        expect(title).toMatch(/confirm/i);
        expect(body).toContain('3');
        expect(tone).toBe('danger');
        expect(post).toHaveBeenCalledWith('/import', expect.objectContaining({ preserveScroll: true }));
    });

    it('declining the confirm does not post', async () => {
        const preview = { valid: 3, invalid: 1, truncated: true, sample: [] };
        const w = mount(ImportIndex, { props: { columns, rows: 'x', preview } });
        ask.mockResolvedValue(false);

        await w.vm.doImport();
        await flushPromises();

        expect(post).not.toHaveBeenCalled();
    });

    it('an editable (full) preview asks with the count of rows still marked OK, not the raw row total', async () => {
        const preview = {
            valid: 2, invalid: 1, truncated: false,
            sample: [
                { line: 1, ok: true, mrn: '111', admit_date: '2024-01-01' },
                { line: 2, ok: true, mrn: '112', admit_date: '2024-01-02' },
                { line: 3, ok: false, mrn: '', error: 'MRN must be 1–11 digits' },
            ],
        };
        const w = mount(ImportIndex, { props: { columns, rows: 'x', preview } });
        ask.mockResolvedValue(true);

        await w.vm.doImport();
        await flushPromises();

        expect(ask.mock.calls[0][1]).toContain('2');
        expect(post).toHaveBeenCalledTimes(1);
    });
});
