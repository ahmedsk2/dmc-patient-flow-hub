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
