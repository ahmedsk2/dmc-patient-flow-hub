import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * Wave 1 (EHC UI) — logging out must wipe the command palette's recent-patient ids (module
 * memory). Shared clinical workstations: the next user at the same tab must open an empty
 * palette, not the previous user's patient trail. Mirrors AppLayout.nav.spec.js's harness.
 */
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
import { pushRecent, clearRecents, recentIds } from '@/lib/recentPatients';

const setPage = (user, url = '/') => {
    pageProps = { appName: 'DMC Internal Medicine', auth: { user }, flash: null, unreadNotifications: 0 };
    pageUrl = url;
};

describe('AppLayout — logout clears the palette recents', () => {
    beforeEach(() => {
        localStorage.clear();
        clearRecents();
        routerMock.post.mockClear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        setPage({ name: 'T', role: 0, is_admin: true, role_label: 'Admin', can: { add: true, assign: true, manage: true, modify: true } });
    });

    it('the sign-out button resets the recents module before posting /logout', async () => {
        pushRecent(11);
        pushRecent(12);
        expect(recentIds()).toEqual([12, 11]);

        const w = mount(AppLayout, { props: { title: 'X' }, global: { stubs: { Transition: false, Teleport: true } } });
        await w.get('button[aria-label="Sign out"]').trigger('click');

        expect(recentIds()).toEqual([]);
        expect(routerMock.post).toHaveBeenCalledWith('/logout');
        w.unmount();
    });
});
