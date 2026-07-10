import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 3 additions to Consultations/Index.vue: the unsaved-changes guard (dirty prop + guarded
// Cancel), the ErrorSummary wiring (form.errors mapped onto per-field ids), and the double-submit
// guard. A FULL mount (not shallow) so BaseModal's slot content — the actual form fields and the
// ErrorSummary — is inspectable; BaseModal itself is stubbed to a slot-rendering shell (its own
// behavior is covered by BaseModal.spec.js).

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
const props = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 }, reasons: [], consultants: [], specialties: [],
};
const mountWith = () => { authUser = admin; return mount(ConsultationsIndex, { props }); };

beforeEach(() => { post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Consultations/Index — new-consultation modal: dirty guard + ErrorSummary', () => {
    it('clean form: Cancel closes immediately, no ask()', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();
        expect(w.vm.cForm.isDirty).toBeFalsy();
        w.vm.closeAdd();
        await w.vm.$nextTick();
        expect(ask).not.toHaveBeenCalled();
        expect(w.vm.showAdd).toBe(false);
    });

    it('dirty form: Cancel asks (danger) before closing', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeAdd();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.vm.showAdd).toBe(false);
    });

    it('dirty form: declining keeps the modal open', async () => {
        ask.mockResolvedValue(false);
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeAdd();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(w.vm.showAdd).toBe(true);
    });

    it('renders no ErrorSummary until the form has errors, then maps errors onto real field ids', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();
        expect(w.find('[role="alert"]').exists()).toBe(false);

        w.vm.cForm.errors = { mrn: 'Enter an MRN using digits only' };
        await w.vm.$nextTick();
        const alert = w.get('[role="alert"]');
        const href = alert.get('a').attributes('href').slice(1);
        const target = w.get(`#${href}`);
        expect(target.attributes('aria-describedby')).toBe(`${href}-err`);
    });

    it('double-submit guard: submitAdd no-ops while cForm.processing is true', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.mrn = '111';
        w.vm.cForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitAdd();
        expect(post).not.toHaveBeenCalled();
    });

    it('the Create button is disabled while processing', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.processing = true;
        await w.vm.$nextTick();
        const btn = w.findAll('button').find((b) => b.text() === 'Create consultation');
        expect(btn.attributes('disabled')).toBeDefined();
    });
});

describe('Consultations/Index — edit-consultation modal: dirty guard + ErrorSummary', () => {
    const consult = { id: 7, mrn: '111', name: 'Ali', age: 40, bed: '5A', location: 'Ward', date: '2026-06-01', from: 'ER', to: 'Cardio', consultant_id: 5, indication_ids: [], other: '' };

    it('clean form: Cancel closes immediately, no ask()', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        await w.vm.$nextTick();
        w.vm.closeEdit();
        await w.vm.$nextTick();
        expect(ask).not.toHaveBeenCalled();
        expect(w.vm.editing).toBe(null);
    });

    it('dirty form: Cancel asks before closing', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        w.vm.openEdit(consult);
        w.vm.eForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeEdit();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(w.vm.editing).toBe(null);
    });

    it('maps eForm.errors onto ids that resolve to real inputs', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        w.vm.eForm.errors = { bed: 'Enter a bed' };
        await w.vm.$nextTick();
        const alert = w.get('[role="alert"]');
        const href = alert.get('a').attributes('href').slice(1);
        expect(w.get(`#${href}`).element.tagName).toBe('INPUT');
    });

    it('double-submit guard: submitEdit no-ops while eForm.processing is true', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        w.vm.eForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitEdit();
        expect(put).not.toHaveBeenCalled();
    });
});

describe('Consultations/Index — deleteConsult copy (Wave 3, Item 6)', () => {
    it('states patient name + MRN and the exact effect', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        await w.vm.deleteConsult({ id: 7, name: 'Ali', mrn: '111', date: '2026-06-01' });
        const [title, body, tone] = ask.mock.calls[0];
        expect(title).toMatch(/delete/i);
        expect(body).toContain('Ali');
        expect(body).toContain('111');
        expect(body).toMatch(/cannot be undone/i);
        expect(tone).toBe('danger');
    });
});
