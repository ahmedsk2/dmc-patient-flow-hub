import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// Role walkthrough 2026-09-25 (U2/U3): Control -> Users -> Edit.
//   U2: unticking "Active" and pressing the ordinary Save button silently ends every one of that
//       user's live sessions and revokes their trusted devices — confirm it, like Delete/Reset MFA do.
//   U3: the delete-user confirm said "Permanently delete ... This cannot be undone" although the
//       delete is a soft delete an admin can restore from Recently Deleted — reworded to say so.
const { putFn, deleteFn, ask } = vi.hoisted(() => ({
    putFn: vi.fn(),
    deleteFn: vi.fn(),
    ask: vi.fn(() => Promise.resolve(true)),
}));
vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: vi.fn(), delete: deleteFn, on: vi.fn() },
    useForm: (obj) => ({
        ...obj,
        put: putFn,
        post: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
        defaults: vi.fn(),
        errors: {},
        processing: false,
        isDirty: false,
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/composables/useUnsavedGuard', () => ({ useUnsavedGuard: () => ({ guardedClose: (fn) => fn() }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import ControlIndex from '@/Pages/Control/Index.vue';

const props = {
    settings: {}, users: [], roles: { 0: 'Admin', 2: 'Registrar', 3: 'Consultant', 4: 'Resident', 5: 'Observer' },
    counts: {}, specialties: [], reasons: [], settingHistory: [], reportRecipients: [], system: {}, timezones: [],
};
const mountPage = () => shallowMount(ControlIndex, { props, global: { stubs: { teleport: true } } });

const activeUser = { id: 7, username: 'wt_resident_basic', name: 'WT Resident Basic', full_name: 'WT Resident Basic',
    email: 'wt@example.test', role: 4, active: true, on_service: true, specialty_id: null, mfa: true,
    can: { assign: false, add: true, manage: false, modify: false, coordinate: false } };

beforeEach(() => { putFn.mockClear(); deleteFn.mockClear(); ask.mockClear(); });

describe('Control/Index — Users edit modal (U2 deactivate confirm)', () => {
    it('asks for confirmation before a Save that flips Active off', async () => {
        const vm = mountPage().vm;
        vm.editUser(activeUser);
        vm.uForm.active = false;
        await vm.saveUser();

        expect(ask).toHaveBeenCalledTimes(1);
        const [title, body, tone] = ask.mock.calls[0];
        expect(title).toMatch(/deactivate/i);
        expect(body).toMatch(/signs? .* out|sign.*them out/i);
        expect(body).toContain('trusted devices');
        expect(tone).toBe('danger');
        expect(putFn).toHaveBeenCalledWith('/control/users/7', expect.objectContaining({ preserveScroll: true }));
    });

    it('does not submit when the deactivate confirmation is declined', async () => {
        ask.mockResolvedValueOnce(false);
        const vm = mountPage().vm;
        vm.editUser(activeUser);
        vm.uForm.active = false;
        await vm.saveUser();

        expect(putFn).not.toHaveBeenCalled();
    });

    it('does not ask for confirmation when Active stays checked', async () => {
        const vm = mountPage().vm;
        vm.editUser(activeUser);
        vm.uForm.full_name = 'WT Resident Basic Jr';
        await vm.saveUser();

        expect(ask).not.toHaveBeenCalled();
        expect(putFn).toHaveBeenCalledWith('/control/users/7', expect.objectContaining({ preserveScroll: true }));
    });

    it('does not ask again when the user was already inactive', async () => {
        const vm = mountPage().vm;
        vm.editUser({ ...activeUser, active: false });
        await vm.saveUser();

        expect(ask).not.toHaveBeenCalled();
        expect(putFn).toHaveBeenCalled();
    });
});

describe('Control/Index — Users edit modal (U3 delete confirm copy)', () => {
    it('does not claim the delete is permanent or irreversible', async () => {
        const vm = mountPage().vm;
        await vm.deleteUser(activeUser);

        const body = ask.mock.calls[0][1];
        expect(body).not.toMatch(/permanent/i);
        expect(body).not.toMatch(/cannot be undone/i);
    });

    it('tells the admin the account can be restored from Recently Deleted', async () => {
        const vm = mountPage().vm;
        await vm.deleteUser(activeUser);

        const [, body] = ask.mock.calls[0];
        expect(body).toContain('Recently Deleted');
        expect(body).toMatch(/restore/i);
        expect(body).toMatch(/sign in/i);
    });

    it('still deletes only after the confirmation resolves true', async () => {
        const vm = mountPage().vm;
        await vm.deleteUser(activeUser);
        expect(deleteFn).toHaveBeenCalledWith('/control/users/7', expect.objectContaining({ preserveScroll: true }));

        ask.mockResolvedValueOnce(false);
        deleteFn.mockClear();
        await vm.deleteUser(activeUser);
        expect(deleteFn).not.toHaveBeenCalled();
    });
});
