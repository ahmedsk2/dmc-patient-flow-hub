import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// Role/UX review 2026-09-24 fix-up round: an adversarial reviewer noted the queue assign modal's
// "Mark as new patient" InfoTip (#27) explained only its OWN local checked/unchecked behaviour, not
// that the board's assign/reassign dialogs (ActionModal.vue / ReassignModal.vue, owned by g2) default
// the identically-named checkbox to the OPPOSITE value. The tooltip text now names that discrepancy
// directly. Verified against the board components: ActionModal.vue `mark_new: true` and
// ReassignModal.vue `mark_new: true`, vs. this page's `mark_new: false` (AdmissionsIndex.wave2.test.js).

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn(() => Promise.resolve(true)) }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import AdmissionsIndex from '@/Pages/Admissions/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { assign: true, add: true, manage: true, modify: true } };
const patient = { id: 3, name: 'Test Patient', mrn: '30000001', admit_date: '2026-09-24' };
const props = { queue: [patient], icuPatients: [], consultants: [{ id: 5, name: 'Dr A' }], countries: [] };

beforeEach(() => { authUser = admin; post.mockClear(); });

describe('Admissions/Index — "Mark as new patient" InfoTip after the #27 review fix-up', () => {
    it('names the cross-dialog default discrepancy, not just this dialog\'s own behaviour', async () => {
        const w = mount(AdmissionsIndex, { props });
        await w.vm.openAssign(patient);
        await w.vm.$nextTick();

        const tip = w.find('button[aria-label="More information: Mark as new patient"]');
        expect(tip.exists()).toBe(true);
        await tip.trigger('click');
        const bubble = document.body.querySelector('[data-infotip-bubble]');
        expect(bubble).toBeTruthy();
        expect(bubble.textContent).toContain('defaults it unticked');
        expect(bubble.textContent).toContain('board');
        expect(bubble.textContent).toMatch(/ticked instead/);
    });

    it('still defaults mark_new to false in this dialog (unchanged — only the explanation changed)', async () => {
        const w = mount(AdmissionsIndex, { props });
        await w.vm.openAssign(patient);
        expect(w.vm.aForm.mark_new).toBe(false);
    });
});
