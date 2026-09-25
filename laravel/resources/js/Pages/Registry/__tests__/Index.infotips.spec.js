import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

// UX-review #9 + info marks (2026-09-24): the Long-term filter, the Clinical/Physical discharge
// labels and the per-row Transfer badge get "!" info marks; the Transfer badge itself now carries a
// distinct label ("Transferred to ICU") for an internal Ward→ICU close, instead of reusing
// "Out-dept transfer" as if the patient had left the department.
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
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><main><slot /></main></div>' },
}));
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { name: 'IcdTypeahead', template: '<div></div>' } }));

import Registry from '@/Pages/Registry/Index.vue';
import InfoTip from '@/Components/InfoTip.vue';

const options = {
    consultants: [{ id: 5, name: 'Dr A' }], countries: ['Saudi Arabia'], locations: ['Ward', 'ICU', 'ER'],
    outcomes: ['Alive', 'Dead'], admittedFrom: ['ER'], dischargedTo: ['Home'], delays: [],
    readmitWindow: 3, reasons: [], dxNames: [],
};
const row = (over = {}) => ({
    id: 1, name: 'Test Patient', mrn: '1001', age: 40, gender: 'Male', location: 'ICU', consultant: 'Dr A',
    admit_date: '2026-09-01', discharge_date: '2026-09-05', outcome: 'Alive', los: 4, los_band: 'short',
    diagnoses: [], admitted_by: null, discharged_by: null, admitted_from: 'ER',
    medical_discharge_date: '2026-09-04', discharge_to: 'Intensive Care (ICU)', delay_reason: null,
    transfer_label: 'Transferred to ICU', is_tb: false, is_readmission: false, is_longterm: false, disch_still_in: false,
    ...over,
});
const pageProps = (rows) => ({
    mode: 'admissions',
    results: { data: rows, total: rows.length, from: 1, to: rows.length, last_page: 1, links: [] },
    filters: {}, options, sort: { sort: null, dir: null },
});

let wrappers = [];
const mountAttached = (rows = [row()]) => {
    authUser = { id: 1, is_admin: true, can: { modify: true } };
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(Registry, { props: pageProps(rows), attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
});

describe('Registry/Index — info marks', () => {
    it('adds an info mark next to the Long-term filter checkbox', () => {
        const w = mountAttached();
        const label = w.findAll('label').find((l) => l.text().startsWith('Long-term'));
        expect(label.find('button[data-infotip]').exists()).toBe(true);
    });

    // role walkthrough 2026-09-25 (ui-ux, "!" info marks still missing): the readmissions checkbox's
    // sibling "Long-term" already had one; this filter's label reads the settings-driven window
    // (options.readmitWindow) but had no InfoTip at all.
    it('adds an info mark next to the N-day readmissions filter checkbox, using the configured window', () => {
        const w = mountAttached();
        const label = w.findAll('label').find((l) => l.text().includes('day readmissions'));
        expect(label.text()).toContain('3-day readmissions');   // options.readmitWindow from fixture
        const tip = label.find('button[data-infotip]');
        expect(tip.exists()).toBe(true);
    });

    // review fix (2026-09-25): the first pass's tip text read "...within the configured window
    // (currently the label's own number) of a real discharge" — a self-referential placeholder that
    // doesn't explain anything on its own. It now interpolates options.readmitWindow directly.
    it('interpolates the live readmission window into the tip text, not a self-referential placeholder', () => {
        const w = mountAttached();
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Readmissions filter');
        expect(tip).toBeTruthy();
        expect(tip.props('text')).toContain('within 3 days of a real discharge');
        expect(tip.props('text')).not.toMatch(/label's own number/i);
    });

    it('adds an info mark to the Clinical discharge, Physical discharge and Transfer detail labels', async () => {
        const w = mountAttached();
        await w.find('button[aria-label^="Toggle details"]').trigger('click');
        const dts = w.findAll('dt');
        const clinical = dts.find((d) => d.text().startsWith('Clinical discharge'));
        const physical = dts.find((d) => d.text().startsWith('Physical discharge'));
        const transfer = dts.find((d) => d.text().startsWith('Transfer'));
        expect(clinical.find('button[data-infotip]').exists()).toBe(true);
        expect(physical.find('button[data-infotip]').exists()).toBe(true);
        expect(transfer.find('button[data-infotip]').exists()).toBe(true);
    });
});

describe('Registry/Index — Ward→ICU transfer label (UX-review #9)', () => {
    it('shows the server-derived "Transferred to ICU" label distinctly from a real Out-dept transfer', async () => {
        const w = mountAttached([
            row({ id: 1, transfer_label: 'Transferred to ICU' }),
            row({ id: 2, transfer_label: 'Out-dept transfer' }),
        ]);
        const toggles = w.findAll('button[aria-label^="Toggle details"]');
        await toggles[0].trigger('click');
        await toggles[1].trigger('click');
        const text = w.text();
        expect(text).toContain('Transferred to ICU');
        expect(text).toContain('Out-dept transfer');
    });
});
