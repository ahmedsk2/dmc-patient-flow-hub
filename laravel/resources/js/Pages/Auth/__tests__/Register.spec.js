import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

// Mandatory MFA + email verification (2026-07-11 design, spec §E) — Register.vue becomes a
// reactive multi-phase form: email must be verified mid-form, then an authenticator (TOTP) must
// be provisioned + confirmed, BEFORE the account is created. This spec drives the phase gating
// only (the step endpoints are mocked via global fetch; the FINAL /register submit goes through
// Inertia's useForm, mocked below like ActionModal.spec.js's reactive-form pattern).

const { formPost, formReset } = vi.hoisted(() => ({ formPost: vi.fn(), formReset: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    useForm: (obj) => reactive({
        ...obj,
        errors: {},
        processing: false,
        post: (url, opts) => { formPost(url, opts); if (opts?.onFinish) opts.onFinish(); },
        reset: formReset,
    }),
}));
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn(async () => 'data:image/png;base64,mockqr') } }));
// PasswordMeter lazy-imports the real zxcvbn package on first non-empty password; stubbing it out
// keeps this spec deterministic (it isn't part of the gating contract under test).
vi.mock('@/Components/PasswordMeter.vue', () => ({ default: { template: '<div class="pw-meter-stub" />' } }));

import Register from '@/Pages/Auth/Register.vue';

const roles = { 2: 'Registrar', 3: 'Consultant', 4: 'Resident', 5: 'Observer' };

let fetchCalls;
const stubFetch = (queue) => {
    fetchCalls = [];
    let i = 0;
    vi.stubGlobal('fetch', vi.fn(async (url, opts) => {
        const step = queue[Math.min(i, queue.length - 1)];
        i += 1;
        fetchCalls.push({ url, opts });
        if (step.fail) return { ok: false, json: async () => ({ errors: step.errors || {} }) };
        return { ok: true, json: async () => step.data };
    }));
};

const mountForm = () => mount(Register, { props: { roles } });
const btn = (w, text) => w.findAll('button').find((b) => b.text() === text);
const btnStartsWith = (w, text) => w.findAll('button').find((b) => b.text().startsWith(text));

beforeEach(() => {
    vi.useFakeTimers();
    formPost.mockClear();
    formReset.mockClear();
});
afterEach(() => {
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Register — phase gating', () => {
    it('hides password, authenticator, and Create until the email is verified', () => {
        const w = mountForm();
        expect(w.find('input[type="password"]').exists()).toBe(false);
        expect(w.text()).not.toContain('Set up authenticator');
        expect(btn(w, 'Create account')).toBeUndefined();
    });

    it('Send code is disabled for an invalid email and enabled once the email looks valid', async () => {
        const w = mountForm();
        const email = w.get('#reg-email');
        await email.setValue('not-an-email');
        expect(btn(w, 'Send code').attributes('disabled')).toBeDefined();
        await email.setValue('person@example.com');
        expect(btn(w, 'Send code').attributes('disabled')).toBeUndefined();
    });
});

describe('Register — email send/verify flow', () => {
    it('locks the email field and reveals the code UI after a successful send', async () => {
        stubFetch([{ data: { sent: true } }]);
        const w = mountForm();
        await w.get('#reg-email').setValue('person@example.com');
        await btn(w, 'Send code').trigger('click');
        await flushPromises();

        expect(fetchCalls[0].url).toBe('/register/email/send');
        expect(JSON.parse(fetchCalls[0].opts.body)).toEqual({ email: 'person@example.com' });
        expect(w.get('#reg-email').attributes('readonly')).toBeDefined();
        expect(w.find('#reg-email-code').exists()).toBe(true);
        expect(btn(w, 'Confirm code')).toBeTruthy();
    });

    it('surfaces a field error and does not lock the email when send fails', async () => {
        stubFetch([{ fail: true, errors: { email: ['This email is already registered.'] } }]);
        const w = mountForm();
        await w.get('#reg-email').setValue('taken@example.com');
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('already registered');
        expect(w.get('#reg-email').attributes('readonly')).toBeUndefined();
    });

    it('reveals password + authenticator sections once the code is confirmed', async () => {
        stubFetch([{ data: { sent: true } }, { data: { verified: true } }]);
        const w = mountForm();
        await w.get('#reg-email').setValue('person@example.com');
        await btn(w, 'Send code').trigger('click');
        await flushPromises();

        await w.get('#reg-email-code').setValue('123456');
        await btn(w, 'Confirm code').trigger('click');
        await flushPromises();

        expect(fetchCalls[1].url).toBe('/register/email/verify');
        expect(JSON.parse(fetchCalls[1].opts.body)).toEqual({ code: '123456' });
        expect(w.find('input[type="password"]').exists()).toBe(true);
        expect(w.text()).toContain('Set up authenticator');
    });

    it('a wrong code surfaces an inline error and keeps the rest hidden', async () => {
        stubFetch([{ data: { sent: true } }, { fail: true, errors: { code: ['Invalid or expired code.'] } }]);
        const w = mountForm();
        await w.get('#reg-email').setValue('person@example.com');
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        await w.get('#reg-email-code').setValue('000000');
        await btn(w, 'Confirm code').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('Invalid or expired code.');
        expect(w.find('input[type="password"]').exists()).toBe(false);
    });

    it('"Change email" resets the pending state and re-enables the email field', async () => {
        stubFetch([{ data: { sent: true } }]);
        const w = mountForm();
        await w.get('#reg-email').setValue('person@example.com');
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        expect(w.find('#reg-email-code').exists()).toBe(true);

        await btn(w, 'Change email').trigger('click');
        expect(w.get('#reg-email').attributes('readonly')).toBeUndefined();
        expect(w.find('#reg-email-code').exists()).toBe(false);
    });

    it('Resend is disabled for a 60s cooldown, then re-enables', async () => {
        stubFetch([{ data: { sent: true } }]);
        const w = mountForm();
        await w.get('#reg-email').setValue('person@example.com');
        await btn(w, 'Send code').trigger('click');
        await flushPromises();

        const resend = () => btnStartsWith(w, 'Resend');
        expect(resend().attributes('disabled')).toBeDefined();
        await vi.advanceTimersByTimeAsync(60000);
        expect(resend().attributes('disabled')).toBeUndefined();
    });
});

