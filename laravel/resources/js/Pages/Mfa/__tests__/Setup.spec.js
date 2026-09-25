import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// U-signin(a) (role walkthrough 2026-09-25): "Cancel" used to link to /profile, which
// EnsureMfaEnrolled immediately bounces back to /mfa/setup — enrolment is mandatory, so there was
// never anywhere to cancel TO. It's now a real "Sign out" action (POST /logout, same as every other
// sign-out in the app) plus a one-line explanation that setup can't be skipped.

const routerPost = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => ({ ...obj, post: vi.fn(), errors: {}, processing: false, reset: vi.fn() }),
    router: { post: routerPost },
}));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async () => 'data:image/png;base64,mockqr') } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import Setup from '@/Pages/Mfa/Setup.vue';

const props = { secret: 'JBSWY3DPEHPK3PXP', otpauthUri: 'otpauth://totp/x', recoveryCodes: ['aaaa-1111', 'bbbb-2222'] };
const btn = (w, text) => w.findAll('button').find((b) => b.text() === text);

describe('Mfa/Setup — no dead-end Cancel link (U-signin a)', () => {
    it('has no link to /profile', () => {
        const w = mount(Setup, { props });
        expect(w.find('a[href="/profile"]').exists()).toBe(false);
        expect(w.findAll('a').find((a) => a.text() === 'Cancel')).toBeUndefined();
    });

    it('explains that enrolment is required before the app can be used', () => {
        const w = mount(Setup, { props });
        expect(w.text()).toContain('Setting up two-factor authentication is required before you can use the app.');
    });

    it('"Sign out" posts to /logout via router, same as every other sign-out', async () => {
        const w = mount(Setup, { props });
        await btn(w, 'Sign out').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/logout');
    });
});
