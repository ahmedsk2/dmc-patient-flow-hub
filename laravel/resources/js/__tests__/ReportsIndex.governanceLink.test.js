import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import ReportsIndex from '@/Pages/Reports/Index.vue';

const baseProps = {
    year: 2026, availableYears: [2026, 2025], months: [], totals: { admissions: 0, discharges: 0, icu: 0, deaths: 0, mortalityRate: 0, lsp: 0 },
    avgLos: 0, icuLos: 0, topDx: [], perConsultant: [], destinations: [], perConsultantLos: [], generatedAt: 'now',
};

// the page content lives in AppLayout's default slot — render stubbed slots so we can query it.
const mountReports = () => shallowMount(ReportsIndex, { props: baseProps, global: { renderStubDefaultSlot: true } });

describe('Reports/Index — governance cross-link (Wave 1, Item 2)', () => {
    it('renders a link to the M&M / Governance pack', () => {
        const link = mountReports().find('a[href="/reports/governance"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Governance');
    });

    it('still renders the monthly-breakdown link (the mirrored pattern)', () => {
        expect(mountReports().find('a[href="/reports/monthly?year=2026"]').exists()).toBe(true);
    });
});
