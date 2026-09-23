import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * Admin → Patient Merge — regression spec for the 2026-09-23 walkthrough defect: the two
 * "Source (will be retired)" / "Target (canonical record)" patient pickers rendered as nothing
 * (<!----><!---->) in production. Cause: PatientMerge.vue defined its local PatientPicker with an
 * options-API `template:` STRING; the production bundle ships Vue's runtime-only build (no
 * template compiler — and CSP forbids 'unsafe-eval' regardless), so Vue rendered a comment node
 * instead of the picker. No spec ever mounted this page before, so nothing caught it: Vitest's own
 * Vue build DOES include a compiler, which is exactly why a mount test — not just "it doesn't
 * throw" — is required here (a `<!---->` renders without error).
 *
 * PatientPicker is intentionally NOT mocked below: mocking it would hide the exact defect this
 * spec exists to catch.
 */
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => ({ ...obj, post: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));

import PatientMerge from '@/Pages/Admin/PatientMerge.vue';
import PatientPicker from '@/Components/PatientPicker.vue';

const mountPage = (possibleDuplicates = []) => mount(PatientMerge, { props: { possibleDuplicates } });

describe('PatientMerge — patient pickers actually render', () => {
    it('renders both pickers as real, labelled combobox inputs (not empty comment nodes)', () => {
        const w = mountPage();

        expect(w.text()).toContain('Source (will be retired)');
        expect(w.text()).toContain('Target (canonical record)');

        const inputs = w.findAll('input[role="combobox"]');
        expect(inputs).toHaveLength(2);
        inputs.forEach((i) => expect(i.attributes('placeholder')).toContain('Search MRN or name'));
    });

    it('picking a source patient updates that panel\'s selection', async () => {
        const w = mountPage();
        const pickers = w.findAllComponents(PatientPicker);
        expect(pickers).toHaveLength(2);
        const sourcePicker = pickers.find((c) => c.props('label') === 'Source (will be retired)');
        expect(sourcePicker).toBeTruthy();
        await sourcePicker.vm.$emit('pick', { id: 42, mrn: '4242', name: 'Jane Doe' });
        expect(w.text()).toContain('Selected:');
        expect(w.text()).toContain('4242');
    });

    it('lists the possible-duplicates worklist rows', () => {
        const w = mountPage([{ id1: 1, mrn1: '111', name1: 'A', id2: 2, mrn2: '222', name2: 'B', reason: 'normalized-mrn' }]);
        expect(w.text()).toContain('111');
        expect(w.text()).toContain('222');
    });
});
