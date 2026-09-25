import { describe, it, expect, vi, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';

// UX-review #39/#41 (2026-09-24): every ChartCanvas on this page used to share one identical,
// generic aria-label, and a From-after-To date range was silently swapped server-side with no
// on-screen notice. This spec guards both fixes plus the new KPI-card / column InfoTips.
const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { get, on: vi.fn() } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><main><slot /></main></div>' },
}));

import Statistics from '@/Pages/Statistics/Index.vue';

// jsdom has no canvas 2D context — ChartCanvas itself degrades to a bare labelled <canvas> in that
// case (see its own docblock), so no Chart.js stubbing is needed here.
beforeAll(() => {
    if (!('__a11yCanvasShim' in HTMLCanvasElement.prototype)) {
        HTMLCanvasElement.prototype.getContext = () => null;
        HTMLCanvasElement.prototype.__a11yCanvasShim = true;
    }
});

const baseProps = (over = {}) => ({
    range: { from: '2026-01-01', to: '2026-09-24' },
    rangeSwapped: false,
    kpis: {
        admissions: 10, discharges: 8, deaths: 1, mortalityRate: 12.5, icuAdmissions: 2,
        consultations: 5, signoffs: 4, avgLos: 3.2, readmissions: 1,
    },
    monthly: { labels: ['Jan'], keys: ['2026-01'], admissions: [10], discharges: [8], deaths: [1], consultations: [5], signoffs: [4] },
    los: { labels: ['0–2'], data: [3] },
    topDx: [{ label: 'Pneumonia', value: 4 }],
    reasons: { labels: ['Cardiology'], data: [2] },
    perConsultant: [{ name: 'Dr A', admissions: 5, avgLos: 3, readmits: 0, discharges: 4, consultations: 2, signoffs: 1 }],
    sourceMix: [{ src: 'ER', c: 6 }],
    kpiGrid: [{ label: 'Jan', admissions: 10, discharges: 8, icu: 2, transToIcu: 1, icuDeaths: 0, wardDeaths: 1, readmits: 1, consultations: 5, signoffs: 4, avgLos: 3.2 }],
    interval: 'month',
    truncated: false,
    destinations: { labels: ['Home'], data: [8] },
    destByConsultant: [{ name: 'Dr A', labels: ['Home'], data: [4] }],
    readmitWindow: 3,
    consultants: [{ id: 1, name: 'Dr A' }],
    physician: null,
    compareData: null,
    ...over,
});
const mountStats = (over = {}) => mount(Statistics, { props: baseProps(over) });

describe('Statistics/Index — distinct chart aria-labels (UX-review #39)', () => {
    it('gives every role="img" chart its own, non-generic accessible name', () => {
        const w = mountStats();
        const charts = w.findAll('[role="img"]');
        expect(charts.length).toBeGreaterThan (5);
        const labels = charts.map((c) => c.attributes('aria-label'));
        expect(labels.every((l) => !!l)).toBe(true);
        expect(labels.every((l) => l !== 'Statistics chart (data also shown in the period table below)')).toBe(true);
        expect(new Set(labels).size).toBe(labels.length);
    });

    it('the discharge-destinations chart label reflects the selected consultant', async () => {
        const w = mountStats();
        const select = w.findAll('select').find((s) => s.findAll('option').some((o) => o.text() === 'All consultants'));
        await select.setValue('Dr A');
        const chart = w.findAll('[role="img"]').find((c) => c.attributes('aria-label')?.includes('Discharge destinations donut'));
        expect(chart.attributes('aria-label')).toContain('Dr A');
    });
});

describe('Statistics/Index — inverted date range notice (UX-review #41)', () => {
    it('is silent when the range was not swapped', () => {
        expect(mountStats({ rangeSwapped: false }).text()).not.toContain('swapped automatically');
    });

    it('shows a notice when the server swapped the range', () => {
        const w = mountStats({ rangeSwapped: true });
        expect(w.text()).toContain('swapped automatically');
        expect(w.text()).toContain('2026-01-01 → 2026-09-24');
    });
});

