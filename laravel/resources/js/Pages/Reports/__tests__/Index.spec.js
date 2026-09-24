import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// UX-review §5 (2026-09-24): "Long-stay %" was never defined on the page — only the Control →
// Settings-configured threshold in the code explains it.
vi.mock('@inertiajs/vue3', () => ({ router: { get: vi.fn() } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><main><slot /></main></div>' },
}));

import ReportsIndex from '@/Pages/Reports/Index.vue';

const props = {
    year: 2026, availableYears: [2026, 2025],
    months: [{ label: 'Jan', admissions: 5, discharges: 4, icu: 1, deaths: 0, lsp: 10 }],
    totals: { admissions: 5, discharges: 4, icu: 1, deaths: 0, mortalityRate: 0, lsp: 10 },
    avgLos: 3.5, icuLos: 4.1, topDx: [], perConsultant: [], destinations: [], perConsultantLos: [],
    generatedAt: 'Thu, 24 Sep 2026 · 10:00',
};

describe('Reports/Index — "Long-stay %" info mark', () => {
    it('adds an info mark to the Long-stay % KPI card only', () => {
        const w = mount(ReportsIndex, { props });
        const lspCard = w.findAll('div').find((d) => d.text().startsWith('Long-stay %') && d.find('button[data-infotip]').exists());
        expect(lspCard).toBeTruthy();
        // exactly one info mark on the whole KPI strip (only Long-stay % gets one)
        expect(w.findAll('button[data-infotip]').length).toBe(1);
    });
});
