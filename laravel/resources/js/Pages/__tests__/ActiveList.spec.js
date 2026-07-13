import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Stub the heavy layout + the flags component — this spec is about the print filter / count-table
// gating / width, not the chrome. ActiveList uses no Inertia primitives directly.
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', template: '<div><slot /></div>' } }));
vi.mock('@/Components/PatientFlags.vue', () => ({ default: { name: 'PatientFlags', props: ['patient', 'readmitWindow', 'variant'], template: '<span />' } }));

import ActiveList from '@/Pages/ActiveList.vue';

const group = (id, name) => ({
    id, name, on_service: true, specialty_id: 1,
    counts: { old: 1, new: 1, active: 2, ward: 2, icu: 0, tb: 0, total: 2 },
    patients: [{ id: id * 10 + 1, mrn: `100${id}`, name: `${name} P1`, bed: 'W-1', age: 40, gender: 'Male', location: 'Ward', admit_date: '2026-06-01', los: 3, diagnoses: [] }],
});
const props = (over = {}) => ({
    groups: [group(5, 'Alpha'), group(6, 'Beta')],
    readmitWindow: 3, generatedAt: 'Mon, 13 Jul 2026 · 10:00', ...over,
});
const mountList = (p = props()) => mount(ActiveList, { props: p });

describe('ActiveList — print consultant filter', () => {
    it('defaults to All: shows the per-consultant count table + every consultant section', () => {
        const w = mountList();
        expect(w.text()).toContain('Patient count per consultant');
        expect(w.text()).toContain('Dr. Alpha');
        expect(w.text()).toContain('Dr. Beta');
    });

    it('offers "All consultants" plus one option per consultant', () => {
        const opts = mountList().findAll('option').map((o) => o.text());
        expect(opts[0]).toContain('All consultants');
        expect(opts.some((t) => t.includes('Dr. Alpha'))).toBe(true);
        expect(opts.some((t) => t.includes('Dr. Beta'))).toBe(true);
    });

    it('selecting one consultant hides the count table + the other consultant, and labels the header', async () => {
        const w = mountList();
        await w.find('select').setValue(5);   // Dr. Alpha
        // scope to the printable document — the toolbar <select> still lists every consultant as an option
        const report = w.find('.report').text();
        expect(report).not.toContain('Patient count per consultant');
        expect(report).toContain('Dr. Alpha');
        expect(report).not.toContain('Dr. Beta');
        expect(report).toContain('— Dr. Alpha');   // printed header self-labels the single consultant
    });

    it('the document-header total tracks the selection (whole-ward 4 → one consultant 2)', async () => {
        const w = mountList();
        expect(w.text()).toContain('4 patient(s)');   // 2 groups × total 2, only the doc header can be 4
        await w.find('select').setValue(5);
        expect(w.text()).not.toContain('4 patient(s)');
    });

    it('renders full-width so columns can expand to their text (up to 100%)', () => {
        expect(mountList().find('.report').classes()).toContain('max-w-full');
    });
});
