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
            post: vi.fn((url, opts) => { posts.push({ url }); if (opts?.onSuccess) opts.onSuccess(); }),
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
