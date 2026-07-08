import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import FlowAlert from '@/Components/FlowAlert.vue';

const mountAlert = (props = {}, slots = {}) =>
    mount(FlowAlert, { props: { title: 'Two discharges are overdue', ...props }, slots });

const has = (cls, re) => cls.some((c) => re.test(c));

describe('FlowAlert', () => {
    it('renders the title and the default-slot body', () => {
        const w = mountAlert({}, { default: 'Review the boarding worklist.' });
        expect(w.text()).toContain('Two discharges are overdue');
        expect(w.text()).toContain('Review the boarding worklist.');
    });

    it('carries a visually-hidden screen-reader prefix per tone (colour is never alone)', () => {
        expect(mountAlert({ tone: 'info' }).find('.sr-only').text()).toBe('Information:');
        expect(mountAlert({ tone: 'warning' }).find('.sr-only').text()).toBe('Important:');
        expect(mountAlert({ tone: 'critical' }).find('.sr-only').text()).toBe('Action needed:');
    });

    // The prefix shares a <p> with the title, so AT reads them as one string. `.text()` trims, so
    // only raw textContent can catch a run-together "Information:Two discharges are overdue".
    it('separates the screen-reader prefix from the title (not run together)', () => {
        const p = mountAlert({ tone: 'info' }).get('p').element.textContent;
        expect(p).toBe('Information: Two discharges are overdue');
    });

    it('always renders a decorative icon beside the text', () => {
        const svg = mountAlert({ tone: 'critical' }).find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.attributes('aria-hidden')).toBe('true');
    });

    // Locks the PROPERTY (three silhouettes: circle / triangle / octagon), not the curve data — the
    // paths may be redrawn freely, but two tones may never share a glyph, or the icon stops being a
    // signal at those tiers.
    it('gives every tone a pairwise-distinct icon path', () => {
        const d = (tone) => mountAlert({ tone }).get('path').attributes('d');
        const paths = [d('info'), d('warning'), d('critical')];
        expect(new Set(paths).size).toBe(3);
    });

    // Shape-agnostic: catches a tone half-added to some maps but not others (six coordinated edits,
    // and a miss produces NO signal rather than a loud failure).
    it.each(['info', 'warning', 'critical'])('tone %s renders all three redundant signals', (tone) => {
        const w = mountAlert({ tone });
        expect(w.find('.sr-only').text()).not.toBe('');
        expect(w.find('path').attributes('d')).toBeTruthy();
        const c = w.classes();
        expect(has(c, /^rail-/) && has(c, /^bg-tint-/) && has(c, /^text-on-/)).toBe(true);
    });

    // An unknown tone must degrade UP to the critical treatment, never to an unmarked grey box.
    // Vue strips prop validators from production builds, so the validator alone cannot protect this.
    it('degrades an unknown tone to the critical treatment', () => {
        // 'constructor' is the `in`-operator trap: `'constructor' in ICON` is true, hasOwn is false.
        for (const tone of ['success', 'criticl', 'constructor']) {
            const w = mountAlert({ tone });
            expect(w.find('.sr-only').text()).toBe('Action needed:');
            expect(w.find('path').attributes('d')).toBeTruthy();
            expect(w.classes()).toEqual(expect.arrayContaining(['rail-danger', 'bg-tint-danger', 'text-on-danger']));
            expect(w.attributes('role')).toBe('status');
        }
    });

    it('applies the status-rail plus the matching tone rail class', () => {
        expect(mountAlert({ tone: 'info' }).classes()).toContain('status-rail');
        expect(mountAlert({ tone: 'info' }).classes()).toContain('rail-info');
        expect(mountAlert({ tone: 'warning' }).classes()).toContain('rail-warning');
        expect(mountAlert({ tone: 'critical' }).classes()).toContain('rail-danger');
    });

    it('uses the AA-safe on-* text token, never the raw status-500 colour', () => {
        expect(mountAlert({ tone: 'warning' }).classes()).toContain('text-on-warning');
        expect(mountAlert({ tone: 'warning' }).classes()).toContain('bg-tint-warning');
        expect(mountAlert({ tone: 'warning' }).classes()).not.toContain('text-warning-500');
    });

    // critical is a POLITE live region (role=status): it queues behind the page-title/heading
    // announcement on Inertia navigation instead of interrupting it. Calmer tones are inert notes.
    it('makes only the critical tone a polite live region (role=status); calmer tones are role=note', () => {
        expect(mountAlert({ tone: 'critical' }).attributes('role')).toBe('status');
        expect(mountAlert({ tone: 'warning' }).attributes('role')).toBe('note');
        expect(mountAlert({ tone: 'info' }).attributes('role')).toBe('note');
    });

    it('defaults to the info tone', () => {
        expect(mountAlert().classes()).toContain('rail-info');
    });
});
