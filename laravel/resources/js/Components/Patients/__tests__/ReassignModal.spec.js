import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

// ReassignModal owns the bulk-reassign flow that used to live on Patients/Index: rForm, selectedIds,
// the preflight load (per-patient handover_today), staleRows, uncheckAllStale, saveAllStale, and the
// preflightReady gate that keeps Confirm LOCKED until every selected patient's handover is current.
// These assertions are RELOCATED from PatientsIndex.wave2.test.js (Item 9 uncheckAllStale) and
// extended to cover the preflight gate that was implicitly guarded on the Index instance.

const { posts } = vi.hoisted(() => ({ posts: [] }));
// reactive() so the watch(() => rForm.from_consultant_id, …) that auto-loads the preflight is
// actually exercised (a plain object wouldn't trigger Vue reactivity).
vi.mock('@inertiajs/vue3', async () => {
    const { reactive, watch } = await import('vue');
    return {
        useForm: (obj) => {
            // #20 (role/UX review 2026-09-24): a faithful-enough stand-in for Inertia's own isDirty
            // — tracks a `defaults` baseline, recomputed whenever a tracked field changes OR
            // `.defaults()` (no-arg) re-anchors it to the CURRENT values. Mirrors ActionModal.spec.js.
            const keys = Object.keys(obj);
            let defaults = { ...obj };
            const recompute = () => { f.isDirty = keys.some((k) => JSON.stringify(f[k]) !== JSON.stringify(defaults[k])); };
            const f = reactive({ ...obj, errors: {}, processing: false, isDirty: false,
                post: vi.fn((url, opts) => { posts.push({ url, form: { ...f } }); if (opts?.onSuccess) opts.onSuccess(); }),
                reset: vi.fn(), clearErrors: vi.fn(),
                defaults: vi.fn((...args) => {
                    if (args.length === 0) defaults = Object.fromEntries(keys.map((k) => [k, f[k]]));
                    else if (args.length === 1 && args[0] && typeof args[0] === 'object') defaults = { ...defaults, ...args[0] };
                    else if (args.length === 2) defaults = { ...defaults, [args[0]]: args[1] };
                    recompute();
                    return f;
                }) });
            watch(keys.map((k) => () => f[k]), recompute, { flush: 'sync' });
            return f;
        },
    };
});
// useHandover.preflight returns the rows; saveHandover records each stale save.
const { preflight, saveHandover } = vi.hoisted(() => ({ preflight: vi.fn(), saveHandover: vi.fn(() => Promise.resolve(true)) }));
vi.mock('@/composables/useHandover', () => ({ useHandover: () => ({ preflight, saveHandover, fetchHandover: vi.fn() }) }));
// the unsaved-changes guard's ask() — controllable per test (Wave 3, Item 1/2).
const { ask } = vi.hoisted(() => ({ ask: vi.fn() }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: { props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'], template: '<div><slot /></div>' },
}));

import ReassignModal from '@/Components/Patients/ReassignModal.vue';
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';
import { withCheckpointDefaults } from '@/lib/handover.js';

const consultants = [
    { id: 5, name: 'Dr Five', on_service: true },
    { id: 6, name: 'Dr Six', on_service: true },
    { id: 9, name: 'Dr Nine (off)', on_service: false },
];

const mountWith = (over = {}) => mount(ReassignModal, { props: { open: true, consultants, ...over } });

const rows = [
    { id: 1, name: 'A', mrn: '1', handover_today: false, body: '', updated_at: '2026-07-10T09:00:00+00:00' },
    { id: 2, name: 'B', mrn: '2', handover_today: true, body: 'ok' },
];

beforeEach(() => { posts.length = 0; preflight.mockReset(); saveHandover.mockClear(); saveHandover.mockResolvedValue(true); ask.mockReset(); });

describe('ReassignModal — open / from-prefill', () => {
    it('openModal(fromId) sets from_consultant_id and opens, blank when none', () => {
        const w = mountWith();
        w.vm.openModal(5);
        expect(w.vm.rForm.from_consultant_id).toBe(5);
        w.vm.openModal();
        expect(w.vm.rForm.from_consultant_id).toBe('');
    });
    it('to-dropdown offers on-service consultants only', () => {
        expect(mountWith().vm.onServiceConsultants.map((c) => c.id)).toEqual([5, 6]);
    });
});

