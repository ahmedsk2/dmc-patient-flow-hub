import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// Consultation ledger W0: the delete confirmation used to promise a permanent, irreversible
// deletion. It is a SOFT delete an admin restores from Recently Deleted, so the copy must say so.
const { post, deleteFn, ask } = vi.hoisted(() => ({ post: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(() => Promise.resolve(true)) }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), put: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const props = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    // W2A stats shape — the server ships the four per-status counts plus open/mine_open/total
    filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
    reasons: [], consultants: [], specialties: [],
};
const mountWith = (user) => { authUser = user; return shallowMount(ConsultationsIndex, { props, global: { stubs: { teleport: true } } }); };

beforeEach(() => { post.mockClear(); deleteFn.mockClear(); ask.mockClear(); });

describe('Consultations/Index — W0 delete confirmation copy', () => {
    it('does not claim the delete is permanent or irreversible', async () => {
        const vm = mountWith(admin).vm;
        await vm.deleteConsult({ id: 9, name: 'Ada Patient', mrn: '55501', date: '2026-08-20' });

        const body = ask.mock.calls[0][1];
        expect(body).not.toMatch(/permanent/i);
        expect(body).not.toMatch(/cannot be undone/i);
        expect(body).not.toMatch(/erased|destroyed/i);
    });

    it('tells the user an administrator can restore it from Recently Deleted', async () => {
        const vm = mountWith(admin).vm;
        await vm.deleteConsult({ id: 9, name: 'Ada Patient', mrn: '55501', date: '2026-08-20' });

        const [title, body, tone] = ask.mock.calls[0];
        expect(title).toBe('Remove consultation');
        expect(body).toContain('Ada Patient');
        expect(body).toContain('55501');
        expect(body).toContain('ledger');
        expect(body).toContain('restore');
        expect(body).toContain('Recently Deleted');
        expect(tone).toBe('danger');   // still a guarded action, just an honest one
    });

    it('still deletes only after the confirmation resolves true', async () => {
        const vm = mountWith(admin).vm;
        await vm.deleteConsult({ id: 9, name: 'Ada Patient', mrn: '55501', date: '2026-08-20' });
        expect(deleteFn).toHaveBeenCalledWith('/consultations/9', { preserveScroll: true });

        ask.mockResolvedValueOnce(false);
        deleteFn.mockClear();
        await vm.deleteConsult({ id: 10, name: 'Bob Patient', mrn: '55502', date: '2026-08-20' });
        expect(deleteFn).not.toHaveBeenCalled();
    });
});
