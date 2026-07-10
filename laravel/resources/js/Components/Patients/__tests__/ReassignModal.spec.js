import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// ReassignModal owns the bulk-reassign flow that used to live on Patients/Index: rForm, selectedIds,
// the preflight load (per-patient handover_today), staleRows, uncheckAllStale, saveAllStale, and the
// preflightReady gate that keeps Confirm LOCKED until every selected patient's handover is current.
// These assertions are RELOCATED from PatientsIndex.wave2.test.js (Item 9 uncheckAllStale) and
// extended to cover the preflight gate that was implicitly guarded on the Index instance.

const { posts } = vi.hoisted(() => ({ posts: [] }));
// reactive() so the watch(() => rForm.from_consultant_id, …) that auto-loads the preflight is
// actually exercised (a plain object wouldn't trigger Vue reactivity).
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (obj) => {
            const f = reactive({ ...obj, errors: {}, processing: false,
                post: vi.fn((url, opts) => { posts.push({ url, form: { ...f } }); if (opts?.onSuccess) opts.onSuccess(); }),
                reset: vi.fn(), clearErrors: vi.fn() });
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

const consultants = [
    { id: 5, name: 'Dr Five', on_service: true },
    { id: 6, name: 'Dr Six', on_service: true },
    { id: 9, name: 'Dr Nine (off)', on_service: false },
];

const mountWith = (over = {}) => mount(ReassignModal, { props: { open: true, consultants, ...over } });

const rows = [
    { id: 1, name: 'A', mrn: '1', handover_today: false, body: '' },
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
    it('preflightReady is LOCKED while a selected handover is stale', async () => {
        preflight.mockResolvedValue(rows);
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        expect(w.vm.preflightReady).toBe(false);   // confirm locked
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
        expect(saveHandover).toHaveBeenCalledWith(1, 'fresh note');
        expect([...w.vm.selectedIds].sort()).toEqual([1, 2]);   // picks restored after reload
        expect(w.vm.preflightReady).toBe(true);
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
    it('does NOT submit while a selected handover is stale (preflightReady guard — e.g. keyboard Enter)', async () => {
        preflight.mockResolvedValue(rows);   // id 1 is stale + selected → preflightReady false
        const w = mountWith();
        await w.vm.loadPreflight(5);
        await w.vm.$nextTick();
        w.vm.submitReassign();
        expect(posts.length).toBe(0);        // guard blocked the move
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