describe('ReassignModal — preflight load + selectedIds', () => {
    it('loadPreflight checks all selected by default and surfaces stale rows', async () => {
        preflight.mockResolvedValue(rows);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        expect([...w.vm.selectedIds].sort()).toEqual([1, 2]);   // all checked by default
        expect(w.vm.staleRows.map((r) => r.id)).toEqual([1]);   // id 1 stale + selected
        expect(preflight).toHaveBeenCalledWith(5);
    });
    it('watcher: setting from_consultant_id auto-triggers a preflight load (integration)', async () => {
        preflight.mockResolvedValue(rows);
        const w = mountWith();
        w.vm.rForm.from_consultant_id = 7;
        await w.vm.$nextTick();
        await w.vm.$nextTick();
        expect(preflight).toHaveBeenCalledWith(7);
    });
    it('preflightReady is TRUE even while a selected handover is stale (soft gate, HO-T5)', async () => {
        preflight.mockResolvedValue(rows);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        expect(w.vm.preflightReady).toBe(true);   // confirm no longer locked by staleness
    });
});

describe('ReassignModal — soft handover gate (HO-T5: warn, don\'t block)', () => {
    it('allows confirm with a stale handover and shows the incomplete-handover warning', async () => {
        preflight.mockResolvedValue(rows);   // id 1 stale + selected, id 2 current + selected
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        expect(w.vm.preflightReady).toBe(true);
        expect(w.text()).toContain('will move with an incomplete handover');
    });
});

describe('ReassignModal — uncheckAllStale (relocated Item 9)', () => {
    it('drops stale ids so preflightReady unlocks', async () => {
        preflight.mockResolvedValue(rows);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.uncheckAllStale();
        await w.vm.$nextTick();
        expect([...w.vm.selectedIds]).toEqual([2]);
        expect(w.vm.staleRows.length).toBe(0);
        expect(w.vm.preflightReady).toBe(true);   // ≥1 selected + no stale → unlocked
    });
});

describe('ReassignModal — saveAllStale unlocks the gate', () => {
    it('saves each stale handover, re-checks, and restores the user picks', async () => {
        // first load: id 1 stale; after saving, the re-check returns it current → unlocks
        preflight.mockResolvedValueOnce(rows)
                 .mockResolvedValueOnce([{ ...rows[0], handover_today: true, body: 'fresh' }, rows[1]]);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.preflightBodies[1] = 'fresh note';
        await w.vm.saveAllStale();
        await w.vm.$nextTick();
        // HC-T5: saveAllStale now sends the per-row checkpoints map too (defaulted, since row 1 had none).
        expect(saveHandover).toHaveBeenCalledWith(1, 'fresh note', withCheckpointDefaults(null));
        expect([...w.vm.selectedIds].sort()).toEqual([1, 2]);   // picks restored after reload
        expect(w.vm.preflightReady).toBe(true);
    });
});

describe('ReassignModal — compact HandoverCapture per stale row (HC-T5)', () => {
    it('renders a compact HandoverCapture per stale row and sends checkpoints on save-all', async () => {
        // first load: id 1 stale + selected, id 2 current + selected; after saving, the re-check
        // returns id 1 current — mirrors the neighbouring saveAllStale test's mock-driven reload.
        preflight.mockResolvedValueOnce(rows)
                 .mockResolvedValueOnce([{ ...rows[0], handover_today: true, body: 'today note' }, rows[1]]);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await nextTick();

        const caps = w.findAllComponents(HandoverCapture);
        expect(caps).toHaveLength(1);   // only row 1 (id 1) is stale
        expect(caps[0].props('density')).toBe('compact');
        // the row's own pill + the batch banner already say "stale" — the capture's redundant status
        // line is suppressed, but it still receives the row's truthful updatedAt (not an implicit null)
        expect(caps[0].props('hideStatus')).toBe(true);
        expect(caps[0].props('updatedAt')).toBe('2026-07-10T09:00:00+00:00');

        caps[0].vm.$emit('update:body', 'today note');
        caps[0].vm.$emit('update:checkpoints', { ...withCheckpointDefaults(null), high_risk: true });
        await nextTick();

        await w.vm.saveAllStale();
        // the THIRD argument is the point of this test — checkpoints now travel with the note
        expect(saveHandover).toHaveBeenCalledWith(1, 'today note', expect.objectContaining({ high_risk: true }));
        w.unmount();
    });
});

