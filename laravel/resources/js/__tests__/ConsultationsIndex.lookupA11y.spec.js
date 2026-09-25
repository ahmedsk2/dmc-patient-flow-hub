import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Role walkthrough 2026-09-25 — two fixes to the "Find the patient" lookup inside the New
// Consultation modal:
//   • Typeahead a11y: the input declared role="combobox" aria-autocomplete="list" but the
//     suggestions were a plain <ul><li><button> — no role="listbox"/"option", so a screen reader
//     announced nothing. Mirrors IcdTypeahead.vue's pattern (role="option" on the clickable row,
//     role="presentation" on the <li>, aria-activedescendant tracking the arrow-key highlight).
//   • U8: picking a patient now prefills "From service" from their current consultant's specialty
//     when that match is unambiguous, instead of leaving a field the app already knows blank.

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
const cardio = { id: 5, name: 'Cardiology', is_external: false };
const nephro = { id: 6, name: 'Nephrology', is_external: false };
const baseProps = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
    reasons: [], specialties: [cardio, nephro],
    consultants: [
        { id: 5, name: 'Dr Cardio', specialty_id: 5, on_service: true },
        { id: 6, name: 'Dr Neph', specialty_id: 6, on_service: true },
    ],
    worklist: { date: '2026-09-25', seen: 0, total: 0, items: [] },
};
const rowA = { id: 501, patient_id: 9012, mrn: '40020001', name: 'A Patient', age: 63, location: 'Ward', bed: 'W-12', consultant: 'Dr Cardio' };
const rowB = { id: 502, patient_id: 9013, mrn: '40020002', name: 'B Patient', age: 40, location: 'ICU', bed: 'I-1', consultant: 'Dr Neph' };
const mountWith = (extra = {}) => { authUser = admin; return mount(ConsultationsIndex, { props: { ...baseProps, ...extra } }); };

beforeEach(() => {
    post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset();
    global.fetch = vi.fn();
});

const lookupInput = (w) => w.get('input[role="combobox"]');
const options = (w) => w.findAll('[role="option"]');

describe('Consultations/Index — "Find the patient" combobox a11y', () => {
    it('the input declares combobox/listbox wiring, and each row is role="option" inside role="presentation"', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupResults = [rowA, rowB];
        await w.vm.$nextTick();

        const input = lookupInput(w);
        expect(input.attributes('aria-autocomplete')).toBe('list');
        expect(input.attributes('aria-expanded')).toBe('true');
        const listbox = w.find('[role="listbox"]');
        expect(listbox.exists()).toBe(true);
        expect(input.attributes('aria-controls')).toBe(listbox.attributes('id'));

        expect(options(w)).toHaveLength(2);
        expect(w.findAll('li[role="presentation"]')).toHaveLength(2);
        // no button nested inside the option — the option itself is the clickable element
        expect(options(w)[0].find('button').exists()).toBe(false);
    });

    it('no results: aria-expanded is false and there is no listbox', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();

        expect(lookupInput(w).attributes('aria-expanded')).toBe('false');
        expect(w.find('[role="listbox"]').exists()).toBe(false);
    });

    it('ArrowDown/ArrowUp move the highlight, reflected in aria-selected and aria-activedescendant', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupResults = [rowA, rowB];
        await w.vm.$nextTick();
        expect(w.vm.lookupHi).toBe(0);   // first result highlighted as soon as results land

        await lookupInput(w).trigger('keydown', { key: 'ArrowDown' });
        expect(w.vm.lookupHi).toBe(1);
        expect(options(w)[1].attributes('aria-selected')).toBe('true');
        expect(lookupInput(w).attributes('aria-activedescendant')).toBe(options(w)[1].attributes('id'));

        await lookupInput(w).trigger('keydown', { key: 'ArrowDown' });   // one past the end — stays put
        expect(w.vm.lookupHi).toBe(1);

        await lookupInput(w).trigger('keydown', { key: 'ArrowUp' });
        expect(w.vm.lookupHi).toBe(0);
    });

    it('Enter picks the highlighted row', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupResults = [rowA, rowB];
        await w.vm.$nextTick();

        await lookupInput(w).trigger('keydown', { key: 'ArrowDown' });   // highlight B
        await lookupInput(w).trigger('keydown', { key: 'Enter' });
        await w.vm.$nextTick();

        expect(w.vm.cForm.mrn).toBe('40020002');
        expect(w.vm.lookupResults).toEqual([]);
    });

    it('mousedown on an option picks it without the input losing focus first', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupResults = [rowA];
        await w.vm.$nextTick();

        await options(w)[0].trigger('mousedown');
        await w.vm.$nextTick();

        expect(w.vm.cForm.mrn).toBe('40020001');
    });

    it('the first Escape closes the list without touching the query', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupQuery = 'Pati';
        w.vm.lookupResults = [rowA];
        await w.vm.$nextTick();

        await lookupInput(w).trigger('keydown', { key: 'Escape' });
        await w.vm.$nextTick();

        expect(w.vm.lookupResults).toEqual([]);
        expect(w.vm.lookupQuery).toBe('Pati');
    });

    it('blur closes the list', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupResults = [rowA];
        await w.vm.$nextTick();

        await lookupInput(w).trigger('blur');
        expect(w.vm.lookupResults).toEqual([]);
    });
});

describe('Consultations/Index — "From service" prefill on pick (U8)', () => {
    it('prefills the referring service from the picked patient\'s current (unambiguous) consultant', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.pickPatient(rowA);
        await w.vm.$nextTick();

        expect(w.vm.cForm.consultation_from).toBe('Cardiology');
    });

    it('prefers the specialty the lookup ships (consultant_specialty) over matching the name', async () => {
        const w = mountWith();
        w.vm.openAdd();
        // the server's value wins even when the name alone would be ambiguous or unknown
        w.vm.pickPatient({ ...rowA, consultant: 'Nobody By This Name', consultant_specialty: 'Nephrology' });
        await w.vm.$nextTick();

        expect(w.vm.cForm.consultation_from).toBe('Nephrology');
    });

    it('never overwrites a value the clinician already typed', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.consultation_from = 'ER';
        w.vm.pickPatient(rowA);
        await w.vm.$nextTick();

        expect(w.vm.cForm.consultation_from).toBe('ER');
    });

    it('leaves the field blank for an unassigned patient (no consultant on the row)', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.pickPatient({ ...rowA, consultant: null });
        await w.vm.$nextTick();

        expect(w.vm.cForm.consultation_from).toBe('');
    });

    it('leaves the field blank when the consultant name matches more than one on-service consultant (ambiguous)', async () => {
        const w = mountWith({
            consultants: [
                { id: 5, name: 'Dr Cardio', specialty_id: 5, on_service: true },
                { id: 55, name: 'Dr Cardio', specialty_id: 6, on_service: true },   // same name, different team
            ],
        });
        w.vm.openAdd();
        w.vm.pickPatient(rowA);
        await w.vm.$nextTick();

        expect(w.vm.cForm.consultation_from).toBe('');
    });
});
