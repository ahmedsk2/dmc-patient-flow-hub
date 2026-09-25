import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// 2026-09-24 role/UX review (group g5-dashboard-layout): a new, standalone spec file rather than
// extending Dashboard.adminBand.test.js, which several implementers already touch concurrently in
// this working tree. Covers:
//   #7  — the "Active Consultations" tile stops being a link for Observer (mirrors the server's
//         hard 403 in ConsultationsController::index()), and carries an InfoTip everywhere else.
//   #15 — the Bed Occupancy tile carries an InfoTip.
//   #28 — the "New"/"Old" table column headers carry InfoTips (not a 24h-timer claim).
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/composables/useChartTheme', () => ({
    useChartTheme: () => ({
        gridColor: { value: '#000' }, axisColor: { value: '#000' }, strokeColor: { value: '#000' }, inkColor: { value: '#000' },
        series: { value: { primary: '#009ca6', accent: '#d9a23c', deep: '#00565e', info: '#2f7fe0', muted: '#5b6a6e', primarySoft: '#38b4ba' } },
    }),
}));

import Dashboard from '@/Pages/Dashboard.vue';
import InfoTip from '@/Components/InfoTip.vue';

// shallowMount stubs every child component it renders, InfoTip included — `findComponent` still
// matches a stubbed instance by its original component reference, and `.props()` on a stub still
// returns the real props it was passed, so this is the reliable way to assert "an InfoTip with
// this label exists here" without needing InfoTip to actually render its DOM under a shallow mount.
const tipLabels = (w) => w.findAllComponents(InfoTip).map((t) => t.props('label'));

const emptyKpis = { census: 0, ward: 0, icu: 0, admissionsToday: 0, dischargesToday: 0, activeConsults: 7, deathsMonth: 0, avgLosMonth: 0, occupancy: 118, occupancyGauge: 100, wardBeds: 50, icuBeds: 8 };
const baseProps = (over = {}) => ({
    adminBand: null,
    kpis: emptyKpis, boardingCount: 0, boardingWorklist: [], deltas: {}, alerts: [], myUnit: null,
    loadBands: { minHosp: 0, maxHosp: 0, minSubs: 0, maxSubs: 0 },
    trend: { labels: [], admissions: [], discharges: [] }, consults: { labels: [], new: [], signed: [] },
    consultDonut: { signedTodayOrYesterday: 0, active: 0 }, los: { labels: [], data: [] },
    mix: { hospitalist: 0, subspecialty: 0, longterm: 0 }, donutTotal: 0, donutTb: 0,
    perConsultant: [], consultantBoard: [], activity24h: [], ytd: { admissions: 0, discharges: 0, consultations: 0, signoffs: 0 },
    topDxWeek: [], topDxWeekNum: 0, recent: [], generatedAt: 'now',
    ...over,
});
const ChartCanvasStub = { name: 'ChartCanvas', props: ['type', 'data', 'options', 'plugins', 'height'], template: '<canvas />' };
const mountAs = (user, over = {}) => {
    authUser = user;
    return shallowMount(Dashboard, { props: baseProps(over), global: { renderStubDefaultSlot: true, stubs: { ChartCanvas: ChartCanvasStub, teleport: true } } });
};

describe('Dashboard — Active Consultations tile (review #7)', () => {
    it('renders as a clickable <button> with an InfoTip for a non-Observer role', () => {
        const w = mountAs({ role: 3, is_admin: false });
        const tile = w.find('[data-kpi="Active Consultations"]');
        expect(tile.element.tagName).toBe('BUTTON');
        expect(tipLabels(w)).toContain('Active Consultations');
    });

    it('renders as a non-clickable <div> for Observer (role 5) — mirrors the server 403', () => {
        const tile = mountAs({ role: 5, is_admin: false }).find('[data-kpi="Active Consultations"]');
        expect(tile.element.tagName).toBe('DIV');
    });

    it('is still a <button> for Admin, Registrar, Consultant and Resident', () => {
        for (const role of [0, 2, 3, 4]) {
            const tile = mountAs({ role, is_admin: role === 0 }).find('[data-kpi="Active Consultations"]');
            expect(tile.element.tagName, `role ${role}`).toBe('BUTTON');
        }
    });
});

describe('Dashboard — Bed Occupancy InfoTip (review #15)', () => {
    it('carries an InfoTip explaining the >100% denominator (KPI tile and chart-section heading)', () => {
        const w = mountAs({ role: 0, is_admin: true });
        // one on the hero KPI tile, one on the "Bed Occupancy" chart-section <h2> — both present
        expect(tipLabels(w).filter((l) => l === 'Bed Occupancy')).toHaveLength(2);
    });
});

