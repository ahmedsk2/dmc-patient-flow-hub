import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Controllable Inertia mock: usePage() reads the module-level pageProps + pageUrl so each test
// can set the auth user shape and the current URL (drives isActive / aria-current).
let pageProps;
let pageUrl;
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', props: ['title'], template: '<span />' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: pageProps, get url() { return pageUrl; } }),
}));
vi.mock('@/Components/EhcLogo.vue', () => ({ default: { name: 'EhcLogo', template: '<span />' } }));
vi.mock('@/Components/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', template: '<span />' } }));

import AppLayout from '@/Layouts/AppLayout.vue';
import NavLink from '@/Components/NavLink.vue';

const baseUser = (over = {}) => ({
    name: 'T', role: 0, is_admin: true, role_label: 'Admin',
    can: { add: true, assign: true, manage: true, modify: true },
    ...over,
});
const setPage = (user, url = '/') => {
    pageProps = { appName: 'DMC Internal Medicine', auth: { user }, flash: null, unreadNotifications: 0 };
    pageUrl = url;
};

const mountLayout = (props = {}) => mount(AppLayout, {
    props: { title: 'X', ...props },
    global: { stubs: { Transition: false, Teleport: true } },
});

const labels = (w) => w.findAllComponents(NavLink).map((n) => n.props('label'));

describe('AppLayout — admin nav sections (Item 1)', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        setPage(baseUser());
    });

    it('renders all four labelled admin sub-sections for an admin', () => {
        const w = mountLayout();
        const text = w.text();
        for (const heading of ['Analytics & Reports', 'Governance & Safety', 'Data Management', 'Settings']) {
            expect(text).toContain(heading);
        }
    });

    it('a non-admin sees zero admin section headings', () => {
        setPage(baseUser({ role: 3, is_admin: false }));
        const text = mountLayout().text();
        for (const heading of ['Analytics & Reports', 'Governance & Safety', 'Data Management', 'Settings']) {
            expect(text).not.toContain(heading);
        }
    });

    it('marks the active link with aria-current="page"', () => {
        setPage(baseUser(), '/registry');
        const w = mountLayout();
        const active = w.findAllComponents(NavLink).filter((n) => n.props('href') === '/registry');
        expect(active).toHaveLength(1);
        expect(active[0].props('active')).toBe(true);
        expect(active[0].find('[aria-current="page"]').exists()).toBe(true);
    });
});

describe('AppLayout — M&M nesting under Reports (Item 2)', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        setPage(baseUser());
    });

    it('renders M&M Pack as an indented child, not a top-level row', () => {
        const w = mountLayout();
        const mm = w.findAllComponents(NavLink).filter((n) => n.props('href') === '/reports/governance');
        expect(mm).toHaveLength(1);
        expect(mm[0].props('indent')).toBe(true);
        // Reports is still its own top-level entry
        expect(w.findAllComponents(NavLink).some((n) => n.props('href') === '/reports' && !n.props('indent'))).toBe(true);
    });

    it('on /reports/governance both Reports and the M&M child are active (startsWith)', () => {
        setPage(baseUser(), '/reports/governance');
        const w = mountLayout();
        const reports = w.findAllComponents(NavLink).find((n) => n.props('href') === '/reports');
        const mm = w.findAllComponents(NavLink).find((n) => n.props('href') === '/reports/governance');
        expect(reports.props('active')).toBe(true);
        expect(mm.props('active')).toBe(true);
    });
});

describe('AppLayout — Recent Activity moved to Clinical (Item 3)', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    });

    it('Recent Activity appears in the Clinical group for every role incl. Observer', () => {
        for (const role of [0, 2, 3, 4, 5]) {
            setPage(baseUser({ role, is_admin: role === 0, can: { add: role !== 5 } }));
            const w = mountLayout();
            const recent = w.findAllComponents(NavLink).filter((n) => n.props('href') === '/recent');
            expect(recent.length, `role ${role} should see Recent Activity`).toBe(1);
            // it is NOT indented and NOT under an admin section heading (it's clinical now)
            expect(recent[0].props('indent')).toBe(false);
        }
    });

    it('Recent Activity is never a top-level admin entry', () => {
        setPage(baseUser());
        const w = mountLayout();
        // exactly one /recent NavLink total — the clinical one
        expect(w.findAllComponents(NavLink).filter((n) => n.props('href') === '/recent')).toHaveLength(1);
    });
});

describe('AppLayout — role/capability-aware nav (Item 4)', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    });

    it('hides New Admissions when can.add is false', () => {
        setPage(baseUser({ role: 3, is_admin: false, can: { add: false } }));
        expect(labels(mountLayout())).not.toContain('New Admissions');
    });

    it('shows New Admissions when can.add is true', () => {
        setPage(baseUser({ role: 3, is_admin: false, can: { add: true } }));
        expect(labels(mountLayout())).toContain('New Admissions');
    });

    it('Observer (role 5) sees neither New Admissions nor Consultations', () => {
        setPage(baseUser({ role: 5, is_admin: false, can: { add: false } }));
        const l = labels(mountLayout());
        expect(l).not.toContain('New Admissions');
        expect(l).not.toContain('Consultations');
        // but still sees the read-only clinical board + recent activity
        expect(l).toContain('Patients');
        expect(l).toContain('Recent Activity');
    });
});

describe('AppLayout — breadcrumbs + document title (Items 5 + 6)', () => {
    beforeEach(() => {
        localStorage.clear();
        if (!window.matchMedia) window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        setPage(baseUser());
    });

    it('renders a Breadcrumb nav when 2+ crumbs are passed', () => {
        const w = mountLayout({ breadcrumbs: [{ label: 'Administration' }, { label: 'Reports' }] });
        expect(w.find('nav[aria-label="Breadcrumb"]').exists()).toBe(true);
    });

    it('renders no Breadcrumb nav with 0/1 crumbs', () => {
        expect(mountLayout({ breadcrumbs: [] }).find('nav[aria-label="Breadcrumb"]').exists()).toBe(false);
        expect(mountLayout({ breadcrumbs: [{ label: 'X' }] }).find('nav[aria-label="Breadcrumb"]').exists()).toBe(false);
    });

    it('sets the document <Head> title to "{title} — DMC Internal Medicine"', () => {
        const w = mountLayout({ title: 'Statistics' });
        const head = w.findComponent({ name: 'Head' });
        expect(head.props('title')).toBe('Statistics — DMC Internal Medicine');
    });

    it('falls back to the bare app name when no title is given', () => {
        const w = mountLayout({ title: '' });
        expect(w.findComponent({ name: 'Head' }).props('title')).toBe('DMC Internal Medicine');
    });
});
