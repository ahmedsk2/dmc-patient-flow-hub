import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import Sparkline from '@/Components/Sparkline.vue';

const mountSpark = (props = {}) => mount(Sparkline, { props: { data: [], ariaLabel: 'spark', ...props } });

describe('Sparkline', () => {
    it('renders one polyline coordinate per data point', () => {
        const w = mountSpark({ data: [1, 5, 2, 8, 3] });
        const poly = w.find('polyline');
        expect(poly.exists()).toBe(true);
        const coords = poly.attributes('points').trim().split(/\s+/);
        expect(coords).toHaveLength(5);
        // every coord is an "x,y" pair
        expect(coords.every((c) => c.includes(','))).toBe(true);
    });

    it('applies the aria-label to a role="img" svg', () => {
        const w = mountSpark({ data: [1, 2, 3], ariaLabel: 'Admissions, last 30 days' });
        const svg = w.find('svg');
        expect(svg.attributes('role')).toBe('img');
        expect(svg.attributes('aria-label')).toBe('Admissions, last 30 days');
    });

    // A single point (or none) is not a trend — a one-coordinate polyline renders as an invisible
    // dot, so we draw NO polyline (the empty state).
    it('draws nothing (no polyline) when there are fewer than two points', () => {
        expect(mountSpark({ data: [] }).find('polyline').exists()).toBe(false);
        expect(mountSpark({ data: [7] }).find('polyline').exists()).toBe(false);
    });

    // A flat series has zero span — the scaling must not divide by zero into NaN coordinates.
    it('stays finite (no NaN) for a flat series', () => {
        const w = mountSpark({ data: [4, 4, 4] });
        expect(w.find('polyline').attributes('points')).not.toContain('NaN');
    });

    // The caller sets the text colour token; the graphic inherits it via currentColor.
    it('uses currentColor for the stroke so the caller controls the hue', () => {
        const poly = mountSpark({ data: [1, 2] }).find('polyline');
        expect(poly.attributes('stroke')).toBe('currentColor');
    });
});
