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

    // W0-T3i. The urgent tint used to be `bg-danger-50/60` — a step that was never declared, so it
    // emitted NO CSS and the "urgent" card rendered as an ordinary white/dark card. The fill is now
    // the theme-aware `bg-tint-danger`, at the same 60% the original reached for. Because this card
    // has no panel behind it (it sits straight on the page surface), the tint REPLACES `bg-card`
    // rather than layering over it — otherwise the two background-colour utilities would race in the
    // cascade and the winner would depend on emission order, not on class order.
    //
    // The count also moves off the raw `danger-600` step: that is 5.63:1 on the white card but only
    // 2.97:1 on the dark one, and it would be 3.02:1 on the new dark tint. `text-on-danger` is
    // theme-aware and clears 5.82:1 light / 8.78:1 dark on the tint. Ratios: scripts/contrast.mjs.
    it('applies the theme-aware danger tint when urgent and count > 0, replacing the card surface', () => {
        const w = mountCard({ urgent: true, count: 3 });
        const c = w.find('a').classes();
        expect(c).toEqual(expect.arrayContaining(['bg-tint-danger/60', 'border-danger-300/60']));
        expect(c).not.toContain('bg-card');            // one element, one background-color
        expect(c).not.toContain('bg-danger-50/60');    // undeclared step — emitted zero CSS
        expect(w.find('p.text-on-danger').exists()).toBe(true);
        expect(w.find('p.text-danger-600').exists()).toBe(false);   // 2.97:1 on the dark card
    });

    it('does NOT apply the danger tint when urgent but count is 0', () => {
        const w = mountCard({ urgent: true, count: 0 });
        const c = w.find('a').classes();
        expect(c).not.toContain('bg-tint-danger/60');
        expect(c).toEqual(expect.arrayContaining(['border-line', 'bg-card']));
        expect(w.find('p.text-on-danger').exists()).toBe(false);
        expect(w.find('p.text-ink-900').exists()).toBe(true);
    });

    it('never applies the danger tint when not urgent, regardless of count', () => {
        const w = mountCard({ urgent: false, count: 99 });
        const c = w.find('a').classes();
        expect(c).not.toContain('bg-tint-danger/60');
        expect(c).toContain('bg-card');
        expect(w.find('p.text-on-danger').exists()).toBe(false);
    });
});
