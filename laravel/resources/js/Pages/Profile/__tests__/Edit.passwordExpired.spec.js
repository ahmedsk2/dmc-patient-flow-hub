import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// U-signin(d) (role walkthrough 2026-09-25): the reason a forced navigation to /profile happened
// (an expired password) used to rely entirely on AppLayout's flash toast — fixed, bottom-right,
// self-dismissing after 4.5s — which a phone-width user could easily miss before ever reading it.
// ProfileController now sends `profile.password_expired`, and Edit.vue renders a persistent banner
// as the FIRST element on the page (above the identity card) for as long as it's true.

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn() },
    useForm: (initial) => ({ ...initial, processing: false, errors: {}, put: vi.fn(), reset: vi.fn() }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn(async () => true) }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/PasswordMeter.vue', () => ({ default: { template: '<div />' } }));

import Edit from '@/Pages/Profile/Edit.vue';

const baseProfile = { name: 'Dr Who', username: 'who', email: null, role: 'Consultant', pass_exp_date: null, mfa_enabled: true };
const mountPage = (passwordExpired) =>
    mount(Edit, { props: { profile: { ...baseProfile, password_expired: passwordExpired }, trustedDevices: [] } });

describe('Profile/Edit — persistent expired-password banner (U-signin d)', () => {
    it('renders the banner as the very first element when the password is expired', () => {
        const w = mountPage(true);
        const banner = w.get('[role="alert"]');
        expect(banner.text()).toContain('Your password has expired — please set a new one to continue.');
        // "first element" = no earlier sibling inside the page's own root container (identity card,
        // etc. all come after it), so it's the first thing seen without scrolling
        expect(banner.element.previousElementSibling).toBeNull();
    });

    it('renders nothing when the password is not expired', () => {
        const w = mountPage(false);
        expect(w.text()).not.toContain('Your password has expired');
    });
});
