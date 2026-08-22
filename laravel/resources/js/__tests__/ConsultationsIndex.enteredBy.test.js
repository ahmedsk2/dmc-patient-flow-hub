import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 2b — who TYPED the record vs who OWNS it. entered_by is the trustworthy field (session-
// sourced, never settable from a payload) and must be visible next to the owning consultant.

const { post, put, deleteFn, ask } = vi.hoisted(() => ({
    post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => reactive({
        ...obj, errors: {}, processing: false,
        post: vi.fn((...a) => post(...a)),
        put: vi.fn((...a) => put(...a)),
        reset: vi.fn(), clearErrors: vi.fn(),
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const consult = {
    id: 31, name: 'Attribution Pt', mrn: '72000001', age: 47, bed: 'W-5', location: 'Ward',
    from: 'ER', to: 'Cardiology', consultant: 'Dr Owner', consultant_id: 5,
    entered_by: 'Dr Typist', entered_by_id: 6, date: '2026-08-21', signoff: null,
    reasons: [], indication_ids: [], other: '',
};
const props = {
    consultations: { data: [consult], total: 1, last_page: 1, links: [] },
    filters: {}, stats: { active: 1, total: 1, mine_active: 0 },
    reasons: [], consultants: [], specialties: [],
    worklist: { date: '2026-08-21', seen: 0, total: 0, items: [] },
};
const mountWith = () => { authUser = admin; return mount(ConsultationsIndex, { props }); };

beforeEach(() => { post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Consultations/Index — entered_by is shown apart from the owner', () => {
    it('renders the typist under the owning consultant in the row', () => {
        const w = mountWith();
        const cell = w.get('[data-test="attribution-31"]');
        expect(cell.text()).toContain('Dr Owner');
        expect(cell.text()).toContain('Entered by Dr Typist');
    });

    it('repeats the attribution inside the edit modal', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        await w.vm.$nextTick();
        const banner = w.get('[data-test="edit-attribution"]');
        expect(banner.text()).toContain('Entered by Dr Typist');
        expect(banner.text()).toContain('Dr Owner');
    });
});
