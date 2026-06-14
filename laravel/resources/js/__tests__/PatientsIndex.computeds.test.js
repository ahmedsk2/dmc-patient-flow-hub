import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// Mock Inertia so usePage() returns a controllable auth user. The page imports Head/Link/router/
// useForm/usePage; we stub them all. useForm returns a plain object with the fields it touches.
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), put: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
// Stub the layout + child components so shallowMount doesn't pull their internals.
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { template: '<div />' } }));

import PatientsIndex from '@/Pages/Patients/Index.vue';

const baseProps = {
    groups: [], filters: {}, stats: { total: 0, ward: 0, icu: 0, unassigned: 0 },
    consultants: [], specialties: [], externalServices: [], readmitWindow: 3, countries: [],
};

const mountWith = (user) => {
    authUser = user;
    return shallowMount(PatientsIndex, { props: baseProps, global: { stubs: { teleport: true } } });
};

// role 5 = Observer, 3 = Consultant, 0 = Admin (is_admin true)
const observer = { role: 5, is_admin: false, id: 7, can: { assign: true, manage: true, modify: true } };
const admin = { role: 0, is_admin: true, id: 1, can: { assign: false, manage: false, modify: false } };
const consultant = (over = {}) => ({ role: 3, is_admin: false, id: 5, can: { assign: false, manage: false, modify: false }, ...over });

describe('Patients/Index role-gate computeds', () => {
    it('canAssign: observer → false even with can.assign', () => {
        expect(mountWith(observer).vm.canAssign).toBe(false);
    });
    it('canAssign: admin → true', () => {
        expect(mountWith(admin).vm.canAssign).toBe(true);
    });
    it('canAssign: non-observer with can.assign → true', () => {
        expect(mountWith(consultant({ can: { assign: true, manage: false, modify: false } })).vm.canAssign).toBe(true);
    });
    it('canAssign: non-observer without flag → false', () => {
        expect(mountWith(consultant()).vm.canAssign).toBe(false);
    });

    it('canReassign: observer → false', () => {
        expect(mountWith(observer).vm.canReassign).toBe(false);
    });
    it('canReassign: admin → true', () => {
        expect(mountWith(admin).vm.canReassign).toBe(true);
    });
    it('canReassign: can.manage → true', () => {
        expect(mountWith(consultant({ can: { assign: false, manage: true, modify: false } })).vm.canReassign).toBe(true);
    });
    it('canReassign: neither flag → false', () => {
        expect(mountWith(consultant()).vm.canReassign).toBe(false);
    });

    it('isObserver: role 5 → true, role 3 → false', () => {
        expect(mountWith(observer).vm.isObserver).toBe(true);
        expect(mountWith(consultant()).vm.isObserver).toBe(false);
    });

    it('canManage(row): admin → true', () => {
        expect(mountWith(admin).vm.canManage({ consultant_id: 999 })).toBe(true);
    });
    it('canManage(row): can.manage → true', () => {
        expect(mountWith(consultant({ can: { assign: false, manage: true, modify: false } })).vm.canManage({ consultant_id: 999 })).toBe(true);
    });
    it('canManage(row): primary consultant (row.consultant_id === me.id) → true', () => {
        expect(mountWith(consultant()).vm.canManage({ consultant_id: 5 })).toBe(true);
    });
    it('canManage(row): none → false', () => {
        expect(mountWith(consultant()).vm.canManage({ consultant_id: 999 })).toBe(false);
    });
});
