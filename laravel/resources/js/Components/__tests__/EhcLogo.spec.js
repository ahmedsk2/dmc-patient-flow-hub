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
});