// role walkthrough 2026-09-25 (admin-clinical #2, security-adjacent InfoTip gap): the per-consultant
// table's "Active" header had no InfoTip even though its definition (non-ICU, not medically
// discharged, not long-term, not TB) is the least obvious in the row — Old/New already had one.
describe('Dashboard — "Active" column InfoTip (role walkthrough 2026-09-25)', () => {
    it('carries an InfoTip naming the same exclusions DashboardController applies', () => {
        const w = mountAs({ role: 0, is_admin: true });
        const tips = w.findAllComponents(InfoTip);
        const activeTip = tips.find((t) => t.props('label') === 'Active column');
        expect(activeTip).toBeTruthy();
        expect(activeTip.props('text')).toMatch(/ICU/);
        expect(activeTip.props('text')).toMatch(/long-term/i);
        expect(activeTip.props('text')).toMatch(/TB/);
        expect(activeTip.props('text')).toMatch(/medically discharged/i);
    });
});

// role walkthrough 2026-09-25 (observer #missing-infotips): Mortality and Avg LOS had no `tip:`
// key at all, unlike every other tile on the row.
describe('Dashboard — Mortality / Avg LOS InfoTips (role walkthrough 2026-09-25)', () => {
    it('both tiles carry an InfoTip matching their month-to-date, non-ICU-LOS definitions', () => {
        const w = mountAs({ role: 0, is_admin: true });
        const tips = w.findAllComponents(InfoTip);
        const mortalityTip = tips.find((t) => t.props('label') === 'Mortality (Month)');
        const losTip = tips.find((t) => t.props('label') === 'Avg LOS (month)');
        expect(mortalityTip).toBeTruthy();
        expect(mortalityTip.props('text')).toMatch(/Dead/);
        expect(mortalityTip.props('text')).toMatch(/calendar month/i);
        expect(losTip).toBeTruthy();
        expect(losTip.props('text')).toMatch(/non-ICU/i);
        expect(losTip.props('text')).toMatch(/calendar month/i);
    });
});

// role walkthrough 2026-09-25 (observer #2): the Active Consultations InfoTip used to say
// "may be fewer, based on your specialty" for every role, including Observer — for whom the ledger
// is refused entirely (ConsultationsController::index() 403s isObserver() first), never scoped by
// specialty. Wording is now role-aware.
describe('Dashboard — Active Consultations InfoTip is role-aware (role walkthrough 2026-09-25)', () => {
    it('tells Observer the ledger is not part of the role, not that it is scoped by specialty', () => {
        const w = mountAs({ role: 5, is_admin: false });
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Active Consultations');
        expect(tip).toBeTruthy();
        expect(tip.props('text')).toMatch(/not part of the Observer role/i);
        expect(tip.props('text')).not.toMatch(/based on your specialty/i);
    });

    it('keeps the specialty-scoped wording for a non-Observer role', () => {
        const w = mountAs({ role: 3, is_admin: false });
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Active Consultations');
        expect(tip).toBeTruthy();
        expect(tip.props('text')).toMatch(/based on your specialty/i);
    });

    // review fix (2026-09-25): Consultation::scopeVisibleTo gives admins AND coordinators
    // (canCoordinateConsultations()) the full, unscoped ledger — the specialty-scoped wording was
    // just as wrong for them as it was for Observer, only in the opposite direction.
    it('drops the specialty-scoped wording for Admin (canCoordinateConsults true)', () => {
        const w = mountAs({ role: 0, is_admin: true }, { canCoordinateConsults: true });
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Active Consultations');
        expect(tip).toBeTruthy();
        expect(tip.props('text')).not.toMatch(/based on your specialty/i);
        expect(tip.props('text')).not.toMatch(/not part of the Observer role/i);
    });

    it('drops the specialty-scoped wording for a non-admin coordinator (can_coordinate_consultations)', () => {
        const w = mountAs({ role: 3, is_admin: false }, { canCoordinateConsults: true });
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Active Consultations');
        expect(tip).toBeTruthy();
        expect(tip.props('text')).not.toMatch(/based on your specialty/i);
    });
});

describe('Dashboard — "New"/"Old" column InfoTips (review #28)', () => {
    it('both column headers carry an InfoTip, not a 24h-timer claim', () => {
        const w = mountAs({ role: 0, is_admin: true });
        const tips = w.findAllComponents(InfoTip);
        const oldTip = tips.find((t) => t.props('label') === 'Old column');
        const newTip = tips.find((t) => t.props('label') === 'New column');
        expect(oldTip).toBeTruthy();
        expect(newTip).toBeTruthy();
        // the review's own report and the doc's PRIOR (wrong) wording both said "within 24h" —
        // the corrected text must not repeat that claim as the definition
        expect(newTip.props('text')).not.toMatch(/within 24/i);
        // it must instead name the real managed-flag rule (DASHBOARD-AND-STATISTICS-METRICS.md's fix)
        expect(newTip.props('text')).toMatch(/assign|handover|shuffle/i);
        expect(newTip.props('text')).toMatch(/discharge|reassign/i);
    });
});
