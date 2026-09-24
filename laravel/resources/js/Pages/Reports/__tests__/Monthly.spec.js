import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// UX-review #10 (2026-09-24): a day after today used to render as a flat 0 in the current month's
// report, indistinguishable from a real zero-activity day. The controller now marks those rows
// `future` (counts null); the page shows "—" for them and a "Data through <date>" note.
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a href="#"><slot /></a>' },
    router: { get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title'], template: '<div><main><slot /></main></div>' },
}));

import Monthly from '@/Pages/Reports/Monthly.vue';

const day = (n, over = {}) => ({
    day: n, weekday: 'Mon', admissions: 2, discharges: 1, icu: 0, deaths: 0, future: false, ...over,
});
const baseProps = (over = {}) => ({
    year: 2026, month: 9, monthName: 'September',
    days: [day(1), day(2)],
    totals: { admissions: 4, discharges: 2, icu: 0, deaths: 0 },
    asOf: '2026-09-24', generatedAt: 'Thu, 24 Sep 2026 · 10:00', availableYears: [2026, 2025],
    ...over,
});

describe('Reports/Monthly — future-day rows (UX-review #10)', () => {
    it('shows no "Data through" note and real numbers when no day is in the future', () => {
        const w = mount(Monthly, { props: baseProps() });
        expect(w.text()).not.toContain('Data through');
        expect(w.text()).toContain('2');
    });

    it('shows "—" for a future day and the "Data through" note, once any day is future', () => {
        const w = mount(Monthly, { props: baseProps({
            days: [day(1), day(2, { admissions: null, discharges: null, icu: null, deaths: null, future: true })],
        }) });
        expect(w.text()).toContain('Data through 2026-09-24');
        const rows = w.findAll('tbody tr');
        // second body row is the future day (the totals row is separate, inside the same tbody)
        expect(rows[1].text()).toContain('—');
        expect(rows[1].text()).not.toMatch(/\b0\b/);
    });

    it('never shows "—" in the day-by-day table for a fully-elapsed past month', () => {
        const w = mount(Monthly, { props: baseProps({ month: 6, monthName: 'June', days: [day(1), day(2)] }) });
        expect(w.find('table').text()).not.toContain('—');
    });
});
