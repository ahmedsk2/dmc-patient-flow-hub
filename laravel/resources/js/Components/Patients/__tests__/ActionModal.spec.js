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
// the unsaved-changes guard's ask() — controllable per test (Wave 3, Item 1/2).
const { ask } = vi.hoisted(() => ({ ask: vi.fn() }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
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

beforeEach(() => { posts.length = 0; saveHandover.mockClear(); saveHandover.mockResolvedValue(true); ask.mockReset(); });

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
// `.dark`, and clears 6.53:1 against #e69209 in BOTH themes.
//
// W0-T3e/f. `hover:opacity-90` is GONE from these buttons. CSS `opacity` composites the WHOLE
// element — fill *and* label — over its backdrop, so it silently degrades the label: white on
// success-600 drops 5.02:1 -> 4.17:1 over the light `bg-card` panel (4.68:1 dark). WCAG 1.4.3
// exempts `disabled` controls (hence `disabled:opacity-50` survives) but has NO hover exemption.
// The `-600`/`-700` steps are now really declared, so every hover is a genuine darker FILL:
//   white on success-700 #166534 = 7.13:1 · navy-950 on warning-600 #d58506 = 5.54:1.
//
// W0-T3h. warning-600 was #c87d06, which put the amber hover at 4.92:1 — BELOW its own 6.53:1 rest
// state, because darkening a fill under a near-black label can only reduce contrast. The step is now
// the lighter #d58506 (5.54:1) and remains a visible state change (dE76 6.55 from warning-500).
describe('ActionModal — medical-discharge button meets WCAG AA in both themes (W0-T3d/e/f)', () => {
    const submitBtn = (w) => w.find('form button[type="submit"]');

    it('medical-only: amber fill with a theme-invariant dark label, never white-on-amber', () => {
        const c = submitBtn(mountWith('medical')).classes();
        expect(c).toEqual(expect.arrayContaining(['bg-warning-500', 'text-navy-950']));
        expect(c).not.toContain('text-white');       // 2.48:1 — the defect this locks out
        expect(c).not.toContain('text-ink-900');     // 2.31:1 under .dark — the near-miss fix
    });

    it('medical-only: hovers with a real darker amber fill, not a whole-element opacity fade', () => {
        const c = submitBtn(mountWith('medical')).classes();
        expect(c).toContain('hover:bg-warning-600');   // 5.54:1 under text-navy-950
        expect(c).not.toContain('hover:opacity-90');   // composites the LABEL too — 1.4.3 has no hover exemption
    });

    // The sibling branch: white on success-600 (#ffffff on #15803d) is 5.02:1 at rest. Its hover is
    // now a real success-700 fill (7.13:1) rather than the opacity fade that took it to 4.17:1.
    it('complete branch keeps white on success-600 and hovers to a real success-700 fill', async () => {
        const w = mountWith('medical');
        w.vm.mdForm.complete = true;
        await w.vm.$nextTick();
        const c = submitBtn(w).classes();
        expect(c).toEqual(expect.arrayContaining(['bg-success-600', 'text-white', 'hover:bg-success-700']));
        expect(c).not.toContain('bg-warning-500');
        expect(c).not.toContain('hover:opacity-90');
    });

    // `disabled:opacity-50` is explicitly permitted by 1.4.3 (disabled controls are exempt) and is the
    // only remaining opacity utility on these buttons. Lock that distinction so a future sweep for
    // "opacity" doesn't remove the legitimate one — or reintroduce the illegitimate one.
    it.each(['medical', 'complete', 'icu'])('%s submit button: disabled fade kept, hover fade gone', (mode) => {
        const c = submitBtn(mountWith(mode)).classes();
        expect(c).toContain('disabled:opacity-50');
        expect(c.some((x) => x.startsWith('hover:opacity-'))).toBe(false);
    });

    it.each(['complete', 'icu'])('%s submit button hovers to success-700', (mode) => {
        expect(submitBtn(mountWith(mode)).classes()).toEqual(
            expect.arrayContaining(['bg-success-600', 'text-white', 'hover:bg-success-700']),
        );
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

// Wave 3, Item 1/2: the Cancel button (and BaseModal's `:dirty` prop) route through the SAME
// unsaved-changes guard, keyed off the ACTIVE mode's own form.isDirty.
describe('ActionModal — unsaved-changes guard (Cancel button)', () => {
    it('clean form: Cancel closes immediately without asking', async () => {
        const w = mountWith('assign');
        expect(w.vm.modalDirty).toBe(false);
        await w.find('button.text-ink-500').trigger('click');
        expect(ask).not.toHaveBeenCalled();
        expect(w.emitted('close')).toBeTruthy();
    });

    it('dirty form: Cancel asks (danger) before closing', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith('assign');
        w.vm.aForm.isDirty = true;
        await w.vm.$nextTick();
        expect(w.vm.modalDirty).toBe(true);
        await w.find('button.text-ink-500').trigger('click');
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.emitted('close')).toBeTruthy();
    });

    it('dirty form: declining the confirm keeps the modal open', async () => {
        ask.mockResolvedValue(false);
        const w = mountWith('assign');
        w.vm.aForm.isDirty = true;
        await w.vm.$nextTick();
        await w.find('button.text-ink-500').trigger('click');
        expect(ask).toHaveBeenCalledTimes(1);
        expect(w.emitted('close')).toBeFalsy();
    });

    it('BaseModal receives :dirty bound to the active mode form', async () => {
        const w = mountWith('medical');
        expect(w.vm.modalDirty).toBe(false);
        w.vm.mdForm.isDirty = true;
        await w.vm.$nextTick();
        expect(w.vm.modalDirty).toBe(true);
    });
});

// Wave 3, Item 4: ErrorSummary at the top of the active mode's form, mapped from that form's own
// .errors onto ids scoped to (uid, mode, field) — never the handover-gate error, which already has
// its own dedicated block.
describe('ActionModal — ErrorSummary wiring', () => {
    it('renders nothing when the active form has no errors', () => {
        const w = mountWith('assign');
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('maps the active form errors onto ids that resolve to the real inputs', async () => {
        const w = mountWith('assign');
        w.vm.aForm.errors = { consultant_id: 'Select a consultant' };
        await w.vm.$nextTick();
        const alert = w.get('[role="alert"]');
        const link = alert.get('a');
        const href = link.attributes('href').slice(1);
        expect(w.get(`#${href}`).element.tagName).toBe('SELECT');
        expect(w.get(`#${href}`).attributes('aria-describedby')).toBe(`${href}-err`);
    });

    it('excludes the handover-gate error from the summary (it has its own inline block)', async () => {
        const w = mountWith('assign');
        w.vm.aForm.errors = { handover: 'Handover is stale' };
        await w.vm.$nextTick();
        expect(w.find('[role="alert"]').exists()).toBe(false);
        expect(w.text()).toContain('Handover is stale');   // still shown, just not via the summary
    });
});

// Wave 3, Item 5: double-submit guard — guardSubmit() no-ops while the form is already processing,
// on top of the existing :disabled="…Form.processing" binding.
describe('ActionModal — double-submit guard', () => {
    it('submitAssign no-ops while aForm.processing is true', async () => {
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 5;
        w.vm.aForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitAssign();
        expect(posts.length).toBe(0);
    });

    it('every mode\'s submit button is :disabled when its form is processing', async () => {
        for (const [mode, key] of [['medical', 'mdForm'], ['complete', 'cdForm'], ['icu', 'icuForm'], ['transfer', 'tForm']]) {
            const w = mountWith(mode);
            w.vm[key].processing = true;
            await w.vm.$nextTick();
            expect(w.find('form button[type="submit"]').attributes('disabled')).toBeDefined();
        }
    });
});
