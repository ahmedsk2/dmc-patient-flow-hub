import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Existing-user email-verify gate (2026-07-11 design, spec §E). "Send" is a plain JSON step
// (no page navigation) so it goes through fetch+xsrf like the rest of the app's non-Inertia async
// calls (Registry/QuickJump precedent); "Confirm" and "Sign out" are genuine Inertia
// redirects/navigations, so they go through router.post (matches AppLayout's logout() and every
// other redirect-producing action in this codebase).

const routerPost = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    router: { post: routerPost },
}));

import EmailVerify from '@/Pages/Auth/EmailVerify.vue';

const mountPage = (email = 'a•••@dmc-im.com') => mount(EmailVerify, { props: { email } });
const btn = (w, text) => w.findAll('button').find((b) => b.text() === text);
const btnStartsWith = (w, text) => w.findAll('button').find((b) => b.text().startsWith(text));

const stubFetch = (ok = true) => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok, json: async () => ({}) })));
};

beforeEach(() => {
    vi.useFakeTimers();
    routerPost.mockClear();
});
afterEach(() => {
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('EmailVerify — masked email + send', () => {
    it('shows the server-masked email prop', () => {
        const w = mountPage('a•••@dmc-im.com');
        expect(w.text()).toContain('a•••@dmc-im.com');
    });

    it('the code input is not present until Send code succeeds', async () => {
        stubFetch(true);
        const w = mountPage();
        expect(w.find('#verify-code').exists()).toBe(false);
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        expect(w.find('#verify-code').exists()).toBe(true);
    });

    it('a failed send keeps the code input hidden and shows an error', async () => {
        stubFetch(false);
        const w = mountPage();
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        expect(w.find('#verify-code').exists()).toBe(false);
        expect(w.text()).toContain('Could not send the code.');
    });
});

describe('EmailVerify — verify is wired to router.post (redirect on success)', () => {
    it('Verify posts the code to /email/verify/confirm', async () => {
        stubFetch(true);
        const w = mountPage();
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        await w.get('#verify-code').setValue('123456');
        await btn(w, 'Verify').trigger('click');

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/email/verify/confirm');
        expect(routerPost.mock.calls[0][1]).toEqual({ code: '123456' });
    });

    it('an onError callback surfaces the field error inline', async () => {
        stubFetch(true);
        // mockImplementationOnce so this doesn't leak into later tests' plain router.post('/logout') calls.
        routerPost.mockImplementationOnce((url, data, opts) => opts.onError({ code: ['Invalid or expired code.'] }));
        const w = mountPage();
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        await w.get('#verify-code').setValue('000000');
        await btn(w, 'Verify').trigger('click');
        await flushPromises();
        expect(w.text()).toContain('Invalid or expired code.');
    });
});

describe('EmailVerify — resend cooldown', () => {
    it('Resend is disabled for 60s after a send, then re-enables', async () => {
        stubFetch(true);
        const w = mountPage();
        await btn(w, 'Send code').trigger('click');
        await flushPromises();
        const resend = () => btnStartsWith(w, 'Resend');
        expect(resend().attributes('disabled')).toBeDefined();
        await vi.advanceTimersByTimeAsync(60000);
        expect(resend().attributes('disabled')).toBeUndefined();
    });
});

describe('EmailVerify — sign out', () => {
    it('Sign out posts to /logout via router', async () => {
        const w = mountPage();
        await btn(w, 'Sign out').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/logout');
    });
});