describe('ReassignModal — confirm submit (SUBSET move)', () => {
    it('submits only the checked patients and emits saved', async () => {
        preflight.mockResolvedValue(rows);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.uncheckAllStale();                  // leave only id 2
        await w.vm.$nextTick();
        w.vm.rForm.to_consultant_id = 6;
        w.vm.submitReassign();
        expect(posts[0].url).toBe('/admissions/reassign');
        expect(posts[0].form.admission_ids).toEqual([2]);   // subset move — only the checked travel
        expect(w.emitted('saved')).toBeTruthy();
    });
    it('submits even while a selected handover is stale (soft gate, HO-T5 — warns but allows)', async () => {
        preflight.mockResolvedValue(rows);   // id 1 is stale + selected → preflightReady is still true
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.rForm.to_consultant_id = 6;
        w.vm.submitReassign();
        expect(posts.length).toBe(1);                                // no longer blocked by staleness
        expect(posts[0].form.admission_ids).toEqual([1, 2]);         // nothing was unchecked — both travel
    });

    it('double-submit guard: submitReassign no-ops while rForm.processing is true', async () => {
        preflight.mockResolvedValue(rows);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.uncheckAllStale();
        w.vm.rForm.to_consultant_id = 6;
        w.vm.rForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitReassign();
        expect(posts.length).toBe(0);
    });
});

// HC-T7: ONE acknowledgement dialog covers the WHOLE batch — never one per patient. Confirming
// sets `acknowledged: true` on the submitted form; cancelling leaves the move un-submitted.
describe('ReassignModal — acknowledgement dialog (HC-T7)', () => {
    const rows3 = [
        { id: 1, name: 'A', mrn: '1', handover_today: false, body: '' },
        { id: 2, name: 'B', mrn: '2', handover_today: false, body: '' },
        { id: 3, name: 'C', mrn: '3', handover_today: true, body: 'ok' },
    ];

    it('asks ONCE for the whole batch when stale rows remain, and posts acknowledged=true on confirm', async () => {
        preflight.mockResolvedValue(rows3);
        ask.mockResolvedValue(true);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.rForm.to_consultant_id = 6;
        await w.vm.confirmThenSubmit();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][1]).toContain('2 of');   // 2 stale of 3 selected
        expect(w.vm.rForm.acknowledged).toBe(true);
        expect(posts.length).toBe(1);
        expect(posts[0].url).toBe('/admissions/reassign');
    });

    it('does not submit when the user cancels the acknowledgement', async () => {
        preflight.mockResolvedValue(rows3);
        ask.mockResolvedValue(false);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.rForm.to_consultant_id = 6;
        await w.vm.confirmThenSubmit();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(posts.length).toBe(0);
    });

    it('does not ask at all when no stale rows are selected', async () => {
        preflight.mockResolvedValue(rows3);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.uncheckAllStale();   // leaves only the current row (id 3) selected
        await w.vm.$nextTick();
        w.vm.rForm.to_consultant_id = 6;
        await w.vm.confirmThenSubmit();
        expect(ask).not.toHaveBeenCalled();
        expect(posts[0]?.form.admission_ids).toEqual([3]);
    });
});

// Wave 3, Item 1/2: unsaved-changes guard, keyed off rForm.isDirty, shared by BaseModal's :dirty
// prop and the Cancel button via the same useUnsavedGuard instance.
describe('ReassignModal — unsaved-changes guard', () => {
    it('clean form: Cancel closes immediately without asking', async () => {
        const w = mountWith();
        expect(w.vm.modalDirty).toBe(false);
        w.vm.close();
        await w.vm.$nextTick();
        expect(ask).not.toHaveBeenCalled();
        expect(w.emitted('close')).toBeTruthy();
    });

    it('dirty form: Cancel asks (danger) before closing', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        w.vm.rForm.isDirty = true;
        await w.vm.$nextTick();
        expect(w.vm.modalDirty).toBe(true);
        w.vm.close();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.emitted('close')).toBeTruthy();
    });

    it('dirty form: declining keeps the modal open', async () => {
        ask.mockResolvedValue(false);
        const w = mountWith();
        w.vm.rForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.close();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(w.emitted('close')).toBeFalsy();
    });
});

