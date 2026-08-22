import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const { post, deleteFn, ask } = vi.hoisted(() => ({ post: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(() => Promise.resolve(true)) }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    // like Inertia's real form.post(url, options), the mock transmits the form's CURRENT field
    // values as the request body — otherwise a spec can assert a URL and call that "posts the
    // structured response" while never observing the response itself.
    useForm: (obj) => {
        const keys = Object.keys(obj);
        const form = { ...obj, put: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false };
        form.post = vi.fn((url, opts) => post(url, Object.fromEntries(keys.map((k) => [k, form[k]])), opts));

        return form;
    },
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const props = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
    reasons: [], consultants: [], specialties: [],
};
const mountWith = (user) => { authUser = user; return shallowMount(ConsultationsIndex, { props, global: { stubs: { teleport: true } } }); };

beforeEach(() => { post.mockClear(); deleteFn.mockClear(); ask.mockClear(); });

describe('Consultations/Index — Wave 2 Item 4', () => {
    // W2A: sign-off carries a REQUIRED structured response, so it opens a modal instead of posting
    // on click. What this case still pins is the Item-4 decision it was written for: sign-off never
    // routes through the destructive-confirm dialog (deleteConsult, below, still does).
    it('openSignoff opens the response modal and never calls ask()', () => {
        const vm = mountWith(admin).vm;
        vm.openSignoff({ id: 7, name: 'X', mrn: '1', status: 'active' });
        expect(vm.signingOff.id).toBe(7);
        expect(post).not.toHaveBeenCalled();
        expect(ask).not.toHaveBeenCalled();
    });

    it('submitting the sign-off form posts the structured response for that consultation', () => {
        const vm = mountWith(admin).vm;
        vm.openSignoff({ id: 7, name: 'X', mrn: '1', status: 'active' });
        vm.sForm.response_disposition = 'advice_given';
        vm.sForm.response_followup_needed = true;
        vm.sForm.response_note = 'Repeat echo in 6 weeks.';
        vm.submitSignoff();
        // the RESPONSE, not just the route: dropping any of the three fields must go red
        expect(post).toHaveBeenCalledWith(
            '/consultations/7/signoff',
            {
                response_disposition: 'advice_given',
                response_followup_needed: true,
                response_note: 'Repeat echo in 6 weeks.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('deleteConsult STILL confirms (danger) before deleting', async () => {
        const vm = mountWith(admin).vm;
        await vm.deleteConsult({ id: 7, name: 'X', mrn: '1' });
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(deleteFn).toHaveBeenCalledWith('/consultations/7', { preserveScroll: true });
    });
});
