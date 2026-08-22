import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 2b — patient lookup inside the New-consultation modal. The term rides a POST body (PHI must
// never enter a URL); picking a result fills the identity fields and pins patient_id/admission_id;
// retyping the MRN clears the pin; the server's unmatched-MRN warning surfaces a "Record anyway" tick.

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
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 },
    reasons: [], consultants: [], specialties: [],
    worklist: { date: '2026-08-21', seen: 0, total: 0, items: [] },
};
// `id` is the ADMISSION id (the stay); `patient_id` is a distinct FK into `patients` — deliberately
// different numbers here so a test that conflated them (as the shipped code once did) would fail.
const row = {
    id: 501, patient_id: 9012, mrn: '40020001', name: 'Bedded Patient', age: 63, gender: 'Male',
    location: 'Ward', bed: 'W-12', status: 'active', dest: 'board',
};
const mountWith = () => { authUser = admin; return mount(ConsultationsIndex, { props }); };

beforeEach(() => {
    post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset();
    global.fetch = vi.fn();
});

describe('Consultations/Index — patient lookup on create', () => {
    it('searches through a POST body, never a query string', async () => {
        global.fetch.mockResolvedValue({ ok: true, json: async () => [row] });
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupQuery = 'Bedded';
        await w.vm.runLookup();

        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/api/patients/quick-search');
        expect(url).not.toContain('?');
        expect(opts.method).toBe('POST');
        expect(JSON.parse(opts.body)).toEqual({ q: 'Bedded' });
        expect(w.vm.lookupResults).toHaveLength(1);
    });

    it('does not search on a one-character term', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupQuery = 'B';
        await w.vm.runLookup();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('picking a patient fills the identity fields and pins patient_id + admission_id', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.lookupResults = [row];
        await w.vm.$nextTick();

        // drive the rendered UI (not the vm method directly) so the click handler + template wiring
        // are actually proven, not just the function they happen to call
        const buttons = w.findAll('ul button');
        expect(buttons).toHaveLength(1);
        await buttons[0].trigger('click');
        await w.vm.$nextTick();

        expect(w.vm.cForm.mrn).toBe('40020001');
        expect(w.vm.cForm.patient_name).toBe('Bedded Patient');
        expect(w.vm.cForm.age).toBe(63);
        expect(w.vm.cForm.bed).toBe('W-12');
        expect(w.vm.cForm.current_location).toBe('Ward');
        // patient_id and admission_id come from distinct id spaces (patients vs admissions) and must
        // not be conflated — this is the exact defect the review caught.
        expect(w.vm.cForm.patient_id).toBe(9012);
        expect(w.vm.cForm.admission_id).toBe(501);
        expect(w.vm.lookupResults).toEqual([]);
    });

    it('retyping the MRN by hand clears the pinned patient and any acknowledgement', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.pickPatient(row);
        await w.vm.$nextTick();
        w.vm.cForm.unmatched_mrn_ack = true;
        w.vm.cForm.mrn = '40029999';
        await w.vm.$nextTick();

        expect(w.vm.cForm.patient_id).toBe(null);
        expect(w.vm.cForm.admission_id).toBe(null);
        expect(w.vm.cForm.unmatched_mrn_ack).toBe(false);
    });

    it('surfaces the "record anyway" acknowledgement only after the server warns', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.mrn = '40029999';
        await w.vm.$nextTick();
        expect(w.vm.showUnmatchedAck).toBe(false);

        w.vm.cForm.errors = { mrn: 'No patient record matches MRN 40029999.' };
        await w.vm.$nextTick();
        expect(w.vm.showUnmatchedAck).toBe(true);
        expect(w.find('[data-test="unmatched-ack"]').exists()).toBe(true);
    });
});
