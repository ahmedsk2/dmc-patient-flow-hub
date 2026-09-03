import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { axe } from 'vitest-axe';
import * as axeMatchers from 'vitest-axe/matchers';

// Automated accessibility gate (UX-08): the three consultation-ledger pages are mounted with
// representative props (the fixture idioms of ConsultationsIndex.wave3.test.js, Handover.spec.js
// and ConsultationsDashboard.test.js) and handed to axe-core. Any violation fails the build.
//
// What this does and does not prove: axe-core in jsdom checks structure and semantics — names,
// roles, labels, table headers, ARIA usage, heading/landmark structure. It cannot measure colour
// contrast (no layout engine; that is scripts/contrast.mjs's job) or anything that needs a real
// paint. So a green run here is "no structural WCAG defect in the rendered DOM", not "accessible".
//
// The stubs mirror the REAL page skeleton rather than hiding it: AppLayout renders a <header> with
// the page <h1> and a <main id="main-content"> around the slot (see Layouts/AppLayout.vue), so the
// stub does the same and the landmark/heading rules run against a page shaped like production.
// Nothing is disabled and no rule is filtered — if a violation appears, it is reported, not silenced.
expect.extend(axeMatchers);

const { get, post, put, deleteFn, ask, visit } = vi.hoisted(() => ({
    get: vi.fn(), post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(), visit: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get, post, delete: deleteFn, visit, reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => {
        const keys = Object.keys(obj);
        const data = () => Object.fromEntries(keys.map((k) => [k, form[k]]));
        const form = reactive({
            ...obj, errors: {}, processing: false,
            post: vi.fn((url, opts) => post(url, data(), opts)),
            put: vi.fn((url, opts) => put(url, data(), opts)),
            reset: vi.fn(), clearErrors: vi.fn(),
        });
        return form;
    },
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        name: 'AppLayout',
        props: ['title'],
        template: '<div><header><h1>{{ title }}</h1></header><main id="main-content" tabindex="-1"><slot /></main></div>',
    },
}));
// BaseModal is reduced to a slot-rendering shell, exactly as the wave-3 spec does: its own dialog
// semantics (role, labelling, focus trap) are covered by BaseModal.spec.js / useModalA11y.test.js.
// What this gate sees is the FORM inside it — every control must still carry a real label.
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));
// the chart-theme composable reads CSS custom properties — stub to inert refs.
vi.mock('@/composables/useChartTheme', () => ({
    useChartTheme: () => ({
        gridColor: { value: '#000' }, axisColor: { value: '#000' }, strokeColor: { value: '#000' }, inkColor: { value: '#000' },
        series: { value: { primary: '#009ca6', accent: '#d9a23c', deep: '#00565e', info: '#2f7fe0', muted: '#5b6a6e', primarySoft: '#38b4ba' } },
    }),
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';
import ConsultationsDashboard from '@/Pages/Consultations/Dashboard.vue';
import Handover from '@/Pages/Consultations/Handover.vue';

// vue3-apexcharts renders a <div> root and lets the caller's attributes (aria-label, …) fall through
// onto it; the stub does the same so axe sees the element the real page produces, not a custom tag.
const apexchartStub = { name: 'apexchart', inheritAttrs: true, template: '<div></div>' };

// jsdom has no canvas. axe's colour-contrast check probes one to sample backgrounds and jsdom logs
// a "Not implemented: HTMLCanvasElement.prototype.getContext" error for every audit — noise, not a
// finding (contrast is measured by scripts/contrast.mjs; axe marks it "incomplete" here either way).
// Returning null is what a canvas-less browser does; no axe rule is disabled by this.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

// axe needs the tree in the document to resolve roles, names and ancestry; mount attached and
// always unmount so one page's DOM never leaks into the next page's audit.
let wrappers = [];
const mountAttached = (component, options = {}) => {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const w = mount(component, { ...options, attachTo: host });
    wrappers.push({ w, host });
    return w;
};
afterEach(() => {
    for (const { w, host } of wrappers) { w.unmount(); host.remove(); }
    wrappers = [];
    document.body.innerHTML = '';
});
beforeEach(() => { get.mockClear(); post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); visit.mockClear(); });

const audit = async (w) => expect(await axe(w.element)).toHaveNoViolations();

// ---- Consultations/Index — the workspace ---------------------------------------------------------
const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const registrar = { role: 2, is_admin: false, id: 9, can: { manage: false } };
const consultant = { role: 3, is_admin: false, id: 5, can: { manage: false } };
const row = (extra = {}) => ({ id: 1, name: 'A', mrn: '1', reasons: [], status: 'active', open_days: 2, signoff: null, indication_ids: [], can_modify: true, ...extra });
const indexProps = (data = [], extra = {}) => ({
    consultations: { data, total: data.length, last_page: 1, links: [] },
    filters: {},
    stats: { new: 2, active: 3, ongoing: 4, signed_off: 5, total: 14, open: 9, mine_open: 1 },
    // the shape ConsultationsController::index ships: [{ id, name }] — the page renders `r.name` as
    // each indication checkbox's label text, so a wrong key here reads as an unlabelled control
    reasons: [{ id: 1, name: 'Chest pain' }, { id: 2, name: 'Arrhythmia' }],
    consultants: [{ id: 5, name: 'Dr Cardio' }, { id: 7, name: 'Dr Busy' }],
    specialties: [{ id: 2, name: 'Cardiology' }, { id: 3, name: 'Nephrology' }],
    ...extra,
});
const mountIndex = (data, user = admin, extra = {}) => {
    authUser = user;
    return mountAttached(ConsultationsIndex, { props: indexProps(data, extra) });
};

describe('a11y (axe) — Consultations/Index', () => {
    it('empty workspace: tabs, counters, search and filters', async () => {
        await audit(mountIndex([]));
    });

    it('a populated list in every state, with the row controls a manager sees', async () => {
        await audit(mountIndex([
            row({ id: 1, status: 'new', consultant_id: 5 }),
            row({ id: 2, name: 'B', mrn: '2', status: 'active', open_days: 6, consultant_id: 5, reasons: ['Chest pain'] }),
            row({ id: 3, name: 'C', mrn: '3', status: 'ongoing', open_days: 12, consultant_id: 7 }),
            row({ id: 4, name: 'D', mrn: '4', status: 'signed_off', open_days: null, signoff: '2026-08-20', disposition: 'follow_up_arranged', followup_needed: true }),
        ]));
    });

    it('the read-only shape a plain registrar sees', async () => {
        await audit(mountIndex([row({ status: 'new', can_modify: false, consultant_id: 999 })], registrar));
    });

    it('a consultant with the personal counter and today\'s follow-up worklist', async () => {
        await audit(mountIndex([row({ status: 'active', consultant_id: 5 })], consultant, {
            worklist: {
                date: new Date().toISOString().slice(0, 10), seen: 1, total: 2,
                items: [
                    { id: 1, name: 'A', mrn: '1', bed: '5A', seen_today: false },
                    { id: 2, name: 'B', mrn: '2', bed: '5B', seen_today: true },
                ],
            },
        }));
    });

    it('the new-consultation form', async () => {
        const w = mountIndex([]);
        w.vm.openAdd();
        await w.vm.$nextTick();
        await audit(w);
    });

    it('the new-consultation form with a validation error summary', async () => {
        const w = mountIndex([]);
        w.vm.openAdd();
        w.vm.cForm.errors = { mrn: 'Enter an MRN using digits only', bed: 'Enter a bed' };
        await w.vm.$nextTick();
        await audit(w);
    });

    it('the edit-consultation form', async () => {
        const w = mountIndex([]);
        w.vm.openEdit({ id: 7, mrn: '111', name: 'Ali', age: 40, bed: '5A', location: 'Ward', date: '2026-06-01', from: 'ER', to: 'Cardio', consultant_id: 5, indication_ids: [1], other: '' });
        await w.vm.$nextTick();
        await audit(w);
    });

    it('the sign-off response form', async () => {
        const w = mountIndex([row({ id: 7, name: 'Ali', mrn: '111', status: 'active' })]);
        w.vm.openSignoff({ id: 7, name: 'Ali', mrn: '111', status: 'active', open_days: 2, signoff: null, reasons: [], indication_ids: [] });
        await w.vm.$nextTick();
        await audit(w);
    });
});

// ---- Consultations/Dashboard --------------------------------------------------------------------
const dashboardProps = (over = {}) => ({
    canPick: false,
    filters: { specialty_id: null },
    specialties: [],
    scopeLabel: 'Cardiology',
    openCounts: { new: 1, active: 4, ongoing: 2, total: 7 },
    ageing: { b0_2: 3, b3_7: 2, b8_plus: 1, unknown: 1 },
    today: { due: 4, seen: 3 },
    turnaround: { first_followup_hours: 2.5, first_followup_n: 12, signoff_hours: 30.0, signoff_n: 9, legacy_excluded: 1283, from_cutover: true },
    trend: { labels: ['Mar 26', 'Apr 26', 'May 26', 'Jun 26', 'Jul 26', 'Aug 26'], data: [3, 5, 2, 8, 4, 6] },
    topIndications: [{ label: 'Chest pain', value: 9 }, { label: 'Arrhythmia', value: 4 }],
    perConsultant: [{ id: 7, name: 'Dr Busy', c: 5 }, { id: 8, name: 'Dr Quiet', c: 2 }],
    generatedAt: '09:41',
    ...over,
});
const mountDashboard = (user, over = {}) => {
    authUser = user;
    return mountAttached(ConsultationsDashboard, {
        props: dashboardProps(over),
        global: { stubs: { apexchart: apexchartStub, teleport: true } },
    });
};

describe('a11y (axe) — Consultations/Dashboard', () => {
    it('a consultant\'s own-service dashboard with data in every panel', async () => {
        await audit(mountDashboard({ role: 3, is_admin: false }));
    });

    it('an admin with the specialty picker', async () => {
        await audit(mountDashboard({ role: 0, is_admin: true }, {
            canPick: true,
            scopeLabel: 'All specialties',
            specialties: [{ id: 2, name: 'Cardiology' }, { id: 3, name: 'Nephrology' }],
        }));
    });

    it('the empty states: no open consults, no trend, no indications, no load', async () => {
        await audit(mountDashboard({ role: 3, is_admin: false }, {
            openCounts: { new: 0, active: 0, ongoing: 0, total: 0 },
            ageing: { b0_2: 0, b3_7: 0, b8_plus: 0, unknown: 0 },
            today: { due: 0, seen: 0 },
            turnaround: { first_followup_hours: null, first_followup_n: 0, signoff_hours: null, signoff_n: 0, legacy_excluded: 0, from_cutover: true },
            trend: { labels: [], data: [] },
            topIndications: [],
            perConsultant: [],
        }));
    });
});

// ---- Consultations/Handover ---------------------------------------------------------------------
const consult = (over = {}) => ({
    id: 1, name: 'Ward Patient', mrn: '90000001', age: 61, bed: 'W-12', location: 'Ward',
    from: 'ER', status: 'active', specialty: 'Cardiology',
    consultant: 'Dr Cardio', entered_by: 'Dr Coordinator',
    reasons: ['Chest pain'], other: null, requested_on: '2026-08-15', open_days: 6,
    last_followup: { date: '2026-08-21', note: 'Rate controlled, continue beta blocker.', author: 'Dr Cardio', is_today: true },
    ...over,
});
const group = (key, name, consultations) => ({
    key, name, consultations,
    counts: {
        total: consultations.length,
        active: consultations.filter((c) => c.status === 'active').length,
        ongoing: consultations.filter((c) => c.status === 'ongoing').length,
        seen_today: consultations.filter((c) => c.last_followup && c.last_followup.is_today).length,
    },
});
const cardiology = () => group('Cardiology', 'Cardiology', [
    consult(),
    consult({ id: 2, name: 'Second Patient', mrn: '90000002', bed: 'W-20', status: 'ongoing', open_days: 12, last_followup: null }),
    consult({ id: 3, name: 'Third Patient', mrn: '90000003', bed: null, age: null, location: null, open_days: null, reasons: [], other: 'Free-text indication' }),
]);
const nephrology = () => group('Nephrology', 'Nephrology', [
    consult({ id: 4, name: 'Renal Patient', mrn: '90000004', specialty: 'Nephrology', consultant: 'Dr Nephro', status: 'ongoing', last_followup: null }),
]);
const handoverProps = (over = {}) => ({ groups: [cardiology()], generatedAt: 'Fri, 21 Aug 2026 · 07:00', today: '2026-08-21', ...over });
const mountHandover = (p = handoverProps()) => mountAttached(Handover, { props: p });

describe('a11y (axe) — Consultations/Handover', () => {
    it('a single-service sheet', async () => {
        await audit(mountHandover());
    });

    it('a multi-service sheet with the service picker', async () => {
        await audit(mountHandover(handoverProps({ groups: [cardiology(), nephrology()] })));
    });

    // one page per test: two mounted pages would put two <header>/<main> landmarks in one document
    // and axe would (correctly) report the duplicate — a harness artefact, not a page defect.
    it('a service with nothing on the books', async () => {
        await audit(mountHandover(handoverProps({ groups: [group('Empty', 'Empty Service', [])] })));
    });

    it('an empty sheet', async () => {
        await audit(mountHandover(handoverProps({ groups: [] })));
    });
});