describe('Register — authenticator + final create gate', () => {
    const verifyEmail = async (w) => {
        await w.get('#reg-email').setValue('person@example.com');
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        await w.get('#reg-email-code').setValue('123456');
        await btn(w, 'Confirm code').trigger('click');
        await flushPromises();
    };

    it('provisions MFA on "Set up authenticator" and renders the QR + secret + recovery codes', async () => {
        stubFetch([
            { data: { sent: true } },
            { data: { verified: true } },
            { data: { secret: 'JBSWY3DPEHPK3PXP', otpauthUri: 'otpauth://totp/x', recoveryCodes: ['aaaa-1111', 'bbbb-2222'] } },
        ]);
        const w = mountForm();
        await verifyEmail(w);
        await btn(w, 'Set up authenticator').trigger('click');
        await flushPromises();

        expect(fetchCalls[2].url).toBe('/register/mfa/provision');
        expect(w.find('img[alt="Two-factor QR code"]').attributes('src')).toBe('data:image/png;base64,mockqr');
        expect(w.text()).toContain('JBSWY3DPEHPK3PXP');
        expect(w.text()).toContain('aaaa-1111');
        expect(w.find('#reg-auth-code').exists()).toBe(true);
    });

    it('Create account stays disabled until email + authenticator are BOTH confirmed, then enables', async () => {
        stubFetch([
            { data: { sent: true } },
            { data: { verified: true } },
            { data: { secret: 'SECRET', otpauthUri: 'otpauth://totp/x', recoveryCodes: ['code-1'] } },
            { data: { confirmed: true } },
        ]);
        const w = mountForm();
        await verifyEmail(w);

        const create = () => btn(w, 'Create account');
        expect(create().attributes('disabled')).toBeDefined();

        await btn(w, 'Set up authenticator').trigger('click');
        await flushPromises();
        expect(create().attributes('disabled')).toBeDefined();   // provisioned but not yet confirmed

        await w.get('#reg-auth-code').setValue('654321');
        await btn(w, 'Confirm authenticator').trigger('click');
        await flushPromises();

        expect(fetchCalls[3].url).toBe('/register/mfa/confirm');
        expect(JSON.parse(fetchCalls[3].opts.body)).toEqual({ code: '654321' });
        expect(create().attributes('disabled')).toBeUndefined();
    });

    it('submitting posts the full form to /register via useForm', async () => {
        stubFetch([
            { data: { sent: true } },
            { data: { verified: true } },
            { data: { secret: 'SECRET', otpauthUri: 'otpauth://totp/x', recoveryCodes: ['code-1'] } },
            { data: { confirmed: true } },
        ]);
        const w = mountForm();
        await w.get('#reg-username').setValue('jdoe');
        await w.get('#reg-fullname').setValue('Jane Doe');
        await w.get('#reg-role').setValue('3');
        await verifyEmail(w);
        await btn(w, 'Set up authenticator').trigger('click');
        await flushPromises();
        await w.get('#reg-auth-code').setValue('654321');
        await btn(w, 'Confirm authenticator').trigger('click');
        await flushPromises();
        await w.get('#reg-password').setValue('Passw0rd!');
        await w.get('#reg-password-confirmation').setValue('Passw0rd!');

        // jsdom's synthetic click on a submit button does not cascade into a form "submit" event
        // (no real user activation), so exercise the @submit.prevent handler directly — the
        // standard vue-test-utils pattern for this.
        await w.get('form').trigger('submit');

        expect(formPost).toHaveBeenCalledTimes(1);
        expect(formPost.mock.calls[0][0]).toBe('/register');
    });

    it('a wrong authenticator code surfaces an inline error and leaves Create disabled', async () => {
        stubFetch([
            { data: { sent: true } },
            { data: { verified: true } },
            { data: { secret: 'SECRET', otpauthUri: 'otpauth://totp/x', recoveryCodes: ['code-1'] } },
            { fail: true, errors: { code: ['Invalid code.'] } },
        ]);
        const w = mountForm();
        await verifyEmail(w);
        await btn(w, 'Set up authenticator').trigger('click');
        await flushPromises();
        await w.get('#reg-auth-code').setValue('000000');
        await btn(w, 'Confirm authenticator').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Invalid code.');
        expect(btn(w, 'Create account').attributes('disabled')).toBeDefined();
    });
});
