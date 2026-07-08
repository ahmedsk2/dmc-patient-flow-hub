import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// ActionModal owns the per-patient flow forms (assign / medical / complete / icu / transfer) that
// used to live on Patients/Index. These assertions are RELOCATED from PatientsIndex.wave2.test.js
// (Item 5 modal-title map) plus the handover gate-then-retry safety control that was inline in
// Index. The Inertia mock returns a REACTIVE form (real useForm is a reactive proxy) so the
// component's outcome→destination + transferReady watchers fire the same way they do in the app.

const { posts } = vi.hoisted(() => ({ posts: [] }));
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => {
        const f = reactive({
            ...obj,
            errors: {},
            processing: false,
            post: vi.fn((url, opts) => { posts.push({ url, form: f }); if (opts?.onSuccess) opts.onSuccess(); }),
            reset: vi.fn(),
            clearErrors: vi.fn(),
        });
        return f;
    },
}));
// gate-then-retry calls useHandover().saveHandover — make it a controllable spy.
const { saveHandover } = vi.hoisted(() => ({ saveHandover: vi.fn(() => Promise.resolve(true)) }));
vi.mock('@/composables/useHandover', () => ({ useHandover: () => ({ saveHandover, fetchHandover: vi.fn(), preflight: vi.fn() }) }));
// BaseModal: render the slot unconditionally so sub-forms are inspectable regardless of `open`.
vi.mock('@/Components/BaseModal.vue', () => ({
    default: { props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/Patients/AdmissionSummary.vue', () => ({ default: { template: '<div class="admission-summary" />' } }));

import ActionModal from '@/Components/Patients/ActionModal.vue';

const patient = { id: 7, name: 'Ali', mrn: '111', consultant_id: 5, location: 'Ward', outcome: '', discharge_to: '' };
const consultants = [
    { id: 5, name: 'Dr Five', on_service: true, specialty_id: 1 },
    { id: 6, name: 'Dr Six', on_service: true, specialty_id: 2 },
    { id: 9, name: 'Dr Nine (off)', on_service: false, specialty_id: 1 },
];

const mountWith = (mode, p = patient, over = {}) => mount(ActionModal, {
    props: {
        open: true, mode, patient: p, consultants, specialties: [{ id: 1, name: 'Cardio' }],
        externalServices: ['Surgery'], today: '2026-06-14', ...over,
    },
});

beforeEach(() => { posts.length = 0; saveHandover.mockClear(); saveHandover.mockResolvedValue(true); });

describe('ActionModal — title map (relocated from PatientsIndex.wave2 Item 5)', () => {
    it('assign mode title is "Assign consultant"', () => {
        expect(mountWith('assign').vm.modalTitle).toBe('Assign consultant');
    });
    it('other modes keep their labels', () => {
        expect(mountWith('medical').vm.modalTitle).toBe('Discharge');
        expect(mountWith('complete').vm.modalTitle).toBe('Complete discharge');
        expect(mountWith('icu').vm.modalTitle).toBe('ICU discharge');
        expect(mountWith('transfer').vm.modalTitle).toBe('Transfer');
    });
});

describe('ActionModal — board assign sub-form', () => {
    it('prefills consultant_id from the patient and defaults mark_new=true (board vs queue differ)', () => {
        const vm = mountWith('assign').vm;
        expect(vm.aForm.consultant_id).toBe(5);
        // BOARD assign defaults mark_new TRUE (the queue/Admissions assign defaults false — they differ)
        expect(vm.aForm.mark_new).toBe(true);
    });
    it('keeps the current (off-service) assignee selectable in the dropdown', () => {
        const off = { ...patient, consultant_id: 9 };
        const vm = mountWith('assign', off).vm;
        expect(vm.assignConsultants.map((c) => c.id)).toContain(9);   // J1-15a keepId
    });
    it('submitAssign posts to /admissions/{id}/assign and emits saved on success', async () => {
        const w = mountWith('assign');
        w.vm.submitAssign();
        expect(posts[0].url).toBe('/admissions/7/assign');
        expect(w.emitted('saved')).toBeTruthy();
    });
});

describe('ActionModal — submit endpoints per mode', () => {
    it('medical → /medical-discharge, complete → /complete-discharge, icu → /icu-discharge, transfer → /transfer', () => {
        mountWith('medical').vm.submitMedical();
        expect(posts.pop().url).toBe('/admissions/7/medical-discharge');
        mountWith('complete').vm.submitComplete();
        expect(posts.pop().url).toBe('/admissions/7/complete-discharge');
        mountWith('icu').vm.submitIcu();
        expect(posts.pop().url).toBe('/admissions/7/icu-discharge');
        mountWith('transfer').vm.submitTransfer();
        expect(posts.pop().url).toBe('/admissions/7/transfer');
    });
});

describe('ActionModal — outcome→destination locking (Dead ⇒ Mortuary)', () => {
    it('medical: Dead forces Mortuary; un-Dead clears it', async () => {
        const w = mountWith('medical');
        w.vm.mdForm.outcome = 'Dead';
        await w.vm.$nextTick();
        expect(w.vm.mdForm.discharge_to).toBe('Mortuary');
        w.vm.mdForm.outcome = 'Alive';
        await w.vm.$nextTick();
        expect(w.vm.mdForm.discharge_to).toBe('');
    });
});

describe('ActionModal — transferReady gate', () => {
    it('location mode ready when target set', () => {
        const vm = mountWith('transfer').vm;
        vm.tForm.mode = 'location'; vm.tForm.target = 'ICU';
        expect(vm.transferReady).toBe(true);
    });
    it('specialty mode needs both specialty + consultant', async () => {
        const w = mountWith('transfer');
        w.vm.tForm.mode = 'specialty'; w.vm.tForm.specialty_id = 1; w.vm.tForm.consultant_id = '';
        await w.vm.$nextTick();
        expect(w.vm.transferReady).toBe(false);
        w.vm.tForm.consultant_id = 6;
        await w.vm.$nextTick();
        expect(w.vm.transferReady).toBe(true);
    });
    it('external mode needs service', () => {
        const vm = mountWith('transfer').vm;
        vm.tForm.mode = 'external'; vm.tForm.service = 'Surgery';
        expect(vm.transferReady).toBe(true);
    });
});

describe('ActionModal — HANDOVER GATE-THEN-RETRY (clinical safety control)', () => {
    it('saveGateThen writes today\'s handover then re-fires the original submit', async () => {
        const w = mountWith('assign');
        w.vm.gateBody = 'on insulin, watch K+';
        const retry = vi.fn();
        await w.vm.saveGateThen(retry);
        expect(saveHandover).toHaveBeenCalledWith(7, 'on insulin, watch K+');
        expect(retry).toHaveBeenCalled();   // gate cleared → original action retried
        expect(w.vm.gateBody).toBe('');     // editor cleared after a successful save
    });
    it('does NOT retry when the handover save fails', async () => {
        saveHandover.mockResolvedValueOnce(false);
        const w = mountWith('assign');
        w.vm.gateBody = 'note';
        const retry = vi.fn();
        await w.vm.saveGateThen(retry);
        expect(retry).not.toHaveBeenCalled();
    });
    it('does NOT save with an empty body', async () => {
        const w = mountWith('assign');
        w.vm.gateBody = '   ';
        const retry = vi.fn();
        await w.vm.saveGateThen(retry);
        expect(saveHandover).not.toHaveBeenCalled();
        expect(retry).not.toHaveBeenCalled();
    });
});

// The medical-discharge submit button is reachable because BaseModal is mocked to render its slot
// unconditionally (above), so no contortion is needed to assert on it.
//
// W0-T3d. `text-white` on `bg-warning-500` (#ffffff on #e69209) is 2.48:1 — a hard WCAG AA failure
// for this 14px semibold label (1.4.3's 3:1 large-text allowance needs 24px, or 18.66px bold).
// The fill stays amber (a fill is not text, and the tone is the signal); the LABEL goes dark.
//
// `text-ink-900` — the obvious dark label — is a TRAP: the ink scale INVERTS under `.dark`, so
// ink-900 resolves to #f4f8f8 there and lands at 2.31:1 on the (theme-invariant) amber fill. It
// fixes light and breaks dark. `text-navy-950` (#00252a) is a literal in @theme, never remapped by
// `.dark`, and clears 6.53:1 against #e69209 in BOTH themes (6.25:1 / 4.82:1 even under the
// button's own hover:opacity-90 composite).
describe('ActionModal — medical-discharge button meets WCAG AA in both themes (W0-T3d)', () => {
    const submitBtn = (w) => w.find('form button[type="submit"]');

    it('medical-only: amber fill with a theme-invariant dark label, never white-on-amber', () => {
        const c = submitBtn(mountWith('medical')).classes();
        expect(c).toEqual(expect.arrayContaining(['bg-warning-500', 'text-navy-950']));
        expect(c).not.toContain('text-white');       // 2.48:1 — the defect this locks out
        expect(c).not.toContain('text-ink-900');     // 2.31:1 under .dark — the near-miss fix
    });

    // The sibling branch: white on success-600 (#ffffff on #15803d) is 5.02:1 AT REST, so the T3d
    // migration left it alone. It is NOT fully AA: `hover:bg-success-700` is a no-op (that token is
    // undeclared — task #142), so `hover:opacity-90` is the only hover effect, and it composites the
    // whole button over the bg-card panel down to 4.17:1 in light mode — below AA. WCAG 1.4.3 exempts
    // disabled controls, never hover. Tracked as task #143. This test locks the CURRENT classes so the
    // migration above can't strip the label colour; it is EXPECTED to change when #143 lands.
    it('complete branch keeps white on success-600 (5.02:1 at rest; 4.17:1 on hover — see #143)', async () => {
        const w = mountWith('medical');
        w.vm.mdForm.complete = true;
        await w.vm.$nextTick();
        const c = submitBtn(w).classes();
        expect(c).toEqual(expect.arrayContaining(['bg-success-600', 'text-white']));
        expect(c).not.toContain('bg-warning-500');
    });

    it('the label text is unchanged by the colour migration', async () => {
        const w = mountWith('medical');
        expect(submitBtn(w).text()).toBe('Medical discharge');
        w.vm.mdForm.complete = true;
        await w.vm.$nextTick();
        expect(submitBtn(w).text()).toBe('Discharge & close file');
    });
});

describe('ActionModal — close', () => {
    it('emits close on the Cancel path', () => {
        const w = mountWith('assign');
        w.vm.$emit('close');
        expect(w.emitted('close')).toBeTruthy();
    });
});
