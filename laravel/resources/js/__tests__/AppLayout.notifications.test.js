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

    // HO-T7: persistent "incomplete handover" reminders render in a pinned group above the
    // regular notifications list, and stay lit (the read-all POST doesn't clear them server-side).
    // The SAME item comes back in BOTH `actionable` and the all-types `notifications` feed — it must
    // render ONCE (pinned), never duplicated in the normal list (spec §C3).
    it('renders an actionable handover.incomplete reminder ONCE (pinned), not duplicated in the feed', async () => {
        const item = { id: 1, type: 'handover.incomplete', payload: { admission_id: 42, patient_name: 'Jane Doe', mrn: '12345', from_name: 'Smith' }, read_at: null, resolved_at: null, created_at: new Date().toISOString() };
        global.fetch
            .mockResolvedValueOnce({
                json: async () => ({
                    notifications: [item],   // the all-types feed includes it too
                    actionable: [item],      // …and it's pinned
                    unread: 1,
                }),
            })
            .mockResolvedValueOnce({ ok: true, json: async () => ({}) });
        const w = mountLayout();
        await w.vm.toggleBell();
        await w.vm.$nextTick();

        // pinned "Needs attention" group rendered for the actionable item — and it links by admission
        // id (?highlight=42), never by MRN, so no PHI rides in the URL.
        expect(w.vm.actionable).toHaveLength(1);
        expect(w.text()).toContain('Needs attention');
        expect(w.html()).toContain('/patients?highlight=42');

        // dedup (spec §C3): the same id is filtered out of the all-types feed, so the normal list
        // does NOT render a second copy — feedNotifications is empty and the feed <ul> isn't rendered.
        expect(w.vm.feedNotifications).toHaveLength(0);
        expect(w.find('ul.max-h-80').exists()).toBe(false);
        // the actionable link target appears exactly once in the DOM (pinned only, no duplicate row)
        expect(w.html().split('/patients?highlight=42').length - 1).toBe(1);
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
