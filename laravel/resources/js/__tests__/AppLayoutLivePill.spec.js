import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// 2026-09-24 role/UX review #33: the "Live" pill in the header carries an InfoTip. It renders on
// EVERY page (AppLayout is the shared shell), but the only real timed auto-refresh in the app is
// Dashboard.vue's 5-minute, visibility-gated setInterval — so the tooltip text must not overclaim a
// fixed interval on a page that doesn't actually poll. New file, not extending the existing
// AppLayout.*.spec.js files several implementers touch concurrently in this working tree.
let pageProps;
let currentUrl;
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', props: ['title'], template: '<span />' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: pageProps, url: currentUrl }),
}));
vi.mock('@/Components/EhcLogo.vue', () => ({ default: { name: 'EhcLogo', template: '<span />' } }));
vi.mock('@/Components/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', template: '<span />' } }));

import AppLayout from '@/Layouts/AppLayout.vue';
import InfoTip from '@/Components/InfoTip.vue';

const mountLayout = () => mount(AppLayout, {
    props: { title: 'X' },
    global: { stubs: { Transition: false, Teleport: true } },
});

describe('AppLayout — "Live" pill InfoTip (review #33)', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        pageProps = {
            appName: 'DMC Internal Medicine', flash: null, unreadNotifications: 0,
            auth: { user: { name: 'T', role: 0, is_admin: true, role_label: 'Admin', can: { add: true, assign: true, manage: true, modify: true } } },
        };
    });

    it('on the Dashboard route, names the real 5-minute auto-refresh', () => {
        currentUrl = '/';
        const tip = mountLayout().findComponent(InfoTip);
        expect(tip.exists()).toBe(true);
        expect(tip.props('label')).toBe('Live');
        expect(tip.props('text')).toMatch(/5 minutes/);
    });

    it('elsewhere, does not claim a 5-minute refresh that page never performs', () => {
        currentUrl = '/patients';
        const tip = mountLayout().findComponent(InfoTip);
        expect(tip.exists()).toBe(true);
        expect(tip.props('text')).not.toMatch(/5 minutes/);
    });
});
