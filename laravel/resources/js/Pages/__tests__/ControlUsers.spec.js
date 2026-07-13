import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Inertia + heavy children stubbed — this spec is about the Users tab's instant client-side filter.
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    useForm: (obj) => ({ ...obj, put: vi.fn(), post: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), defaults: vi.fn(), errors: {}, processing: false, isDirty: false }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));
// Tabs stub forces the Users tab visible so we can drive the filter.
vi.mock('@/Components/Tabs.vue', () => ({ default: { name: 'Tabs', props: ['tabs', 'modelValue'], template: "<div><slot :active=\"'users'\" /></div>" } }));
vi.mock('@/Components/BaseModal.vue', () => ({ default: { name: 'BaseModal', props: ['open', 'title', 'subtitle', 'size', 'closable', 'dirty'], template: '<div v-if="open"><slot /></div>' } }));

import Control from '@/Pages/Control/Index.vue';

const user = (over) => ({ id: 1, name: 'X', full_name: 'X', username: 'x', email: 'x@d.com', role: 5, role_label: 'Observer', active: true, on_service: false, specialty_id: null, mfa: false, can: { assign: false, add: false, manage: false, modify: false }, ...over });
const USERS = [
    user({ id: 1, name: 'Zara Admin', full_name: 'Zara Admin', username: 'zadmin', email: 'zara@d.com', role: 0, role_label: 'Admin' }),
    user({ id: 2, name: 'Alice Ward', full_name: 'Alice Ward', username: 'award', email: 'alice@d.com', role: 3, role_label: 'Consultant', on_service: true, can: { assign: false, add: false, manage: true, modify: false } }),
    user({ id: 3, name: 'Bob Stone', full_name: 'Bob Stone', username: 'bstone', email: 'bob@d.com', role: 4, role_label: 'Resident', active: false, can: { assign: false, add: true, manage: false, modify: false } }),
    user({ id: 4, name: 'Carol Reed', full_name: 'Carol Reed', username: 'creed', email: 'carol@d.com', role: 2, role_label: 'Registrar' }),
];
const settings = { min_hospitalist: 6, max_hospitalist: 30, min_subs: 7, max_subs: 7, short_los: 5, long_los: 11 };
const roles = { 0: 'Admin', 2: 'Registrar', 3: 'Consultant', 4: 'Resident', 5: 'Observer' };
const counts = { users: 4, active_users: 3, patients: 0, admissions: 0, consultations: 0, icd10: 0, specialties: 0 };

const mountControl = () => mount(Control, {
    props: { settings, users: USERS, roles, counts, specialties: [], reasons: [], settingHistory: [], reportRecipients: [] },
});
const sel = (w, label) => w.find(`select[aria-label="${label}"]`);
const searchBox = (w) => w.find('input[aria-label="Search users by name, username, or email"]');

describe('Control — Users instant filter', () => {
    it('shows every user by default with an "N of M" count', () => {
        const w = mountControl();
        for (const name of ['Zara Admin', 'Alice Ward', 'Bob Stone', 'Carol Reed']) expect(w.text()).toContain(name);
        expect(w.text()).toContain('4 of 4 user(s)');
    });

    it('search matches name / username / email, case-insensitively', async () => {
        const w = mountControl();
        await searchBox(w).setValue('ALICE');
        expect(w.text()).toContain('Alice Ward');
        expect(w.text()).not.toContain('Bob Stone');
        expect(w.text()).toContain('1 of 4 user(s)');
    });

    it('filters by role', async () => {
        const w = mountControl();
        await sel(w, 'Filter by role').setValue('3');   // Consultant
        expect(w.text()).toContain('Alice Ward');
        expect(w.text()).not.toContain('Bob Stone');
        expect(w.text()).toContain('1 of 4 user(s)');
    });

    it('filters by status (disabled)', async () => {
        const w = mountControl();
        await sel(w, 'Filter by status').setValue('disabled');
        expect(w.text()).toContain('Bob Stone');       // the only inactive account
        expect(w.text()).not.toContain('Alice Ward');
    });

    it('filters by on-service', async () => {
        const w = mountControl();
        await sel(w, 'Filter by on-service').setValue('on');
        expect(w.text()).toContain('Alice Ward');
        expect(w.text()).not.toContain('Carol Reed');
    });

    it('filters by capability', async () => {
        const w = mountControl();
        await sel(w, 'Filter by capability').setValue('add');
        expect(w.text()).toContain('Bob Stone');       // the only can-add account
        expect(w.text()).not.toContain('Alice Ward');
    });

    it('combines filters and clears them', async () => {
        const w = mountControl();
        await searchBox(w).setValue('a');               // Zara, Alice, Carol contain "a"
        await sel(w, 'Filter by role').setValue('3');   // ...narrow to Consultant → Alice only
        expect(w.text()).toContain('1 of 4 user(s)');
        // the Clear button appears only while a filter is active
        const clear = w.findAll('button').find((b) => b.text() === 'Clear');
        expect(clear).toBeTruthy();
        await clear.trigger('click');
        expect(w.text()).toContain('4 of 4 user(s)');
    });

    it('shows an empty state when nothing matches', async () => {
        const w = mountControl();
        await searchBox(w).setValue('zzzznomatch');
        expect(w.text()).toContain('No users match these filters.');
        expect(w.text()).toContain('0 of 4 user(s)');
    });
});