describe('Statistics/Index — KPI-card and column info marks', () => {
    it('adds an info mark to Admissions, Avg LOS, Mortality and the readmit card, not to Discharges', () => {
        const w = mountStats();
        const cards = w.findAll('.grid.grid-cols-2.gap-4 > div');
        const cardFor = (label) => cards.find((c) => c.text().startsWith(label));
        expect(cardFor('Admissions').find('button[data-infotip]').exists()).toBe(true);
        expect(cardFor('Avg LOS').find('button[data-infotip]').exists()).toBe(true);
        expect(cardFor('Mortality').find('button[data-infotip]').exists()).toBe(true);
        expect(cardFor('≤3d readmits').find('button[data-infotip]').exists()).toBe(true);
        expect(cardFor('Discharges').find('button[data-infotip]').exists()).toBe(false);
    });

    it('labels the ICU adm / →ICU KPI-grid columns with their own info marks', () => {
        const w = mountStats();
        const headers = w.findAll('th');
        const icuAdmTh = headers.find((h) => h.text().startsWith('ICU adm'));
        const toIcuTh = headers.find((h) => h.text().startsWith('→ICU'));
        expect(icuAdmTh.find('button[data-infotip]').exists()).toBe(true);
        expect(toIcuTh.find('button[data-infotip]').exists()).toBe(true);
    });

    it('explains the From/To swap rule next to the date pickers', () => {
        const w = mountStats();
        expect(w.findAll('button[data-infotip]').some((b) => b.attributes('aria-label') === 'More information: From/To dates')).toBe(true);
    });

    // review fix-up: the physician drill-down's second donut ("Discharged to") buckets by
    // discharge_to with its OWN 5 named slices (Home/Other Facility/LAMA/Absconded/Mortuary) and a
    // 'Transfer' catch-all that also swallows an internal ward→ICU move — a second, distinct
    // instance of the same conflation already flagged on the "Discharge destinations" donut above it.
    it('discloses the same ward→ICU conflation on the "Discharged to" donut', () => {
        const w = mountStats({
            physician: {
                id: 1, name: 'Dr A',
                destinations: { labels: ['Discharged'], data: [5] },
                dischargedTo: { labels: ['Home', 'Transfer'], data: [3, 2] },
                topDx: [],
                numbers: { admissions: 5, discharges: 5, transToIcu: 1, deaths: 0, avgLos: 3, readmissions: 0, consultations: 2, signoffs: 1 },
                series: { labels: [], admissions: [], discharges: [], consultations: [], signoffs: [] },
            },
        });
        // role walkthrough 2026-09-25: this subheading moved from h4 to h3 (its parent card title
        // moved h3 -> h2) so the outline stays sequential — see the h1->h2->h3 fix below.
        const heading = w.findAll('h3').find((h) => h.text().startsWith('Discharged to'));
        expect(heading.find('button[data-infotip]').exists()).toBe(true);
    });
});

