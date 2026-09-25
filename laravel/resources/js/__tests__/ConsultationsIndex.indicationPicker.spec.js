import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Indication picker a11y (role walkthrough 2026-09-25): the checkboxes behind the indication chips
// were hidden with display:none (`class="hidden"`), which drops a native control from the Tab
// order entirely — a keyboard user could never reach, let alone toggle, one (the same class of bug
// ui-ux.md #1 found in the Transfer/Discharge radio chips). sr-only keeps the checkbox focusable and
// screen-reader-visible while it stays invisible on screen; a visible focus ring lands on the chip
// via :focus-within, and a short "Pick one or more" hint says the group is a multi-select.

const { post, put, deleteFn, ask } = vi.hoisted(() => ({
    post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => reactive({
        ...obj, errors: {}, processing: false,
        post: vi.fn((...a) => post(...a)),
        put: vi.fn((...a) => put(...a)),
        reset: vi.fn(), clearErrors: vi.fn(),
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const baseProps = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
    reasons: [{ id: 1, name: 'Chest pain' }, { id: 2, name: 'Arrhythmia' }],
    consultants: [], specialties: [],
    worklist: { date: '2026-09-25', seen: 0, total: 0, items: [] },
};
const mountWith = () => { authUser = admin; return mount(ConsultationsIndex, { props: baseProps, attachTo: document.body }); };

beforeEach(() => { post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Consultations/Index — indication chip checkboxes', () => {
    it('New consultation: the checkboxes are sr-only (never display:none) and keyboard-focusable', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();

        const boxes = w.findAll('#' + w.vm.cfid('indication') + ' input[type="checkbox"]');
        expect(boxes).toHaveLength(2);
        for (const b of boxes) {
            expect(b.classes()).toContain('sr-only');
            expect(b.classes()).not.toContain('hidden');
        }
        boxes[0].element.focus();
        expect(document.activeElement).toBe(boxes[0].element);
        w.unmount();
    });

    it('a "Pick one or more" hint sits above the New consultation chips', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();
        expect(w.text()).toContain('Pick one or more.');
    });

    it('ticking a checkbox toggles it into cForm.indication (v-model still works under sr-only)', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();

        const box = w.find('#' + w.vm.cfid('indication') + ' input[type="checkbox"]');
        await box.setValue(true);
        expect(w.vm.cForm.indication).toContain(1);
    });

    it('Edit consultation: the checkboxes are sr-only too, with the same hint', async () => {
        const w = mountWith();
        w.vm.openEdit({ id: 9, name: 'Pt', mrn: '1', to: 'Cardiology', consultant_id: null, indication_ids: [1] });
        await w.vm.$nextTick();

        const boxes = w.findAll('#' + w.vm.efid('indication') + ' input[type="checkbox"]');
        expect(boxes).toHaveLength(2);
        for (const b of boxes) expect(b.classes()).toContain('sr-only');
        expect(w.text()).toContain('Pick one or more.');
    });

    // (role walkthrough 2026-09-25 review) aria-labelledby only names the group ("Indication"); the
    // "Pick one or more." hint needs its own id and a spot in aria-describedby, or a screen-reader
    // user who tabs straight into a checkbox never hears it.
    it('New consultation: the hint has an id and the group describes itself by it', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();

        const hintId = w.vm.cfid('indication') + '-hint';
        const hint = w.find('#' + hintId);
        expect(hint.exists()).toBe(true);
        expect(hint.text()).toBe('Pick one or more.');

        const group = w.find('#' + w.vm.cfid('indication'));
        expect(group.attributes('aria-describedby')).toBe(hintId);
    });

    it('New consultation: aria-describedby also picks up the error id once indication is invalid', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();
        w.vm.cForm.errors.indication = 'Pick at least one indication.';
        await w.vm.$nextTick();

        const hintId = w.vm.cfid('indication') + '-hint';
        const errId = w.vm.cfid('indication') + '-err';
        const group = w.find('#' + w.vm.cfid('indication'));
        expect(group.attributes('aria-describedby')).toBe(`${hintId} ${errId}`);
    });

    it('Edit consultation: the hint has an id and the group describes itself by it', async () => {
        const w = mountWith();
        w.vm.openEdit({ id: 9, name: 'Pt', mrn: '1', to: 'Cardiology', consultant_id: null, indication_ids: [1] });
        await w.vm.$nextTick();

        const hintId = w.vm.efid('indication') + '-hint';
        expect(w.find('#' + hintId).exists()).toBe(true);
        const group = w.find('#' + w.vm.efid('indication'));
        expect(group.attributes('aria-describedby')).toBe(hintId);
    });
});
