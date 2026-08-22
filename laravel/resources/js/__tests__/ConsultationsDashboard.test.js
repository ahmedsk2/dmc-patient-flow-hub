import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// The page navigates with router.visit and reads no shared page props beyond auth; usePage is
// stubbed so the component mounts outside an Inertia app.
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
// the chart-theme composable reads CSS custom properties — stub to inert refs.
vi.mock('@/composables/useChartTheme', () => ({
    useChartTheme: () => ({
        gridColor: { value: '#000' }, axisColor: { value: '#000' }, strokeColor: { value: '#000' }, inkColor: { value: '#000' },
        series: { value: { primary: '#009ca6', accent: '#d9a23c', deep: '#00565e', info: '#2f7fe0', muted: '#5b6a6e', primarySoft: '#38b4ba' } },
    }),
}));

import ConsultationsDashboard from '@/Pages/Consultations/Dashboard.vue';
import { router } from '@inertiajs/vue3';

const baseProps = (over = {}) => ({
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

const mountAs = (user, over = {}) => {
    authUser = user;
    return shallowMount(ConsultationsDashboard, {
        props: baseProps(over),
        global: { renderStubDefaultSlot: true, stubs: { apexchart: true, teleport: true } },
    });
};

describe('Consultations dashboard — scope header + picker', () => {
    it('shows the scope label and hides the specialty picker for a plain consultant', () => {
        const w = mountAs({ role: 3, is_admin: false });
        expect(w.text()).toContain('Cardiology');
        expect(w.find('select[data-testid="specialty-picker"]').exists()).toBe(false);
    });

    it('renders the specialty picker for a picker (admin / coordinator) and navigates on change', async () => {
        router.visit.mockClear();
        const w = mountAs({ role: 0, is_admin: true }, {
            canPick: true,
            scopeLabel: 'All specialties',
            specialties: [{ id: 2, name: 'Cardiology' }, { id: 3, name: 'Nephrology' }],
        });
        const picker = w.find('select[data-testid="specialty-picker"]');
        expect(picker.exists()).toBe(true);
        // "All specialties" + the two options
        expect(picker.findAll('option')).toHaveLength(3);

        await picker.setValue('3');
        expect(router.visit).toHaveBeenCalledWith('/consultations/dashboard', { data: { specialty_id: '3' } });
    });
});

describe('Consultations dashboard — honesty of the turnaround block', () => {
    it('renders the visible "from cutover" note naming the excluded historical count', () => {
        const note = mountAs({ role: 3, is_admin: false }).find('[data-testid="cutover-note"]');
        expect(note.exists()).toBe(true);
        expect(note.text()).toContain('from cutover');
        expect(note.text()).toContain('1283');
        // the note must be VISIBLE, not screen-reader-only — the number is the caveat
        expect(note.classes()).not.toContain('sr-only');
    });

    it('renders the note even when nothing was excluded, so the window is always stated', () => {
        const w = mountAs({ role: 3, is_admin: false }, {
            turnaround: { first_followup_hours: 1.0, first_followup_n: 2, signoff_hours: 4.0, signoff_n: 2, legacy_excluded: 0, from_cutover: true },
        });
        expect(w.find('[data-testid="cutover-note"]').text()).toContain('from cutover');
    });

    it('shows a "not enough data yet" placeholder instead of a zero when a median is null', () => {
        const w = mountAs({ role: 3, is_admin: false }, {
            turnaround: { first_followup_hours: null, first_followup_n: 0, signoff_hours: null, signoff_n: 0, legacy_excluded: 1283, from_cutover: true },
        });
        const values = w.findAll('[data-testid="turnaround-value"]').map((n) => n.text());
        expect(values).toEqual(['Not enough data yet', 'Not enough data yet']);
        expect(values.join(' ')).not.toContain('0.0');
    });

    it('formats a real median in hours', () => {
        const values = mountAs({ role: 3, is_admin: false })
            .findAll('[data-testid="turnaround-value"]').map((n) => n.text());
        expect(values[0]).toBe('2.5 h');
        expect(values[1]).toBe('30 h');
    });
});

describe('Consultations dashboard — worklist + load', () => {
    it('states today\'s completeness as "seen X of Y"', () => {
        expect(mountAs({ role: 3, is_admin: false }).find('[data-testid="today-completeness"]').text())
            .toContain('3 of 4');
    });

    it('reads 0 of 0 without a NaN when nothing is on the daily worklist', () => {
        const w = mountAs({ role: 3, is_admin: false }, { today: { due: 0, seen: 0 } });
        const text = w.find('[data-testid="today-completeness"]').text();
        expect(text).toContain('0 of 0');
        expect(text).not.toContain('NaN');
    });

    it('lists per-consultant load rows and drills into the workspace filtered to that consultant', async () => {
        router.visit.mockClear();
        const w = mountAs({ role: 3, is_admin: false });
        const rows = w.findAll('[data-testid="load-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Dr Busy');

        await rows[0].trigger('click');
        expect(router.visit).toHaveBeenCalledWith('/consultations', { data: { consultant_id: 7 } });
    });

    it('renders one row per top indication', () => {
        expect(mountAs({ role: 3, is_admin: false }).findAll('[data-testid="indication-row"]')).toHaveLength(2);
    });

    it('renders a calm empty note instead of a chart when the trend has no data', () => {
        const w = mountAs({ role: 3, is_admin: false }, { trend: { labels: [], data: [] } });
        expect(w.text()).toContain('No data for this period.');
    });
});
