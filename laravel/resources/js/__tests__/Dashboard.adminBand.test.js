import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
// the chart-theme composable touches CSS vars / matchMedia — stub to inert refs.
vi.mock('@/composables/useChartTheme', () => ({
    useChartTheme: () => ({ gridColor: { value: '#000' }, axisColor: { value: '#000' }, strokeColor: { value: '#000' }, inkColor: { value: '#000' } }),
}));
// AdminBandCard is the unit under test downstream — count its instances via the stub.
vi.mock('@/Components/AdminBandCard.vue', () => ({ default: { name: 'AdminBandCard', props: ['label', 'count', 'href', 'iconPath', 'urgent'], template: '<a class="band-card">{{ count }} {{ label }}</a>' } }));

import Dashboard from '@/Pages/Dashboard.vue';
import AdminBandCard from '@/Components/AdminBandCard.vue';

const emptyKpis = { census: 0, ward: 0, icu: 0, admissionsToday: 0, dischargesToday: 0, activeConsults: 0, deathsMonth: 0, avgLosMonth: 0, occupancy: 0, occupancyGauge: 0, wardBeds: 50 };
const baseProps = (over = {}) => ({
    adminBand: null,
    kpis: emptyKpis, boardingCount: 0, boardingWorklist: [], deltas: {}, alerts: [], myUnit: null,
    loadBands: { minHosp: 0, maxHosp: 0, minSubs: 0, maxSubs: 0 },
    trend: { labels: [], admissions: [], discharges: [] }, consults: { labels: [], new: [], signed: [] },
    consultDonut: { signed24h: 0, active: 0 }, los: { labels: [], data: [] },
    mix: { hospitalist: 0, subspecialty: 0, longterm: 0 }, donutTotal: 0, donutTb: 0,
    perConsultant: [], consultantBoard: [], activity24h: [], ytd: { admissions: 0, discharges: 0, consultations: 0, signoffs: 0 },
    topDxWeek: [], topDxWeekNum: 0, recent: [], generatedAt: 'now',
    ...over,
});

const mountAs = (user, over = {}) => {
    authUser = user;
    // renderStubDefaultSlot so the AppLayout stub renders the page body (the band lives there).
    return shallowMount(Dashboard, { props: baseProps(over), global: { renderStubDefaultSlot: true, stubs: { apexchart: true, teleport: true } } });
};

describe('Dashboard — admin landing band (Wave 1, Item 7)', () => {
    const adminBand = { dqIssues: 2, securityAnomalies: 5, recentlyDeleted: 1, pendingHandovers: 3 };

    it('renders the Administrative section with four cards for an admin', () => {
        const w = mountAs({ role: 0, is_admin: true }, { adminBand });
        expect(w.find('section[aria-label="Administrative overview"]').exists()).toBe(true);
        expect(w.findAllComponents(AdminBandCard)).toHaveLength(4);
    });

    it('passes the urgent flag only to Data Quality + Security', () => {
        const w = mountAs({ role: 0, is_admin: true }, { adminBand });
        const cards = w.findAllComponents(AdminBandCard);
        const byLabel = Object.fromEntries(cards.map((c) => [c.props('label'), c.props('urgent')]));
        expect(byLabel['Data Quality Issues']).toBe(true);
        expect(byLabel['Security Anomalies']).toBe(true);
        expect(byLabel['Recently Deleted']).toBe(false);
        expect(byLabel['Pending Handovers']).toBe(false);
    });

    it('does NOT render the band for a non-admin', () => {
        const w = mountAs({ role: 3, is_admin: false }, { adminBand });
        expect(w.find('section[aria-label="Administrative overview"]').exists()).toBe(false);
        expect(w.findAllComponents(AdminBandCard)).toHaveLength(0);
    });

    it('does NOT render the band when adminBand is null even for an admin', () => {
        const w = mountAs({ role: 0, is_admin: true }, { adminBand: null });
        expect(w.find('section[aria-label="Administrative overview"]').exists()).toBe(false);
    });
});

// W0-T3i — the "My unit today" lens. Colocated here because this file already owns the only
// Dashboard mount harness; the tiles are a sibling of the admin band in the same template.
//
// Three of the six tiles asked for `bg-danger-50` / `bg-warning-50` / `bg-info-50`. Those steps are
// not declared in @theme, so Tailwind emitted NO rule for them and the tiles rendered with no fill
// at all: half the row's colour coding was silently dead. They now use the theme-aware `bg-tint-*`
// tokens at 30% — the alpha whose CIE dE76 from `bg-card` (4.2 / 4.6 / 4.4) matches the two fills
// that always worked (bg-brand-50 = 4.25, bg-ink-50 = 3.90), so the row keeps one visual weight.
// The `nums` value keeps `text-ink-900`: 13.7-14.2:1 light, 15.0-15.6:1 dark on every new fill.
describe('Dashboard — "My unit today" tiles (W0-T3i)', () => {
    const myUnit = { total: 12, ward: 9, icu: 3, boarding: 2, new: 4, myConsults: 5, signPending: 0 };
    // label -> class list. The label is the second <p> in each tile button.
    const tiles = (w) => Object.fromEntries(
        w.findAll('.grid-cols-3 button').map((b) => [b.findAll('p')[1].text(), b.classes()]),
    );
    const mountConsultant = () => mountAs({ role: 3, is_admin: false }, { myUnit });

    it('renders all six tiles for a consultant', () => {
        expect(Object.keys(tiles(mountConsultant()))).toEqual(
            ['Active', 'Ward', 'ICU', 'Boarding', 'New (24h)', 'Consults'],
        );
    });

    it('the three formerly fill-less tiles now carry theme-aware tint fills', () => {
        const t = tiles(mountConsultant());
        expect(t['ICU']).toContain('bg-tint-danger/30');
        expect(t['Boarding']).toContain('bg-tint-warning/30');
        expect(t['New (24h)']).toContain('bg-tint-info/30');
    });

    it('no tile references an undeclared *-50 colour step (the defect: zero emitted CSS)', () => {
        for (const [label, classes] of Object.entries(tiles(mountConsultant()))) {
            const dead = classes.filter((c) => /^bg-(danger|warning|info|success)-50(\/|$)/.test(c));
            expect(dead, `${label} tile still asks for an undeclared step`).toEqual([]);
        }
    });

    it('leaves the two fills that always emitted alone', () => {
        const t = tiles(mountConsultant());
        expect(t['Active']).toContain('bg-brand-50');
        expect(t['Ward']).toContain('bg-ink-50');
        expect(t['Consults']).toContain('bg-accent-300/20');
    });
});
