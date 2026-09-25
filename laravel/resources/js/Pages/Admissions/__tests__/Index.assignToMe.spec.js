import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// Owner decision 2026-09-25 (role walkthrough, option B): "Assign to me" is for consultants only.
// Everyone else either names a consultant with "Assign to primary" (Can-assign / admin) or sees that
// the patient is awaiting a consultant. The server enforces the same rule (Round6K1Test).

let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn(() => Promise.resolve(true)) }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import AdmissionsIndex from '@/Pages/Admissions/Index.vue';

const none = { assign: false, add: false, manage: false, modify: false };
const users = {
    admin: { role: 0, is_admin: true, id: 1, can: { assign: true, add: true, manage: true, modify: true } },
    registrar: { role: 2, is_admin: false, id: 2, can: { ...none, assign: true, add: true, modify: true } },
    consultant: { role: 3, is_admin: false, id: 3, can: none },
    resident: { role: 4, is_admin: false, id: 4, can: { ...none, manage: true } },
};
const patient = { id: 7, name: 'Queue Patient', mrn: '30000007', admit_date: '2026-09-25' };
const props = { queue: [patient], icuPatients: [], consultants: [{ id: 3, name: 'Dr C' }], countries: [] };

const buttons = (w) => w.findAll('button').map((b) => b.text());

beforeEach(() => { authUser = users.admin; });

describe('Admissions/Index — "Assign to me" is for consultants only', () => {
    it('a consultant sees "Assign to me"', () => {
        authUser = users.consultant;
        const w = mount(AdmissionsIndex, { props });
        expect(buttons(w)).toContain('Assign to me');
    });

    it.each(['admin', 'registrar', 'resident'])('%s does not see "Assign to me"', (who) => {
        authUser = users[who];
        const w = mount(AdmissionsIndex, { props });
        expect(buttons(w)).not.toContain('Assign to me');
    });

    it('an admin or a registrar with Can-assign still names a consultant', () => {
        for (const who of ['admin', 'registrar']) {
            authUser = users[who];
            const w = mount(AdmissionsIndex, { props });
            expect(buttons(w)).toContain('Assign to primary');
            expect(w.text()).not.toContain('Awaiting a consultant');
        }
    });

    it('someone who can do neither is told the patient awaits a consultant', () => {
        authUser = users.resident;
        const w = mount(AdmissionsIndex, { props });
        expect(w.text()).toContain('Awaiting a consultant');
        expect(w.find('button[aria-label="More information: Awaiting a consultant"]').exists()).toBe(true);
    });
});
