import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const { post, ask } = vi.hoisted(() => ({ post: vi.fn(), ask: vi.fn(() => Promise.resolve(true)) }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { template: '<div />' } }));

import AdmissionsIndex from '@/Pages/Admissions/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { assign: true, add: true, manage: true, modify: true } };
const props = { queue: [], icuPatients: [], consultants: [], countries: [] };
const mountWith = (user) => { authUser = user; return shallowMount(AdmissionsIndex, { props, global: { stubs: { teleport: true } } }); };

beforeEach(() => { post.mockClear(); ask.mockClear(); });

describe('Admissions/Index — Wave 2', () => {
    it('Item 4: fromIcu posts immediately and never calls ask()', () => {
        const vm = mountWith(admin).vm;
        vm.fromIcu({ id: 9, name: 'X', mrn: '1' });
        expect(post).toHaveBeenCalledWith('/admissions/9/icu-pull', {}, expect.objectContaining({ preserveScroll: true }));
        expect(ask).not.toHaveBeenCalled();
    });

    it('Item 5: openAssign defaults mark_new to false (established G1 queue default; unify-to-true gated on owner sign-off)', () => {
        const vm = mountWith(admin).vm;
        vm.openAssign({ id: 3, name: 'X', mrn: '1' });
        expect(vm.aForm.mark_new).toBe(false);
    });

    it('Item 5: the assign useForm default is mark_new:false', () => {
        const vm = mountWith(admin).vm;
        // openAssign hasn't run yet for a fresh form — the default itself is false
        expect(vm.aForm.mark_new).toBe(false);
    });
});
