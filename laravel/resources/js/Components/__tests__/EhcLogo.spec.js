import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import EhcLogo from '@/Components/EhcLogo.vue';

// 2026-09-24 role/UX review #5: neither official asset has ever been committed
// (public/images/BRAND_README.md §1.3), so probing for them 404'd on every single page load, for
// every user, forever — pure console noise, since the recreation below always renders anyway.
// USE_OFFICIAL_ASSET is now OFF by default (a module-scope const inside EhcLogo.vue, documented
// there and in BRAND_README.md §1.5): the vector recreation renders immediately and NO <img>, and
// therefore no network request, is ever issued. These specs cover that default, always-on path.
describe('EhcLogo', () => {
    it('renders the vector recreation directly — no <img> probe, no network request', () => {
        const w = mount(EhcLogo);
        expect(w.find('img').exists()).toBe(false);
        const svg = w.find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.attributes('aria-label')).toBe('Eastern Health Cluster');
        expect(w.findAll('path')).toHaveLength(5); // the five flame petals
    });

    it('the mono variant paints in currentColor and drops the brand gradient, with no <img> either', () => {
        const w = mount(EhcLogo, { props: { mono: true } });
        expect(w.find('img').exists()).toBe(false);
        expect(w.find('linearGradient').exists()).toBe(false);
        expect(w.find('g').attributes('fill')).toBe('currentColor');
    });

    it('the mono medallion disc is a translucent wash; ring and dot stay opaque', () => {
        const w = mount(EhcLogo, { props: { mono: true } });
        const circles = w.findAll('circle');
        expect(circles).toHaveLength(2);
        expect(circles[0].attributes('fill-opacity')).toBe('0.25');   // disc
        expect(circles[0].attributes('stroke')).toBe('currentColor'); // ring: opaque, carries the form
        expect(circles[1].attributes('fill-opacity')).toBeUndefined(); // inner dot: opaque
    });

    // W0-T4b. The ring stroke is 1.4 USER UNITS on a 100-unit viewBox; at the 28px header size that
    // scales to 0.39 CSS px — sub-pixel, effectively invisible. `vector-effect="non-scaling-stroke"`
    // makes the browser evaluate the stroke width AFTER the viewport transform, so it stays a crisp
    // 1.4 CSS px at any render size. Assert it is present on the ring (circles[0]), absent-irrelevant
    // on the strokeless inner dot.
    it('the medallion ring uses a non-scaling stroke so it never renders sub-pixel', () => {
        const ring = mount(EhcLogo).findAll('circle')[0];
        expect(ring.attributes('stroke-width')).toBe('1.4');
        expect(ring.attributes('vector-effect')).toBe('non-scaling-stroke');
    });

    it('exposes an accessible name via role=img', () => {
        expect(mount(EhcLogo).find('svg').attributes('role')).toBe('img');
    });

    // Login.vue mounts two EhcLogo simultaneously (`hidden lg:flex` / `lg:hidden` — both in the DOM).
    // They must be mounted in ONE app, as Login.vue does: useId's counter lives on the app context,
    // so two separate mount() calls would each restart at "v-0" and mask a real duplicate-id bug.
    it('gives each instance a unique gradient id, and each binds to its own', () => {
        const Host = { components: { EhcLogo }, template: '<div><EhcLogo /><EhcLogo /></div>' };
        const w = mount(Host);

        const ids = w.findAll('linearGradient').map((g) => g.attributes('id'));
        expect(ids).toHaveLength(2);
        expect(ids[0]).not.toBe(ids[1]);

        // Each petal group must reference its OWN gradient, not just any unique-looking id.
        const fills = w.findAll('g').map((g) => g.attributes('fill'));
        expect(fills).toEqual([`url(#${ids[0]})`, `url(#${ids[1]})`]);
    });
});
