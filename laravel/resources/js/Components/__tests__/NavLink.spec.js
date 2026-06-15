import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// NavLink uses @inertiajs/vue3 <Link>; stub it to a plain anchor so we can read href/class/attrs.
vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import NavLink from '@/Components/NavLink.vue';

// the path strings are irrelevant to the assertions — NavLink renders whatever `iconPath` it's given.
const PATH = 'M3 3h18v18H3Z';

const mountLink = (props = {}) =>
    mount(NavLink, { props: { href: '/x', iconPath: PATH, label: 'Thing', ...props } });

describe('NavLink', () => {
    it('renders the label, href and the supplied icon path', () => {
        const w = mountLink({ href: '/patients', label: 'Patients' });
        expect(w.text()).toContain('Patients');
        expect(w.find('a').attributes('href')).toBe('/patients');
        expect(w.find('path').attributes('d')).toBe(PATH);
    });

    it('applies the active classes + aria-current when active', () => {
        const w = mountLink({ active: true });
        const a = w.find('a');
        expect(a.classes()).toContain('bg-white/10');
        expect(a.attributes('aria-current')).toBe('page');
        // the active trailing dot marker is present
        expect(w.find('.bg-brand-400').exists()).toBe(true);
    });

    it('applies the inactive classes + no aria-current when inactive', () => {
        const w = mountLink({ active: false });
        const a = w.find('a');
        expect(a.classes()).toContain('text-navy-200');
        expect(a.attributes('aria-current')).toBeUndefined();
        expect(w.find('.bg-brand-400').exists()).toBe(false);
    });

    it('indents (ml-5) when indent is true', () => {
        expect(mountLink({ indent: true }).find('a').classes()).toContain('ml-5');
        expect(mountLink({ indent: false }).find('a').classes()).not.toContain('ml-5');
    });

    it('renders a count badge span only when badge is a number', () => {
        expect(mountLink({ badge: 4 }).text()).toContain('4');
        const noBadge = mountLink({ badge: null });
        expect(noBadge.find('[aria-label$="items"]').exists()).toBe(false);
        // badge of 0 still renders (an explicit zero is meaningful for a count chip)
        expect(mountLink({ badge: 0 }).find('span[aria-label="0 items"]').exists()).toBe(true);
    });
});
