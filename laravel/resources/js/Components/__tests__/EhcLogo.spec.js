import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import EhcLogo from '@/Components/EhcLogo.vue';

describe('EhcLogo', () => {
    it('prefers the official asset at /images/ehc-logo.svg', () => {
        const img = mount(EhcLogo).find('img');
        expect(img.attributes('src')).toBe('/images/ehc-logo.svg');
        expect(img.attributes('alt')).toBe('Eastern Health Cluster');
    });

    it('requests the mono asset when mono is set (dark headers, print)', () => {
        expect(mount(EhcLogo, { props: { mono: true } }).find('img').attributes('src'))
            .toBe('/images/ehc-logo-mono.svg');
    });

    it('falls back to the inline recreation when the asset 404s', async () => {
        const w = mount(EhcLogo);
        await w.find('img').trigger('error');
        expect(w.find('img').exists()).toBe(false);
        const svg = w.find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.attributes('aria-label')).toBe('Eastern Health Cluster');
        expect(w.findAll('path')).toHaveLength(5); // the five flame petals
    });

    it('the mono fallback paints in currentColor and drops the brand gradient', async () => {
        const w = mount(EhcLogo, { props: { mono: true } });
        await w.find('img').trigger('error');
        expect(w.find('linearGradient').exists()).toBe(false);
        expect(w.find('g').attributes('fill')).toBe('currentColor');
    });

    it('re-arms the <img> when the mono prop flips (the other file may well exist)', async () => {
        const w = mount(EhcLogo);
        await w.find('img').trigger('error');
        expect(w.find('svg').exists()).toBe(true);
        await w.setProps({ mono: true });
        expect(w.find('img').exists()).toBe(true);
    });

    it('the mono medallion disc is a translucent wash; ring and dot stay opaque', async () => {
        const w = mount(EhcLogo, { props: { mono: true } });
        await w.find('img').trigger('error');
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
    it('the medallion ring uses a non-scaling stroke so it never renders sub-pixel', async () => {
        const w = mount(EhcLogo);
        await w.find('img').trigger('error');
        const ring = w.findAll('circle')[0];
        expect(ring.attributes('stroke-width')).toBe('1.4');
        expect(ring.attributes('vector-effect')).toBe('non-scaling-stroke');
    });

    it('the fallback exposes an accessible name via role=img', async () => {
        const w = mount(EhcLogo);
        await w.find('img').trigger('error');
        expect(w.find('svg').attributes('role')).toBe('img');
    });

    // Login.vue mounts two EhcLogo simultaneously (`hidden lg:flex` / `lg:hidden` — both in the DOM).
    // They must be mounted in ONE app, as Login.vue does: useId's counter lives on the app context,
    // so two separate mount() calls would each restart at "v-0" and mask a real duplicate-id bug.
    it('gives each instance a unique gradient id, and each binds to its own', async () => {
        const Host = { components: { EhcLogo }, template: '<div><EhcLogo /><EhcLogo /></div>' };
        const w = mount(Host);

        const imgs = w.findAll('img');
        expect(imgs).toHaveLength(2);
        await imgs[0].trigger('error');
        await imgs[1].trigger('error');

        const ids = w.findAll('linearGradient').map((g) => g.attributes('id'));
        expect(ids).toHaveLength(2);
        expect(ids[0]).not.toBe(ids[1]);

        // Each petal group must reference its OWN gradient, not just any unique-looking id.
        const fills = w.findAll('g').map((g) => g.attributes('fill'));
        expect(fills).toEqual([`url(#${ids[0]})`, `url(#${ids[1]})`]);
    });
});
