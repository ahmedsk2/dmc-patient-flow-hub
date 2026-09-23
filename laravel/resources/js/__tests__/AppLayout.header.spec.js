import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// 2026-09-23 UAT (NF-01/NF-02): at phone width the breadcrumb's one-word crumbs could not wrap and ran
// under the header icons; at tablet width a wrapped trail was taller than the FIXED 64px header, so the
// page title was pushed off the top. jsdom cannot lay out, so these pin the structure that prevents it:
// a header that grows (min-h, not h), a title block that takes the free width, crumbs hidden below sm.
let pageProps;
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', props: ['title'], template: '<span />' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: pageProps, url: '/control' }),
}));
vi.mock('@/Components/EhcLogo.vue', () => ({ default: { name: 'EhcLogo', template: '<span />' } }));
vi.mock('@/Components/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', template: '<span />' } }));

import AppLayout from '@/Layouts/AppLayout.vue';

const mountLayout = () => mount(AppLayout, {
    props: { title: 'Control Panel', breadcrumbs: [{ label: 'Administration', href: '/control' }, { label: 'Settings' }] },
    global: { stubs: { Transition: false, Teleport: true } },
});

describe('AppLayout — header never clips the page title', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        pageProps = {
            appName: 'DMC Internal Medicine', flash: null, unreadNotifications: 0,
            auth: { user: { name: 'T', role: 0, is_admin: true, role_label: 'Admin', can: { add: true, assign: true, manage: true, modify: true } } },
        };
    });

    it('grows with its content instead of a fixed height', () => {
        const header = mountLayout().find('header');
        expect(header.classes()).toContain('min-h-16');
        expect(header.classes()).not.toContain('h-16');
    });

    it('gives the title block the free width and hides the crumb trail below sm', () => {
        const w = mountLayout();
        const h1 = w.find('header h1');
        expect(h1.text()).toBe('Control Panel');
        expect(h1.element.parentElement.classList.contains('flex-1')).toBe(true);
        const crumbs = w.find('header nav[aria-label="Breadcrumb"]');
        expect(crumbs.exists()).toBe(true);
        expect(crumbs.classes()).toEqual(expect.arrayContaining(['hidden', 'sm:block']));
    });

    it('keeps an accessible name on the profile link now that its visible name shows only at xl', () => {
        const link = mountLayout().find('header a[href="/profile"]');
        expect(link.attributes('aria-label')).toBe('My profile: T, Admin');
    });
});

describe('AppLayout — the closed mobile drawer is out of the Tab order', () => {
    it('is inert while closed below lg, and not once opened', async () => {
        window.matchMedia = (q) => ({ matches: q === '(max-width: 1023px)', addEventListener() {}, removeEventListener() {} });
        pageProps = {
            appName: 'DMC Internal Medicine', flash: null, unreadNotifications: 0,
            auth: { user: { name: 'T', role: 0, is_admin: true, role_label: 'Admin', can: { add: true, assign: true, manage: true, modify: true } } },
        };
        const w = mountLayout();
        const aside = () => w.find('#app-sidebar');
        expect(aside().attributes('inert')).toBeDefined();
        expect(aside().attributes('aria-hidden')).toBe('true');

        await w.find('button[aria-label="Open navigation menu"]').trigger('click');
        expect(aside().attributes('inert')).toBeUndefined();
        expect(aside().attributes('aria-hidden')).toBeUndefined();
    });
});
