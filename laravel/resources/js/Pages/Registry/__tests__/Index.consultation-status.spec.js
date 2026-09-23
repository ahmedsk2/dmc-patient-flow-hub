import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Defect (C) — walkthrough 2026-09-23: RegistryController::consultationResults() now sends
// `status`, one of Consultation's four ledger states. Registry/Index.vue used to branch only on
// whether `signoff` was set, so every new/active/ongoing consultation rendered as "Active" —
// hiding the real state from a reader relying on the registry. This mounts the real page in
// consultations mode and checks each status renders its own label (same vocabulary as
// Consultations/Index.vue's STATUS_TABS), never the old blanket "Active".
const { get, post, visit } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn() }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    router: { get, post, visit, delete: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (initial) => ({
        ...initial, errors: {}, processing: false,
        post: vi.fn(), put: vi.fn(), clearErrors: vi.fn(), reset: vi.fn(),
    }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { name: 'IcdTypeahead', template: '<div></div>' } }));

import Registry from '@/Pages/Registry/Index.vue';

const options = {
    consultants: [], countries: [], locations: [], outcomes: [], admittedFrom: [], dischargedTo: [],
    delays: [], readmitWindow: 3, reasons: [], dxNames: [],
};

const row = (overrides) => ({
    id: 1, name: 'Status Patient', mrn: '80000001', age: 40, location: 'Ward', from: 'ER',
    to: 'Cardiology', consultant: 'Dr A', date: '2024-05-01', signoff: null, status: null,
    reasons: [], ...overrides,
});

const mountConsultations = (data) => {
    authUser = { id: 1, is_admin: true, can: { modify: true } };
    const results = { data, total: data.length, from: 1, to: data.length, last_page: 1, links: [] };

    return mount(Registry, {
        props: { mode: 'consultations', results, filters: {}, options, sort: { sort: null, dir: null } },
    });
};

describe('Registry/Index (consultations mode) — 4-state status label (defect C)', () => {
    it('labels an ongoing consultation "Ongoing", never "Active"', () => {
        const w = mountConsultations([row({ status: 'ongoing' })]);
        expect(w.text()).toContain('Ongoing');
        expect(w.text()).not.toContain('Active');
    });

    it('labels a new consultation "New", never "Active"', () => {
        const w = mountConsultations([row({ status: 'new' })]);
        expect(w.text()).toContain('New');
        expect(w.text()).not.toContain('Active');
    });

    it('labels an active consultation "Active"', () => {
        const w = mountConsultations([row({ status: 'active' })]);
        expect(w.text()).toContain('Active');
    });

    it('labels a signed-off consultation "Signed off" with its sign-off date', () => {
        const w = mountConsultations([row({ status: 'signed_off', signoff: '2024-05-03' })]);
        expect(w.text()).toContain('Signed off');
        expect(w.text()).toContain('2024-05-03');
    });
});
