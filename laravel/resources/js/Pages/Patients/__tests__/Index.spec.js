import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Patients/Index.vue is the heaviest page in the app (board + 3 modals + 2 composables + the full
// AppLayout chrome) — every non-essential piece is stubbed so this spec can isolate the ONE thing it
// covers: the `?highlight=<admission_id>` deep-link consumption (Fix B) added to onMounted. It must
// not throw, and it must mark the matching PatientCard — that's the whole contract being tested here.
let pageProps;
vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: pageProps }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), put: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/ActivityPanel.vue', () => ({ default: { name: 'ActivityPanel', props: ['items'], template: '<div />' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: { name: 'BaseModal', props: ['open', 'title', 'subtitle', 'size', 'tall', 'closable'], template: '<div v-if="open"><slot /></div>' },
}));
vi.mock('@/Components/PatientForm.vue', () => ({ default: { name: 'PatientForm', props: ['form', 'selectedDx', 'countries', 'consultants', 'today'], template: '<div />' } }));
vi.mock('@/Components/Patients/ActionModal.vue', () => ({
    default: { name: 'ActionModal', props: ['open', 'mode', 'patient', 'consultants', 'specialties', 'externalServices', 'today'], template: '<div />' },
}));
vi.mock('@/Components/Patients/ReassignModal.vue', () => ({ default: { name: 'ReassignModal', props: ['open', 'consultants'], template: '<div />' } }));
vi.mock('@/Components/Patients/HandoverModal.vue', () => ({
    default: { name: 'HandoverModal', props: ['open', 'patient', 'canManage', 'isObserver'], template: '<div />' },
}));
vi.mock('@/Components/PatientFlags.vue', () => ({ default: { name: 'PatientFlags', props: ['patient', 'readmitWindow', 'variant'], template: '<span />' } }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn(() => Promise.resolve(true)) }) }));
vi.mock('@/composables/usePatientEdit', () => ({
    usePatientEdit: () => ({
        form: {}, editing: { value: null }, selectedDx: [], activity: [],
        open: vi.fn(), addDx: vi.fn(), removeDx: vi.fn(), submit: vi.fn(), isDirty: { value: false },
    }),
}));
vi.mock('@/composables/useUnsavedGuard', () => ({ useUnsavedGuard: () => ({ guardedClose: (fn) => fn() }) }));

import Index from '@/Pages/Patients/Index.vue';

const patient = (over = {}) => ({
    id: 10, name: 'Test Patient', mrn: '9001', location: 'Ward', bed: 'W-1',
    los: 3, los_band: 'short', age: 40, gender: 'Male', admit_date: '2026-06-01',
    dx_count: 0, diagnoses: [], is_longterm: false, medically_discharged: false, discharged: false,
    consultant_id: 9, sign_pending: false, handover: null, ...over,
});
const group = (id, name, patients) => ({
    id, name, specialty_id: 1, on_service: true,
    counts: { new: 0, old: patients.length, active: patients.length, ward: patients.length, icu: 0, tb: 0, total: patients.length },
    patients,
});
const baseProps = (over = {}) => ({
    groups: [group(1, 'Alpha', [patient({ id: 10 })]), group(2, 'Beta', [patient({ id: 42 })])],
    filters: {}, stats: { total: 2, ward: 2, icu: 0, unassigned: 0 },
    consultants: [], specialties: [], externalServices: [], readmitWindow: 3, countries: [],
    fallback: null, highlight: null, ...over,
});

describe('Patients/Index — ?highlight deep-link (Fix B)', () => {
    beforeEach(() => {
        pageProps = { auth: { user: { id: 1, role: 0, is_admin: true, can: { assign: true, manage: true, modify: true } } } };
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    });

    it('mounts cleanly with no highlight (baseline — nothing marked)', async () => {
        const w = mount(Index, { props: baseProps() });
        await flushPromises();
        expect(w.find('[data-admission-id="10"]').classes()).not.toContain('outline-brand-500');
        expect(w.find('[data-admission-id="42"]').classes()).not.toContain('outline-brand-500');
    });

    it('marks the matching PatientCard when highlight matches an admission id, without throwing', async () => {
        const w = mount(Index, { props: baseProps({ highlight: 42 }) });
        await flushPromises();
        const card = w.find('[data-admission-id="42"]');
        expect(card.exists()).toBe(true);
        expect(card.classes()).toContain('outline-brand-500');
        // the OTHER card must stay unmarked
        expect(w.find('[data-admission-id="10"]').classes()).not.toContain('outline-brand-500');
    });

    it('is a no-op when highlight matches no patient on the board (discharged/reassigned since the reminder fired)', async () => {
        const w = mount(Index, { props: baseProps({ highlight: 9999 }) });
        await flushPromises();
        expect(w.find('[data-admission-id="10"]').classes()).not.toContain('outline-brand-500');
        expect(w.find('[data-admission-id="42"]').classes()).not.toContain('outline-brand-500');
    });
});