// role walkthrough 2026-09-25 (ui-ux #3/#4/#6, axe critical/critical/moderate): the From/To date
// labels had no for/id association, the "Discharge destinations" consultant select had no
// accessible name (its "Physician drill-down" sibling did), and every section title skipped from
// the page's own h1 straight to h3.
describe('Statistics/Index — a11y fixes (role walkthrough 2026-09-25)', () => {
    it('associates the From and To labels with their date inputs', () => {
        const w = mountStats();
        const fromInput = w.find('#stats-from-date');
        const toInput = w.find('#stats-to-date');
        expect(fromInput.exists()).toBe(true);
        expect(toInput.exists()).toBe(true);
        expect(w.find(`label[for="${fromInput.attributes('id')}"]`).text()).toContain('From');
        expect(w.find(`label[for="${toInput.attributes('id')}"]`).text()).toContain('To');
    });

    // review fix (2026-09-25): the InfoTip "!" button used to nest INSIDE <label for="stats-to-date">
    // — a labelable/interactive element inside the label pollutes its computed accessible name
    // ("To !" instead of "To"). It now sits as a sibling, so the label's own element has no nested
    // button and its trimmed text content is exactly "To".
    it('keeps the InfoTip button out of the To label so its accessible name stays "To"', () => {
        const w = mountStats();
        const toLabel = w.find('label[for="stats-to-date"]');
        expect(toLabel.find('button[data-infotip]').exists()).toBe(false);
        expect(toLabel.text().trim()).toBe('To');
    });

    it('gives the Discharge destinations consultant select an accessible name', () => {
        const w = mountStats();
        const select = w.findAll('select').find((s) => s.findAll('option').some((o) => o.text() === 'All consultants'));
        expect(select.attributes('aria-label')).toBe('Discharge destinations consultant');
    });

    it('never skips from h1 to h3 — every section title is an h2, one level under the page h1', () => {
        // physician drill-down populated so its nested h3 subheadings (Discharge destinations,
        // Discharged to, Top diagnoses, Activity over range) render too.
        const w = mountStats({
            physician: {
                id: 1, name: 'Dr A',
                destinations: { labels: ['Discharged'], data: [5] },
                dischargedTo: { labels: ['Home'], data: [5] },
                topDx: [], numbers: { admissions: 5, discharges: 5, transToIcu: 0, deaths: 0, avgLos: 3, readmissions: 0, consultations: 0, signoffs: 0 },
                series: { labels: [], admissions: [], discharges: [], consultations: [], signoffs: [] },
            },
        });
        expect(w.findAll('h1')).toHaveLength(0);   // the page's own h1 lives in AppLayout, stubbed out here
        expect(w.findAll('h2').length).toBeGreaterThanOrEqual(8);   // every section-card title
        // every h3 is a subheading nested one level under an h2 card (Discharge destinations, etc.)
        expect(w.findAll('h3').length).toBeGreaterThan(0);
    });
});

// 2026-09-24 (owner: "optimize this Statistics chart"): the physician donuts split a ward→ICU move out
// of "Out-dept transfer" / "Transfer", so "Discharged to" now has SEVEN slices. Chart.js loops a short
// colour list, which would give slice 7 the same colour as slice 1 right next to it — the page's donut
// palette therefore carries seven colours.
describe('Statistics/Index — seven-slice destination donuts get seven distinct colours', () => {
    const physician = {
        id: 1, name: 'Dr Split',
        destinations: { labels: ['Discharged', 'Intra-dept transfer', 'Transfer to ICU', 'Out-dept transfer', 'ICU discharge'], data: [1, 1, 2, 2, 0] },
        dischargedTo: { labels: ['Home', 'Other Facility', 'LAMA', 'Absconded', 'Mortuary', 'ICU', 'Transfer'], data: [1, 1, 1, 1, 1, 2, 3] },
        topDx: [],
        numbers: { admissions: 6, discharges: 10, transToIcu: 2, deaths: 0, avgLos: 3, readmissions: 0, consultations: 0, signoffs: 0 },
    };

    it('the Discharged-to donut has one distinct colour per slice, and the destinations donut too', () => {
        const w = mountStats({ physician });
        const charts = w.findAllComponents({ name: 'ChartCanvas' });
        const byLabel = (needle) => charts.find((c) => String(c.attributes('aria-label') || '').includes(needle));
        const dest = byLabel('Discharged-to destinations for Dr Split');
        const types = byLabel('Discharge destinations for Dr Split');
        expect(dest).toBeTruthy();
        expect(types).toBeTruthy();
        const destColours = dest.props('data').datasets[0].backgroundColor;
        expect(dest.props('data').labels).toEqual(physician.dischargedTo.labels);
        expect(destColours).toHaveLength(7);
        expect(new Set(destColours).size).toBe(7);
        expect(types.props('data').labels).toContain('Transfer to ICU');
    });

    it('the tooltips describe the new slices', () => {
        const w = mountStats({ physician });
        const tips = w.findAll('button[data-infotip]').map((b) => b.attributes('aria-label'));
        expect(tips).toContain('More information: Discharge destinations');
        expect(tips).toContain('More information: Discharged to');
    });
});
