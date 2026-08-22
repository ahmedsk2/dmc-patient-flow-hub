import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Dashboard from '@/Pages/Consultations/Dashboard.vue';
import { router } from '@inertiajs/vue3';

const baseProps = (over = {}) => ({
    canPick: false,
    filters: { specialty_id: null },
    specialties: [],
    scopeLabel: 'Cardiology',
    openCounts: { new: 1, active: 2, ongoing: 3, total: 6 },
    ageing: { b0_2: 1, b3_7: 2, b8_plus: 3, unknown: 4 },
    generatedAt: '22 Aug 2026, 09:15 Asia/Riyadh',
    ...over,
});

const mount = (over = {}) => shallowMount(Dashboard, {
    props: baseProps(over),
    global: { renderStubDefaultSlot: true },
});

beforeEach(() => router.get.mockClear());

// W4 — the physician dashboard renders server-scoped figures and re-derives NOTHING: every count
// on it was already filtered by Consultation::scopeVisibleTo and the open/soft-delete invariants.
describe('Consultations/Dashboard — server-scoped figures', () => {
    const cellsOf = (w, section) => w.findAll(`section[aria-labelledby="${section}"] dd`).map((d) => d.text());

    it('renders the four open counts exactly as handed', () => {
        expect(cellsOf(mount(), 'open-heading')).toEqual(['1', '2', '3', '6']);
    });

    // The controller derives `total` from the grouped SQL rows, so it legitimately exceeds
    // new+active+ongoing when a row carries an unrecognised status. If this page ever recomputed the
    // sum client-side it would disagree with the ageing buckets, which counted that row.
    it('shows the server total verbatim and never recomputes it from the three named tiles', () => {
        const w = mount({ openCounts: { new: 1, active: 0, ongoing: 0, total: 2 } });
        expect(cellsOf(w, 'open-heading')).toEqual(['1', '0', '0', '2']);
    });

    it('renders all four ageing buckets, including the date-less rows', () => {
        expect(cellsOf(mount(), 'ageing-heading')).toEqual(['1', '2', '3', '4']);
        expect(mount().text()).toContain('No request date');
    });

    // The honesty requirement: date-less rows are reported separately, and the page SAYS so rather
    // than letting a reader assume every open consult was aged.
    it('states in plain language that date-less rows were not given a date', () => {
        expect(mount().text()).toContain('rather than being given a date they never had');
    });

    it('stamps the scope label and the generated-at time, which carries a day and a zone', () => {
        const text = mount().text();
        expect(text).toContain('Cardiology');
        expect(text).toContain('22 Aug 2026, 09:15 Asia/Riyadh');
    });
});

describe('Consultations/Dashboard — the specialty picker', () => {
    it('is hidden for a viewer the server did not grant the pick', () => {
        expect(mount().find('select').exists()).toBe(false);
    });

    it('is shown for a picker and reflects the server-side filter', () => {
        const w = mount({
            canPick: true, filters: { specialty_id: 7 },
            specialties: [{ id: 7, name: 'Nephrology' }, { id: 9, name: 'Cardiology' }],
        });
        const select = w.find('select');
        expect(select.exists()).toBe(true);
        expect(select.element.value).toBe('7');
        expect(select.findAll('option').map((o) => o.text()))
            .toEqual(['All specialties', 'Nephrology', 'Cardiology']);
    });

    it('navigates with specialty_id on a pick, and with no filter at all on "All specialties"', async () => {
        const w = mount({
            canPick: true, filters: { specialty_id: null },
            specialties: [{ id: 7, name: 'Nephrology' }],
        });
        const select = w.find('select');

        await select.setValue('7');
        expect(router.get).toHaveBeenLastCalledWith('/consultations/dashboard',
            { specialty_id: '7' }, expect.objectContaining({ replace: true }));

        await select.setValue('');
        expect(router.get).toHaveBeenLastCalledWith('/consultations/dashboard',
            {}, expect.objectContaining({ replace: true }));
    });
});
