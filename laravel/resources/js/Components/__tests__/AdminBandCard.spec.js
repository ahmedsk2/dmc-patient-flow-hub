import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import AdminBandCard from '@/Components/AdminBandCard.vue';

const PATH = 'M3 3h18v18H3Z';
const mountCard = (props = {}) =>
    mount(AdminBandCard, { props: { label: 'Data Quality', count: 0, href: '/data-quality', iconPath: PATH, ...props } });

describe('AdminBandCard', () => {
    it('renders the count, label, href and icon', () => {
        const w = mountCard({ label: 'Security Anomalies', count: 7, href: '/security' });
        expect(w.text()).toContain('7');
        expect(w.text()).toContain('Security Anomalies');
        expect(w.find('a').attributes('href')).toBe('/security');
        expect(w.find('path').attributes('d')).toBe(PATH);
    });

    it('applies the danger tint when urgent and count > 0', () => {
        const w = mountCard({ urgent: true, count: 3 });
        expect(w.find('a').classes()).toContain('bg-danger-50/60');
        expect(w.find('p.text-danger-600').exists()).toBe(true);
    });

    it('does NOT apply the danger tint when urgent but count is 0', () => {
        const w = mountCard({ urgent: true, count: 0 });
        expect(w.find('a').classes()).not.toContain('bg-danger-50/60');
        expect(w.find('a').classes()).toContain('border-line');
        expect(w.find('p.text-danger-600').exists()).toBe(false);
    });

    it('never applies the danger tint when not urgent, regardless of count', () => {
        const w = mountCard({ urgent: false, count: 99 });
        expect(w.find('a').classes()).not.toContain('bg-danger-50/60');
        expect(w.find('p.text-danger-600').exists()).toBe(false);
    });
});
