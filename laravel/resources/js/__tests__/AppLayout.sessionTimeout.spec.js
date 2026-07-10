import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Same controllable-Inertia harness as AppLayout.nav.spec.js / AppLayout.recents.spec.js.
let pageProps;
let pageUrl;
const routerMock = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', props: ['title'], template: '<span />' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: routerMock,
    usePage: () => ({ props: pageProps, get url() { return pageUrl; } }),
}));
vi.mock('@/Components/EhcLogo.vue', () => ({ default: { name: 'EhcLogo', template: '<span />' } }));
vi.mock('@/Components/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', template: '<span />' } }));

import AppLayout from '@/Layouts/AppLayout.vue';

const baseUser = { name: 'T', role: 0, is_admin: true, role_label: 'Admin', can: { add: true, assign: true, manage: true, modify: true } };
const setPage = (over = {}) => {
    pageProps = { appName: 'DMC Internal Medicine', auth: { user: baseUser }, flash: null, unreadNotifications: 0, idleTimeoutMinutes: 0, absTimeoutMinutes: 0, ...over };
    pageUrl = '/';
};
const mountLayout = () => mount(AppLayout, { props: { title: 'X' }, global: { stubs: { Transition: false, Teleport: true } } });

// Wave 5, Item 4: the idle/absolute session-timeout warning. The pure countdown MATH is unit-tested
// in useSessionTimeout.test.js; this file covers the DOM wiring — the panel appears/disappears at the
// right tick, the countdown region carries role="timer" + aria-live, "Stay signed in" is withheld for
// an absolute-reason warning (it can't extend that clock), and both escape hatches (auto-expiry, the
// "Lock now" button) route through the same logout().
describe('AppLayout — session-timeout warning', () => {
    beforeEach(() => {
        localStorage.clear();
        sessionStorage.clear();
        routerMock.post.mockClear();
        routerMock.reload.mockClear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        vi.useFakeTimers();
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('stays hidden before the warn window (idle-only)', async () => {
        setPage({ idleTimeoutMinutes: 5 });
        const w = mountLayout();
        await vi.advanceTimersByTimeAsync(60_000);   // 1 of 5 minutes — nowhere near the last 60s
        expect(w.find('[role="alertdialog"]').exists()).toBe(false);
        w.unmount();
    });

    it('shows a live countdown + Stay signed in + Lock now inside the idle warn window', async () => {
        setPage({ idleTimeoutMinutes: 5 });
        const w = mountLayout();
        await vi.advanceTimersByTimeAsync(4 * 60_000 + 31_000);   // 29s left
        const dialog = w.find('[role="alertdialog"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.text()).toContain('due to inactivity');
        const timer = w.find('[role="timer"]');
        expect(timer.exists()).toBe(true);
        expect(timer.attributes('aria-live')).toBe('polite');
        expect(timer.text()).toMatch(/^\d+ seconds?$/);
        expect(w.find('button[aria-label], button').exists()).toBe(true);
        expect(w.text()).toContain('Stay signed in');
        expect(w.text()).toContain('Lock now');
        w.unmount();
    });

    it('withholds "Stay signed in" when the ABSOLUTE limit is the binding one (it cannot extend it)', async () => {
        setPage({ idleTimeoutMinutes: 0, absTimeoutMinutes: 2 });   // 120s absolute cap, no idle cap
        const w = mountLayout();
        await vi.advanceTimersByTimeAsync(70_000);   // 50s left — inside the 60s warn window
        const dialog = w.find('[role="alertdialog"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.text()).toContain('maximum length');
        expect(w.text()).not.toContain('Stay signed in');
        expect(w.text()).toContain('Lock now');
        w.unmount();
    });

    it('auto-logs-out (routes through the shared logout()) once the binding limit fully elapses', async () => {
        setPage({ idleTimeoutMinutes: 1 });
        const w = mountLayout();
        await vi.advanceTimersByTimeAsync(61_000);
        expect(routerMock.post).toHaveBeenCalledWith('/logout');
        w.unmount();
    });

    it('"Lock now" signs out immediately, without waiting for expiry', async () => {
        setPage({ idleTimeoutMinutes: 5 });
        const w = mountLayout();
        await vi.advanceTimersByTimeAsync(4 * 60_000 + 31_000);   // enter the warn window
        expect(w.find('[role="alertdialog"]').exists()).toBe(true);

        const buttons = w.findAll('button').filter((b) => b.text() === 'Lock now');
        expect(buttons).toHaveLength(1);
        await buttons[0].trigger('click');
        expect(routerMock.post).toHaveBeenCalledWith('/logout');
        w.unmount();
    });

    it('never mounts the idle timer at all when both limits are 0 (unchanged default behavior)', () => {
        setPage();   // idleTimeoutMinutes: 0, absTimeoutMinutes: 0
        const w = mountLayout();
        vi.advanceTimersByTime(60 * 60_000);
        expect(w.find('[role="alertdialog"]').exists()).toBe(false);
        w.unmount();
    });
});
