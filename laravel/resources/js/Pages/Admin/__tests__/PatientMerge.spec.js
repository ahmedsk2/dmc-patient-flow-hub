import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

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
const { post, ask } = vi.hoisted(() => ({ post: vi.fn(), ask: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => ({ ...obj, post, errors: {}, processing: false }),
}));
// #11 (2026-09-24 role/UX review): the final merge confirmation used to be the browser's native
// window.confirm() — the one holdout among the app's destructive actions. Mocking useConfirm lets
// the specs below drive that decision deterministically instead of stubbing window.confirm.
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));

import PatientMerge from '@/Pages/Admin/PatientMerge.vue';
import PatientPicker from '@/Components/PatientPicker.vue';

const mountPage = (possibleDuplicates = []) => mount(PatientMerge, { props: { possibleDuplicates } });

beforeEach(() => { post.mockClear(); ask.mockReset(); });

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

// #11 (2026-09-24 role/UX review): the final merge confirmation used window.confirm() while every
// other destructive action in the app (delete user, discharge, MFA reset…) uses the themed,
// accessible ConfirmDialog via useConfirm(). confirmMerge() must now go through `ask`.
describe('PatientMerge — themed confirm on merge (Problem #11)', () => {
    const setPreview = (w) => {
        w.vm.preview = {
            source: { id: 1, mrn: '111', name: 'A', admissions: 2, consultations: 1, has_open_admission: false },
            target: { id: 2, mrn: '222', name: 'B', admissions: 0, consultations: 0, has_open_admission: false },
        };
    };

    it('asks via the themed dialog (danger tone) naming both patients, and posts only once confirmed', async () => {
        const w = mountPage();
        setPreview(w);
        ask.mockResolvedValue(true);

        await w.vm.confirmMerge();
        await flushPromises();

        expect(ask).toHaveBeenCalledTimes(1);
        const [title, body, tone] = ask.mock.calls[0];
        expect(title).toContain('#2');
        expect(body).toContain('#1 (111)');
        expect(body).toContain('#2 (222)');
        expect(tone).toBe('danger');
        expect(post).toHaveBeenCalledWith('/admin/patient-merge', expect.objectContaining({ preserveScroll: true }));
    });

    it('declining the themed confirm does not post the merge', async () => {
        const w = mountPage();
        setPreview(w);
        ask.mockResolvedValue(false);

        await w.vm.confirmMerge();
        await flushPromises();

        expect(ask).toHaveBeenCalledTimes(1);
        expect(post).not.toHaveBeenCalled();
    });
});
