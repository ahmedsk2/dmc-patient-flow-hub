import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// Profile → trusted devices list + revoke (2026-07-19 spec, "Surfaces" §3).
//
// This is the user's only remedy for having ticked the opt-in box on the wrong machine, so the
// list must render and both revoke paths must fire. "Revoke all" goes through the shared
// ConfirmDialog (useConfirm), never window.confirm — pinned below.

const routerDelete = vi.hoisted(() => vi.fn());
const ask = vi.hoisted(() => vi.fn(async () => true));

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { delete: routerDelete },
    useForm: (initial) => ({ ...initial, processing: false, errors: {}, put: vi.fn(), reset: vi.fn() }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/PasswordMeter.vue', () => ({ default: { template: '<div />' } }));

import Edit from '@/Pages/Profile/Edit.vue';

const profile = { name: 'Dr Who', username: 'who', email: null, role: 'Consultant', pass_exp_date: null, mfa_enabled: true };
const device = (over = {}) => ({
    id: 7, label: 'Chrome on Windows', granted_at: '2026-07-19T08:00:00+00:00',
    expires_at: '2026-07-20T08:00:00+00:00', last_used_at: null, ...over,
});
const mountPage = (trustedDevices = []) => mount(Edit, { props: { profile, trustedDevices } });
const btn = (w, text) => w.findAll('button').find((b) => b.text() === text);

beforeEach(() => {
    routerDelete.mockClear();
    ask.mockClear();
    ask.mockResolvedValue(true);
});

describe('Profile — trusted devices', () => {
    it('shows a neutral empty state when there are none', () => {
        const w = mountPage([]);
        expect(w.text()).toContain("No trusted devices. You'll be asked for a code every time you sign in.");
        expect(btn(w, 'Revoke all')).toBeUndefined();
    });

    it('lists a device with its label and formatted dates', () => {
        const w = mountPage([device()]);
        expect(w.text()).toContain('Chrome on Windows');
        expect(w.text()).toContain('19 Jul 2026');   // granted, via the shared formatDate helper
        expect(w.text()).toContain('20 Jul 2026');   // expires
    });

    it('renders an em dash for a device that has never been used', () => {
        expect(mountPage([device()]).text()).toContain('—');
    });

    it('revokes one device by id', async () => {
        const w = mountPage([device({ id: 42 })]);
        await btn(w, 'Revoke').trigger('click');

        expect(routerDelete).toHaveBeenCalledWith('/profile/trusted-devices/42', expect.anything());
    });

    it('gates "Revoke all" behind the shared confirm dialog', async () => {
        const w = mountPage([device()]);
        await btn(w, 'Revoke all').trigger('click');
        await Promise.resolve();

        expect(ask).toHaveBeenCalled();
        expect(routerDelete).toHaveBeenCalledWith('/profile/trusted-devices', expect.anything());
    });

    it('does not revoke all when the confirmation is declined', async () => {
        ask.mockResolvedValue(false);
        const w = mountPage([device()]);
        await btn(w, 'Revoke all').trigger('click');
        await Promise.resolve();

        expect(routerDelete).not.toHaveBeenCalled();
    });
});
