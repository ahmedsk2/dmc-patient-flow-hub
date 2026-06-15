import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import Breadcrumbs from '@/Components/Breadcrumbs.vue';

const mountCrumbs = (crumbs) => mount(Breadcrumbs, { props: { crumbs } });

describe('Breadcrumbs', () => {
    it('renders nothing for 0 or 1 crumbs', () => {
        expect(mountCrumbs([]).find('nav').exists()).toBe(false);
        expect(mountCrumbs([{ label: 'Solo' }]).find('nav').exists()).toBe(false);
    });

    it('renders a labelled nav for 2+ crumbs', () => {
        const w = mountCrumbs([{ label: 'Administration' }, { label: 'Reports', href: '/reports' }, { label: 'M&M Pack' }]);
        const nav = w.find('nav');
        expect(nav.exists()).toBe(true);
        expect(nav.attributes('aria-label')).toBe('Breadcrumb');
        expect(w.findAll('li')).toHaveLength(3);
    });

    it('renders intermediate crumbs with an href as links and the final crumb as the current page', () => {
        const w = mountCrumbs([{ label: 'Administration' }, { label: 'Reports', href: '/reports' }, { label: 'M&M Pack' }]);
        // the middle crumb is a link to its href
        const link = w.find('a[href="/reports"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('Reports');
        // the last crumb is current page, no link
        const current = w.find('[aria-current="page"]');
        expect(current.exists()).toBe(true);
        expect(current.text()).toBe('M&M Pack');
        expect(current.element.tagName).not.toBe('A');
    });

    it('a non-final crumb without an href renders as plain text (section label)', () => {
        const w = mountCrumbs([{ label: 'Administration' }, { label: 'Reports' }]);
        // "Administration" has no href and is not the final crumb -> plain span, not a link, not aria-current
        const adminLi = w.findAll('li')[0];
        expect(adminLi.find('a').exists()).toBe(false);
        expect(adminLi.find('[aria-current="page"]').exists()).toBe(false);
        expect(adminLi.text()).toBe('Administration');
    });
});
