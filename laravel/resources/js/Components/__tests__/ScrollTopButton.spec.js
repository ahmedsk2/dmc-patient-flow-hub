import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ScrollTopButton from '@/Components/ScrollTopButton.vue';

// Render the Transition's default slot synchronously so the v-if presence is what we assert on.
const mountBtn = () => mount(ScrollTopButton, { global: { stubs: { transition: true } } });
const setScroll = (y) => { window.scrollY = y; window.dispatchEvent(new Event('scroll')); };

describe('ScrollTopButton', () => {
    beforeEach(() => {
        Object.defineProperty(window, 'scrollY', { configurable: true, writable: true, value: 0 });
        window.scrollTo = vi.fn();
        window.matchMedia = vi.fn().mockReturnValue({ matches: false });
    });

    it('is hidden at the top of the page', () => {
        const w = mountBtn();
        expect(w.find('button').exists()).toBe(false);
    });

    it('appears once the reader scrolls past the threshold', async () => {
        const w = mountBtn();
        setScroll(500);
        await flushPromises();
        expect(w.find('button').exists()).toBe(true);
        expect(w.get('button').attributes('aria-label')).toBe('Back to top');
    });

    // Regression guard (adversarial review): the FAB must NOT sit in the flash toast's bottom-6 corner
    // (toast is z-50 and would bury it for ~4.5s on every mutating action), and must stack BELOW the
    // mobile-drawer backdrop (z-30) — so it lifts to bottom-24 and drops to z-20.
    it('is lifted clear of the toast corner and stacks below transient overlays', async () => {
        const w = mountBtn();
        setScroll(500);
        await flushPromises();
        const cls = w.get('button').classes();
        expect(cls).toContain('bottom-24');
        expect(cls).toContain('z-20');
        expect(cls).not.toContain('bottom-6');
        expect(cls).not.toContain('z-30');
    });

    it('hides again when scrolled back near the top', async () => {
        const w = mountBtn();
        setScroll(500);
        await flushPromises();
        expect(w.find('button').exists()).toBe(true);
        setScroll(0);
        await flushPromises();
        expect(w.find('button').exists()).toBe(false);
    });

    it('scrolls smoothly to the top on click', async () => {
        const w = mountBtn();
        setScroll(500);
        await flushPromises();
        await w.get('button').trigger('click');
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('respects prefers-reduced-motion (jumps, no smooth animation)', async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true });
        const w = mountBtn();
        setScroll(500);
        await flushPromises();
        await w.get('button').trigger('click');
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });
});
