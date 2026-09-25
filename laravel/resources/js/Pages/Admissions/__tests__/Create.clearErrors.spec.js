import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// E6 (role walkthrough 2026-09-25): the red "required" messages never cleared once a field was
// fixed — Create.vue never called form.clearErrors(). Reproduced live: fill in every field after a
// failed submit and the same six messages stay on screen through a second, successful submit. Each
// field now clears its OWN error the moment its value changes.

const errors = vi.hoisted(() => ({}));
const clearErrors = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a href="#"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 1, role: 0, is_admin: true, can: { add: true } } }, flash: null } }),
    useForm: (initial) => reactive({ ...initial, errors, processing: false, post: vi.fn(), reset: vi.fn(), clearErrors }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/IcdTypeahead.vue', () => ({
    default: { name: 'IcdTypeahead', emits: ['select'], template: '<div></div>' },
}));

import Create from '@/Pages/Admissions/Create.vue';

const props = {
    consultants: [{ id: 5, name: 'Dr A', full_name: 'Dr A' }],
    countries: ['Saudi Arabia', 'Egypt'],
    locations: ['Ward', 'ICU', 'ER'],
    admitFrom: ['ER', 'OPD'],
};

let w;
const mountCreate = () => { w = mount(Create, { props }); return w; };

afterEach(() => {
    w?.unmount();
    w = null;
    for (const k of Object.keys(errors)) delete errors[k];
    clearErrors.mockClear();
});

const setErrors = (obj) => { for (const [k, v] of Object.entries(obj)) errors[k] = v; };

describe('Admissions/Create — clearing a field\'s error on change (E6)', () => {
    it('typing in MRN clears only the mrn error', async () => {
        setErrors({ mrn: 'The MRN field is required.', name: 'The name field is required.' });
        const w = mountCreate();
        await w.get('#' + w.vm.fid('mrn')).setValue('30000001');
        expect(clearErrors).toHaveBeenCalledWith('mrn');
    });

    it('typing in name clears the name error', async () => {
        setErrors({ name: 'The name field is required.' });
        const w = mountCreate();
        await w.get('#' + w.vm.fid('name')).setValue('A Patient');
        expect(clearErrors).toHaveBeenCalledWith('name');
    });

    it('typing in age clears the age error', async () => {
        setErrors({ age: 'The age field is required.' });
        const w = mountCreate();
        await w.get('#' + w.vm.fid('age')).setValue('40');
        expect(clearErrors).toHaveBeenCalledWith('age');
    });

    it('changing gender clears the gender error', async () => {
        setErrors({ gender: 'The gender field is required.' });
        const w = mountCreate();
        await w.get('#' + w.vm.fid('gender')).setValue('Male');
        expect(clearErrors).toHaveBeenCalledWith('gender');
    });

    it('changing nationality clears the nationality error', async () => {
        setErrors({ nationality: 'The nationality field is required.' });
        const w = mountCreate();
        await w.get('#' + w.vm.fid('nationality')).setValue('Egypt');
        expect(clearErrors).toHaveBeenCalledWith('nationality');
    });

    it('changing admit date clears the admit_date error', async () => {
        setErrors({ admit_date: 'The admit date field is required.' });
        const w = mountCreate();
        await w.get('#' + w.vm.fid('admit_date')).setValue('2026-09-25');
        expect(clearErrors).toHaveBeenCalledWith('admit_date');
    });

    it('typing in bed clears the bed error', async () => {
        setErrors({ bed: 'The bed field is required.' });
        const w = mountCreate();
        await w.get('#' + w.vm.fid('bed')).setValue('W-1');
        expect(clearErrors).toHaveBeenCalledWith('bed');
    });

    it('adding a diagnosis clears the diagnoses error', async () => {
        setErrors({ diagnoses: 'At least one diagnosis is required.' });
        const w = mountCreate();
        w.vm.addDx({ code: 'A00', name: 'Cholera' });
        expect(clearErrors).toHaveBeenCalledWith('diagnoses');
    });

    it('ticking the confirm-identity checkbox clears its own error', async () => {
        // FlowAlert's v-if is `identityChanged || form.errors.confirm_identity_update` — the error
        // alone is enough to render it, no need to fake a full MRN-lookup identity mismatch.
        setErrors({ confirm_identity_update: 'Confirm the update.' });
        const w = mountCreate();
        await w.vm.$nextTick();
        const box = w.find('input[type="checkbox"]');
        expect(box.exists()).toBe(true);
        await box.setValue(true);
        expect(clearErrors).toHaveBeenCalledWith('confirm_identity_update');
    });
});
