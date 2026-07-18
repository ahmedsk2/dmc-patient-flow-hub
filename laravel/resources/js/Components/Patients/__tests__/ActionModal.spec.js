import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';

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
// HC-T6: the proactive panel calls useHandover().fetchHandover to preload, and saveHandoverThen
// calls useHandover().saveHandover — both controllable spies. fetchHandover defaults to an
// empty-but-shaped payload (mirrors the real GET /admissions/{id}/handover response).
const { fetchHandover, saveHandover } = vi.hoisted(() => ({
    fetchHandover: vi.fn(() => Promise.resolve({ body: '', checkpoints: null, today: false, updated_at: null, revisions: [] })),
    saveHandover: vi.fn(() => Promise.resolve(true)),
}));
vi.mock('@/composables/useHandover', () => ({ useHandover: () => ({ saveHandover, fetchHandover, preflight: vi.fn() }) }));
// the unsaved-changes guard's ask() — controllable per test (Wave 3, Item 1/2).
const { ask } = vi.hoisted(() => ({ ask: vi.fn() }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
// BaseModal: render the slot unconditionally so sub-forms are inspectable regardless of `open`.
vi.mock('@/Components/BaseModal.vue', () => ({
    default: { props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/Patients/AdmissionSummary.vue', () => ({ default: { template: '<div class="admission-summary" />' } }));

import ActionModal from '@/Components/Patients/ActionModal.vue';
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';
import BaseModal from '@/Components/BaseModal.vue';

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

beforeEach(() => {
    posts.length = 0;
    saveHandover.mockClear(); saveHandover.mockResolvedValue(true);
    fetchHandover.mockClear(); fetchHandover.mockResolvedValue({ body: '', checkpoints: null, today: false, updated_at: null, revisions: [] });
    ask.mockReset();
});

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

// HC-T6: the old reactive gate (gateBody/gateBusy/saveGateThen, triggered only AFTER the server
// rejected a submit with a 'handover' validation error) is GONE — the server no longer blocks any
// consultant-changing path. In its place: a PROACTIVE panel that appears the moment the user's
// selection differs from the patient's current consultant, pre-loaded via fetchHandover(), well
// before any submit is attempted.
describe('ActionModal — proactive handover panel (HC-T6)', () => {
    it('assign: shows the handover editor as soon as a DIFFERENT consultant is picked, before any submit', async () => {
        const w = mountWith('assign');   // patient.consultant_id === 5
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);
        expect(fetchHandover).not.toHaveBeenCalled();

        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();   // watch(changingConsultant) + fetchHandover resolution

        const cap = w.findComponent(HandoverCapture);
        expect(cap.exists()).toBe(true);
        expect(cap.props('density')).toBe('full');
        expect(fetchHandover).toHaveBeenCalledWith(7);
    });

    it('assign: does NOT show the handover editor when re-picking the SAME consultant', async () => {
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 5;   // unchanged — a no-op assign is not a handoff
        await nextTick(); await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);
        expect(fetchHandover).not.toHaveBeenCalled();
    });

    it('assign: hides the panel again if the user reverts to the original consultant', async () => {
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(true);
        w.vm.aForm.consultant_id = 5;
        await nextTick(); await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);
    });

    it('assign: prefills body/checkpoints/today from the fetched handover payload', async () => {
        fetchHandover.mockResolvedValueOnce({ body: 'prior note', checkpoints: { high_risk: true }, today: true, updated_at: '2026-06-01T10:00:00Z', revisions: [] });
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();
        const cap = w.findComponent(HandoverCapture);
        expect(cap.props('body')).toBe('prior note');
        expect(cap.props('today')).toBe(true);
        expect(cap.props('checkpoints').high_risk).toBe(true);
    });

    it('specialty transfer: shows the panel once a receiving consultant is chosen', async () => {
        const w = mountWith('transfer');
        w.vm.tForm.mode = 'specialty';
        await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);
        w.vm.tForm.specialty_id = 1;
        // the specialty_id watcher resets consultant_id to '' — let it flush BEFORE picking one
        // (same ordering the pre-existing "specialty mode needs both" transferReady test uses).
        await nextTick();
        w.vm.tForm.consultant_id = 5;
        await nextTick(); await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(true);
    });

    it('location / external transfer modes never show the panel', async () => {
        const w = mountWith('transfer');
        w.vm.tForm.mode = 'location'; w.vm.tForm.target = 'ICU';
        await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);
        w.vm.tForm.mode = 'external'; w.vm.tForm.service = 'Surgery';
        await nextTick();
        expect(w.findComponent(HandoverCapture).exists()).toBe(false);
    });

    it('assign submit button reads "Save handover & assign" while changing consultant, "Assign" otherwise', async () => {
        const w = mountWith('assign');
        expect(w.find('form button[type="submit"]').text()).toBe('Assign');
        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();
        expect(w.find('form button[type="submit"]').text()).toBe('Save handover & assign');
    });

    it('specialty transfer submit button reads "Save handover & transfer" while changing consultant', async () => {
        const w = mountWith('transfer');
        w.vm.tForm.mode = 'specialty'; w.vm.tForm.specialty_id = 1;
        await nextTick();   // let the specialty_id→consultant_id reset watcher flush first
        w.vm.tForm.consultant_id = 6;
        await nextTick(); await nextTick();
        expect(w.find('form button[type="submit"]').text()).toBe('Save handover & transfer');
    });

    it('widens the modal to size="lg" (was "md") for room for the panel', () => {
        expect(mountWith('assign').findComponent(BaseModal).props('size')).toBe('lg');
    });
});

describe('ActionModal — saveHandoverThen (HC-T6 replacement for saveGateThen)', () => {
    it('saves the handover, marks it current, then re-fires the original submit', async () => {
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();
        w.vm.hoBody = 'on insulin, watch K+';
        const retry = vi.fn();
        await w.vm.saveHandoverThen(retry);
        expect(saveHandover).toHaveBeenCalledWith(7, 'on insulin, watch K+', w.vm.hoCheckpoints);
        expect(retry).toHaveBeenCalled();
        expect(w.vm.ho.today).toBe(true);
    });

    // Unlike the old gate, an empty body is NOT a hard stop — this is a proactive best-effort save,
    // not a blocking requirement, so the original action still proceeds.
    it('skips the save but still retries when the body is empty (no forced bypass button needed)', async () => {
        const w = mountWith('assign');
        const retry = vi.fn();
        w.vm.hoBody = '   ';
        await w.vm.saveHandoverThen(retry);
        expect(saveHandover).not.toHaveBeenCalled();
        expect(retry).toHaveBeenCalled();
    });

    // Unlike the old gate (which only retried on a truthy saveHandover result), the move is never
    // blocked by a failed handover save — the server-side gate is gone, so this is best-effort only.
    it('still retries even when the handover save reports failure', async () => {
        saveHandover.mockResolvedValueOnce(false);
        const w = mountWith('assign');
        w.vm.hoBody = 'note';
        const retry = vi.fn();
        await w.vm.saveHandoverThen(retry);
        expect(retry).toHaveBeenCalled();
    });
});

// HC-T7: the acknowledgement dialog is the single primary action's interception point — there is
// deliberately NO visible "assign without handover" bypass button. Confirming proceeds (and sets
// `acknowledged: true` on the submitted form so the server can raise the persistent reminder);
// declining aborts the submit entirely and leaves the user on the panel to write the note.
describe('ActionModal — acknowledgement dialog (HC-T7)', () => {
    it('asks for acknowledgement when assigning with an incomplete handover, and aborts on Cancel', async () => {
        ask.mockResolvedValue(false);
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();
        // hoBody left empty — the handover is not complete
        await w.vm.submitWithHandoverGuard(w.vm.submitAssign, w.vm.aForm);
        expect(ask).toHaveBeenCalledTimes(1);
        expect(posts.length).toBe(0);   // the underlying submit did NOT fire
    });

    it('submits with acknowledged=true when the user confirms', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();
        await w.vm.submitWithHandoverGuard(w.vm.submitAssign, w.vm.aForm);
        expect(w.vm.aForm.acknowledged).toBe(true);
        expect(posts[0]?.url).toBe('/admissions/7/assign');
    });

    it('does NOT ask when the handover is complete (note written or already today)', async () => {
        const w = mountWith('assign');
        w.vm.aForm.consultant_id = 6;
        await nextTick(); await nextTick();
        w.vm.hoBody = 'today note';
        await w.vm.submitWithHandoverGuard(w.vm.submitAssign, w.vm.aForm);
        expect(ask).not.toHaveBeenCalled();
        expect(saveHandover).toHaveBeenCalled();       // saveHandoverThen path used instead
        expect(posts[0]?.url).toBe('/admissions/7/assign');
    });

    // The template only routes assign/specialty-transfer through the guard when changingConsultant
    // is true; location and external transfer never touch it — same submit as before this task.
    it('location and external transfer submit directly, never asking for acknowledgement', async () => {
        const w = mountWith('transfer');
        w.vm.tForm.mode = 'location'; w.vm.tForm.target = 'ICU';
        await nextTick();
        await w.find('form').trigger('submit');
        expect(ask).not.toHaveBeenCalled();
        expect(posts.pop()?.url).toBe('/admissions/7/transfer');

        w.vm.tForm.mode = 'external'; w.vm.tForm.service = 'Surgery';
        await nextTick();
        await w.find('form').trigger('submit');
        expect(ask).not.toHaveBeenCalled();
        expect(posts.pop()?.url).toBe('/admissions/7/transfer');
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

    // HC-T6: the dedicated inline gate block that used to render this error is GONE — the server no
    // longer sends a 'handover' key at all. The filter itself is kept as a harmless defensive no-op
    // (see the modeErrors comment in ActionModal.vue), so a stray 'handover' key still never reaches
    // the summary — it just isn't rendered anywhere else either now.
    it('excludes a handover error key from the summary (legacy defensive filter; server no longer sends it)', async () => {
        const w = mountWith('assign');
        w.vm.aForm.errors = { handover: 'Handover is stale' };
        await w.vm.$nextTick();
        expect(w.find('[role="alert"]').exists()).toBe(false);
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
