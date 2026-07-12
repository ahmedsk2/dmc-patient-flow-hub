import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Controllable Inertia + confirm mocks (same shape as AppLayout.nav.spec.js). usePage() reads the
// module-level pageProps so each test sets the acting user; router/ask are spies we assert against.
let pageProps;
const post = vi.fn();
const del = vi.fn();
let askResult = true;
const ask = vi.fn(() => Promise.resolve(askResult));

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a) => post(...a), delete: (...a) => del(...a) },
    usePage: () => ({ props: pageProps }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Components/PatientFlags.vue', () => ({
    default: { name: 'PatientFlags', props: ['patient', 'readmitWindow', 'variant'], template: '<span class="flags" />' },
}));

import PatientCard from '@/Components/Patients/PatientCard.vue';

const patient = (over = {}) => ({
    id: 5, name: 'Test Patient', mrn: '9001', location: 'Ward', bed: 'W-1',
    los: 3, los_band: 'short', age: 40, gender: 'Male', admit_date: '2026-06-01',
    dx_count: 2, diagnoses: [{ code: 'A00', name: 'Cholera' }, { code: 'B00', name: 'Herpes' }],
    is_longterm: false, medically_discharged: false, discharged: false,
    consultant_id: 9, sign_pending: false, handover: null, ...over,
});
const setUser = (over = {}) => {
    pageProps = { auth: { user: { id: 1, role: 0, is_admin: true, can: { assign: true, manage: true, modify: true }, ...over } } };
};
const mountCard = (p = patient(), props = {}) => mount(PatientCard, { props: { patient: p, compact: false, readmitWindow: 3, ...props } });

describe('PatientCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        askResult = true;
        setUser();   // admin by default
    });

    it('renders the patient identity', () => {
        const w = mountCard();
        expect(w.text()).toContain('Test Patient');
        expect(w.text()).toContain('MRN 9001');
        expect(w.text()).toContain('W-1');
        expect(w.text()).toContain('Ward');
    });

    it('emits open-modal with the mode + patient for the flow actions', async () => {
        const p = patient();
        const w = mountCard(p);
        await w.get('[title="Reassign consultant"]').trigger('click');
        await w.get('[title="Discharge"]').trigger('click');       // active ward → medical discharge
        await w.get('[title="Transfer"]').trigger('click');
        expect(w.emitted('open-modal')[0]).toEqual(['assign', p]);
        expect(w.emitted('open-modal')[1]).toEqual(['medical', p]);
        expect(w.emitted('open-modal')[2]).toEqual(['transfer', p]);
    });

    it('emits modify + handover to the parent (Index owns those modals)', async () => {
        const p = patient();
        const w = mountCard(p);
        await w.get('[title="Modify details"]').trigger('click');
        await w.get('[aria-label="No handover yet"]').trigger('click');
        expect(w.emitted('modify')[0]).toEqual([p]);
        expect(w.emitted('handover')[0]).toEqual([p]);
    });

    it('an observer sees no action row and no bed-edit affordance (read-only)', () => {
        setUser({ id: 2, role: 5, is_admin: false, can: {} });
        const w = mountCard();
        expect(w.find('[title="Modify details"]').exists()).toBe(false);
        expect(w.find('[title="Edit bed"]').exists()).toBe(false);
        expect(w.text()).toContain('W-1');   // bed still shown, just not editable
    });

    it('inline bed edit posts only when the value actually changes', async () => {
        const w = mountCard();
        await w.get('[title="Edit bed"]').trigger('click');
        const input = w.get('input[aria-label="Bed"]');
        await input.setValue('W-9');
        await input.trigger('blur');
        expect(post).toHaveBeenCalledWith('/admissions/5/bed', { bed: 'W-9' }, { preserveScroll: true });
    });

    it('inline bed edit makes no request when unchanged', async () => {
        const w = mountCard();
        await w.get('[title="Edit bed"]').trigger('click');
        await w.get('input[aria-label="Bed"]').trigger('blur');   // value still 'W-1'
        expect(post).not.toHaveBeenCalled();
    });

    it('long-term toggle posts to the longterm endpoint', async () => {
        const w = mountCard();
        await w.get('[title="Mark long-term"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/admissions/5/longterm', {}, { preserveScroll: true });
    });

    it('undo-medical posts immediately and never confirms (relocated from PatientsIndex.wave2)', async () => {
        const w = mountCard(patient({ medically_discharged: true }));
        await w.get('[title="Undo medical discharge"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/admissions/5/undo-medical-discharge', {}, { preserveScroll: true });
        expect(ask).not.toHaveBeenCalled();
    });

    it('delete confirms then hard-deletes (admin only)', async () => {
        const w = mountCard();
        await w.get('[title="Delete admission"]').trigger('click');
        await flushPromises();
        expect(ask).toHaveBeenCalled();
        expect(del).toHaveBeenCalledWith('/admissions/5', { preserveScroll: true });
    });

    it('delete does nothing when the confirm is declined', async () => {
        askResult = false;
        const w = mountCard();
        await w.get('[title="Delete admission"]').trigger('click');
        await flushPromises();
        expect(del).not.toHaveBeenCalled();
    });

    it('a medically-discharged ward patient shows Complete discharge, not Discharge', () => {
        const w = mountCard(patient({ medically_discharged: true }));
        expect(w.find('[title="Complete discharge"]').exists()).toBe(true);
        expect(w.find('[title="Discharge"]').exists()).toBe(false);
    });

    it('a non-admin without manage cannot transfer/discharge but can still modify (per caps)', () => {
        setUser({ id: 3, role: 4, is_admin: false, can: { assign: false, manage: false, modify: true } });
        const w = mountCard(patient({ consultant_id: 999 }));   // not their patient
        expect(w.find('[title="Transfer"]').exists()).toBe(false);
        expect(w.find('[title="Discharge"]').exists()).toBe(false);
        expect(w.find('[title="Reassign consultant"]').exists()).toBe(false);
        expect(w.find('[title="Modify details"]').exists()).toBe(true);
    });
});
