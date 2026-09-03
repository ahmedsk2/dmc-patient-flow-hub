import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// HandoverModal owns the per-patient handover editor that used to live on Patients/Index: on open it
// fetchHandover()s the body+history, shows a read-only view for all roles, and an Edit→Save path for
// canManage (via useHandover.saveHandover through Inertia's useForm.post). These assertions cover
// open→fetch, the submit endpoint, and the saved emit.

const { posts } = vi.hoisted(() => ({ posts: [] }));
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => {
        const f = { ...obj, errors: {}, processing: false,
            // capture the live form snapshot at post-time (body + checkpoints) so tests can assert
            // what would have been serialized, since this double doesn't do real HTTP submission.
            post: vi.fn((url, opts) => { posts.push({ url, body: f.body, checkpoints: f.checkpoints }); if (opts?.onSuccess) opts.onSuccess(); }),
            reset: vi.fn(), clearErrors: vi.fn() };
        return f;
    },
}));
const { fetchHandover } = vi.hoisted(() => ({ fetchHandover: vi.fn() }));
vi.mock('@/composables/useHandover', () => ({ useHandover: () => ({ fetchHandover, saveHandover: vi.fn(), preflight: vi.fn() }) }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: { props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable'], template: '<div><slot /></div>' },
}));

import HandoverModal from '@/Components/Patients/HandoverModal.vue';

const patient = { id: 7, name: 'Ali', mrn: '111', consultant_id: 5 };
const data = { body: 'on insulin', today: true, updated_at: '2026-06-14T08:00:00Z', updated_by_name: 'Dr Five', revisions: [] };

const mountWith = (over = {}) => mount(HandoverModal, {
    props: { open: false, patient: null, canManage: true, isObserver: false, ...over },
});

beforeEach(() => { posts.length = 0; fetchHandover.mockReset(); fetchHandover.mockResolvedValue(data); });

describe('HandoverModal — open → fetch', () => {
    it('opening (open=true with a patient) fetches the handover and fills the form body', async () => {
        const w = mountWith();
        await w.setProps({ open: true, patient });
        await flushPromises();
        expect(fetchHandover).toHaveBeenCalledWith(7);
        expect(w.vm.data.body).toBe('on insulin');
        expect(w.vm.hForm.body).toBe('on insulin');
        expect(w.vm.editing).toBe(false);   // opens in read-only view
    });
});

describe('HandoverModal — submit', () => {
    it('submitHandover posts to /admissions/{id}/handover and emits saved', async () => {
        const w = mountWith();
        await w.setProps({ open: true, patient });
        await flushPromises();
        w.vm.editing = true;
        w.vm.hForm.body = 'updated note';
        w.vm.submitHandover();
        expect(posts[0].url).toBe('/admissions/7/handover');
        expect(w.emitted('saved')).toBeTruthy();
    });
});

describe('HandoverModal — close', () => {
    it('emits close', () => {
        const w = mountWith({ open: true, patient });
        w.vm.$emit('close');
        expect(w.emitted('close')).toBeTruthy();
    });
});

describe('HandoverModal — checkpoints', () => {
    it('shows checkpoint chips for set flags in the read view', async () => {
        fetchHandover.mockResolvedValue({
            ...data,
            checkpoints: { vte_completed: true, ready_for_discharge: false, high_risk: true, needs_workup: false, workup_pending: false, code_status: 'dnr' },
        });
        const w = mountWith();
        await w.setProps({ open: true, patient });
        await flushPromises();
        expect(w.text()).toContain('VTE');
        expect(w.text()).toContain('DNR');
        expect(w.text()).toContain('High-risk');
    });

    it('edits checkpoints and posts them on Save', async () => {
        const w = mountWith();
        await w.setProps({ open: true, patient });
        await flushPromises();
        w.vm.editing = true;
        w.vm.hForm.checkpoints.high_risk = true;
        w.vm.hForm.checkpoints.code_status = 'dnr';
        w.vm.submitHandover();
        expect(posts[0].url).toBe('/admissions/7/handover');
        expect(posts[0].checkpoints).toEqual(expect.objectContaining({ high_risk: true, code_status: 'dnr' }));
    });
});

describe('HandoverModal — fetch failure', () => {
    it('shows a readable error instead of "Loading…" forever when the fetch rejects', async () => {
        fetchHandover.mockRejectedValue(new Error('Request failed (403) while loading /admissions/7/handover'));
        const w = mountWith();
        await w.setProps({ open: true, patient });
        await flushPromises();
        expect(w.vm.data).toBeNull();
        const alert = w.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Request failed (403)');
        expect(w.text()).not.toContain('Loading…');
    });
    it('a later successful open clears the error', async () => {
        fetchHandover.mockRejectedValueOnce(new Error('Network error while loading /admissions/7/handover: offline'));
        const w = mountWith();
        await w.setProps({ open: true, patient });
        await flushPromises();
        expect(w.find('[role="alert"]').exists()).toBe(true);
        await w.setProps({ open: false, patient: null });
        await w.setProps({ open: true, patient });
        await flushPromises();
        expect(w.find('[role="alert"]').exists()).toBe(false);
        expect(w.vm.data.body).toBe('on insulin');
    });
});