// #20 (role/UX review 2026-09-24): opening the modal PRE-FILLED from a group-header "Reassign"
// button used to diverge from rForm's empty constructor default (from_consultant_id set, non-empty)
// and read dirty before the user touched anything — Cancel raised a false "Discard changes?"
// warning. Fixed via `.defaults()` right after the prefill in openModal().
describe('ReassignModal — false "Discard changes?" regression (#20)', () => {
    it('opening pre-filled from a group header reads clean, Cancel never asks', async () => {
        preflight.mockResolvedValue([]);   // the watcher on from_consultant_id auto-loads it — give it a real resolution
        const w = mountWith();
        w.vm.openModal(5);
        await nextTick(); await nextTick();
        expect(w.vm.rForm.from_consultant_id).toBe(5);
        expect(w.vm.modalDirty).toBe(false);
        w.vm.close();
        await nextTick();
        expect(ask).not.toHaveBeenCalled();
        expect(w.emitted('close')).toBeTruthy();
    });

    it('a genuine edit AFTER opening pre-filled still marks the form dirty', async () => {
        preflight.mockResolvedValue([]);
        const w = mountWith();
        w.vm.openModal(5);
        await nextTick(); await nextTick();
        expect(w.vm.modalDirty).toBe(false);
        w.vm.rForm.to_consultant_id = 6;   // a real user change
        await nextTick();
        expect(w.vm.modalDirty).toBe(true);
    });
});

// #27 (role/UX review 2026-09-24): explains what ticking the bulk "Mark as new patients" checkbox
// actually does (verified against PatientActionController::bulkReassign's mark_new handling).
describe('ReassignModal — "Mark as new patients" InfoTip (#27)', () => {
    it('renders an InfoTip mark next to the checkbox', () => {
        const w = mountWith();
        const tip = w.find('button[aria-label="More information: Mark as new patients"]');
        expect(tip.exists()).toBe(true);
    });
});

// #40 (role/UX review 2026-09-24): a server-side rejection of the "To" consultant now has somewhere
// to render — previously the modal had no binding for rForm.errors.to_consultant_id at all.
describe('ReassignModal — "To" consultant validation error display (#40)', () => {
    it('renders the server message when to_consultant_id fails validation', async () => {
        const w = mountWith();
        w.vm.rForm.errors = { to_consultant_id: 'Only an active consultant can receive these patients — the selected user is not eligible.' };
        await nextTick();
        expect(w.text()).toContain('Only an active consultant can receive these patients');
    });

    // review fix-up: the error <p> is now programmatically associated with the "To" field via
    // aria-describedby, matching ActionModal.vue's fid(...)+'-err' pattern — not just visually near it.
    it('associates the error message with the "To" field via aria-describedby, and clears it once the error is gone', async () => {
        const w = mountWith();
        const toSelect = w.find('select[title="On-service consultants only"]');
        expect(toSelect.attributes('aria-describedby')).toBeUndefined();

        w.vm.rForm.errors = { to_consultant_id: 'Only an active consultant can receive these patients — the selected user is not eligible.' };
        await nextTick();
        expect(toSelect.attributes('aria-describedby')).toBe('reassign-to-consultant-err');
        const err = w.find('#reassign-to-consultant-err');
        expect(err.exists()).toBe(true);
        expect(err.text()).toContain('Only an active consultant can receive these patients');

        w.vm.rForm.errors = {};
        await nextTick();
        expect(toSelect.attributes('aria-describedby')).toBeUndefined();
    });
});

describe('ReassignModal — preflight failure', () => {
    it('a rejected preflight shows why and keeps Confirm locked instead of "Checking handovers…" forever', async () => {
        preflight.mockRejectedValue(new Error('Request failed (500) while loading /handovers/preflight?from_consultant_id=5'));
        const w = mountWith();
        w.vm.openModal(5);
        await nextTick();
        await new Promise((r) => setTimeout(r, 0));
        await nextTick();
        expect(w.vm.preflight.loading).toBe(false);
        expect(w.vm.preflight.error).toContain('Request failed (500)');
        expect(w.vm.preflightReady).toBe(false);
        const alert = w.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Re-select the consultant');
        expect(w.text()).not.toContain('Checking handovers…');
    });
});
