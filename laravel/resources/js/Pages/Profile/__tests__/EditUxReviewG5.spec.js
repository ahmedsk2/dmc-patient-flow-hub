import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// 2026-09-24 role/UX review #38: the identity summary line said "MFA enabled/not enabled" while
// the section below it says "Two-factor authentication" — one term, consistently, matching the
// dominant term elsewhere in the app (Mfa/Setup.vue's own page title/button copy is "two-factor").
// New file, separate from the existing Edit.spec.js / Edit.a11y.spec.js (both kept green).
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn() },
    useForm: (initial) => ({ ...initial, processing: false, errors: {}, recentlySuccessful: false, put: vi.fn(), reset: vi.fn() }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/PasswordMeter.vue', () => ({ default: { template: '<div />' } }));

import Edit from '@/Pages/Profile/Edit.vue';

const mountPage = (mfa_enabled) => mount(Edit, {
    props: { profile: { name: 'Dr Who', username: 'who', email: null, role: 'Consultant', pass_exp_date: null, mfa_enabled }, trustedDevices: [] },
});

describe('Profile/Edit — one term for MFA (review #38)', () => {
    it('the identity summary says "Two-factor", not "MFA"', () => {
        const text = mountPage(true).text();
        expect(text).toContain('Two-factor enabled');
        expect(text).not.toMatch(/\bMFA\b/);
    });

    it('agrees with the section heading below it either way (enabled/not enabled)', () => {
        expect(mountPage(true).text()).toContain('Two-factor enabled');
        expect(mountPage(false).text()).toContain('Two-factor not enabled');
    });
});
