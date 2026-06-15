import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// ---- Inertia mock --------------------------------------------------------------------------
// AppLayout imports Link/router/usePage from @inertiajs/vue3. We mock the module so usePage()
// returns a controllable props object and router is inert.
let pageProps;
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<head><slot /></head>' },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: pageProps, get url() { return '/'; } }),
}));
// EhcLogo / ConfirmDialog children are irrelevant to this test; stub the logo to avoid SVG noise.
vi.mock('@/Components/EhcLogo.vue', () => ({ default: { name: 'EhcLogo', template: '<span />' } }));
vi.mock('@/Components/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', template: '<span />' } }));

import AppLayout from '@/Layouts/AppLayout.vue';

const baseProps = () => ({
    auth: { user: { name: 'Test', role: 0, is_admin: true, role_label: 'Admin' } },
    flash: null,
    unreadNotifications: 3,
});

const mountLayout = () => shallowMount(AppLayout, {
    props: { title: 'X' },
    global: { stubs: { Transition: false, Teleport: true } },
});

describe('AppLayout notifications', () => {
    beforeEach(() => {
        pageProps = baseProps();
        // localStorage + matchMedia shims used in onMounted
        localStorage.clear();
        if (!window.matchMedia) {
            window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        }
        global.fetch = vi.fn();
        // a fake XSRF cookie for the read-all POST header
        document.cookie = 'XSRF-TOKEN=tok123';
    });
    afterEach(() => { vi.restoreAllMocks(); });

    it('unread computed reflects the prop while readOverride is false', () => {
        const w = mountLayout();
        expect(w.vm.unread).toBe(3);
    });

    it('toggleBell fetches notifications, POSTs read-all when unread>0, and zeroes the badge', async () => {
        global.fetch
            .mockResolvedValueOnce({ json: async () => ({ notifications: [{ id: 1 }], unread: 3 }) })  // /api/notifications
            .mockResolvedValueOnce({ ok: true, json: async () => ({}) });                               // /notifications/read-all
        const w = mountLayout();
        await w.vm.toggleBell();

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(global.fetch.mock.calls[0][0]).toBe('/api/notifications');
        expect(global.fetch.mock.calls[1][0]).toBe('/notifications/read-all');
        expect(global.fetch.mock.calls[1][1]).toMatchObject({ method: 'POST' });
        expect(w.vm.notifications).toEqual([{ id: 1 }]);
        // optimistic zero after read-all
        expect(w.vm.unread).toBe(0);
    });

    it('toggleBell does NOT POST read-all when unread is 0', async () => {
        global.fetch.mockResolvedValueOnce({ json: async () => ({ notifications: [], unread: 0 }) });
        const w = mountLayout();
        await w.vm.toggleBell();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe('/api/notifications');
        // readOverride still set → badge zeroed
        expect(w.vm.unread).toBe(0);
    });

    it('closing the bell (second toggle) does not fetch again', async () => {
        global.fetch.mockResolvedValue({ json: async () => ({ notifications: [], unread: 0 }) });
        const w = mountLayout();
        await w.vm.toggleBell();      // open → 1 fetch
        await w.vm.toggleBell();      // close → no fetch
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });
});

describe('AppLayout flash toast roles', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) {
            window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        }
    });

    it('renders role="status" + aria-live="polite" for a success toast', async () => {
        pageProps = { ...baseProps(), flash: { type: 'success', message: 'Saved' } };
        const w = mountLayout();
        await w.vm.$nextTick();
        const el = w.find('[role="status"]');
        expect(el.exists()).toBe(true);
        expect(el.attributes('aria-live')).toBe('polite');
    });

    it('renders role="alert" + aria-live="assertive" for an error toast', async () => {
        pageProps = { ...baseProps(), flash: { type: 'error', message: 'Boom' } };
        const w = mountLayout();
        await w.vm.$nextTick();
        const el = w.find('[role="alert"]');
        expect(el.exists()).toBe(true);
        expect(el.attributes('aria-live')).toBe('assertive');
    });

    it('renders the skip-to-content link as the first child', () => {
        pageProps = baseProps();
        const w = mountLayout();
        const skip = w.find('a[href="#main-content"]');
        expect(skip.exists()).toBe(true);
        expect(skip.text()).toContain('Skip to content');
    });
});
