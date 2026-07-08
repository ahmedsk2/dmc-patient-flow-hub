import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ref } from 'vue';
import { mount } from '@vue/test-utils';
import { useReducedMotion, chartAnimations } from '@/composables/useReducedMotion';

// jsdom does NOT implement matchMedia, so we install a controllable stub and keep a handle on the
// registered `change` listener to fire it ourselves (mirrors the getComputedStyle stub in
// useChartTheme.test.js).
const makeMql = (matches) => {
    const listeners = new Set();
    const mql = {
        matches,
        addEventListener: (_e, cb) => listeners.add(cb),
        removeEventListener: (_e, cb) => listeners.delete(cb),
        // test helper: flip the query result and notify listeners the way the OS would
        _emit(next) { mql.matches = next; listeners.forEach((cb) => cb({ matches: next })); },
    };
    return mql;
};

// Mount a throwaway component so the composable's onMounted/onUnmounted run in a real instance
// (the change listener is wired in onMounted).
const mountWith = () => {
    let api;
    const wrapper = mount({ setup() { api = useReducedMotion(); return () => null; } });
    return { wrapper, api };
};

describe('useReducedMotion', () => {
    let original;
    beforeEach(() => { original = window.matchMedia; });
    afterEach(() => { window.matchMedia = original; });

    it('queries the prefers-reduced-motion media feature', () => {
        window.matchMedia = vi.fn(() => makeMql(false));
        mountWith();
        expect(window.matchMedia).toHaveBeenCalledWith('(prefers-reduced-motion: reduce)');
    });

    it('reflects the initial query result', () => {
        window.matchMedia = vi.fn(() => makeMql(true));
        expect(mountWith().api.reduced.value).toBe(true);
    });

    it('updates reactively when the media query changes', () => {
        const mql = makeMql(false);
        window.matchMedia = vi.fn(() => mql);
        const { api } = mountWith();
        expect(api.reduced.value).toBe(false);
        mql._emit(true);
        expect(api.reduced.value).toBe(true);
        mql._emit(false);
        expect(api.reduced.value).toBe(false);
    });

    it('is SSR/undefined-matchMedia safe: reports motion allowed and does not throw', () => {
        window.matchMedia = undefined;
        expect(mountWith().api.reduced.value).toBe(false);
    });
});

describe('chartAnimations', () => {
    // The whole point: ApexCharts animates its draw by default — reduced motion must turn it OFF.
    it('disables the ApexCharts draw animation when reduced', () => {
        expect(chartAnimations(true)).toEqual({ enabled: false });
    });

    it('enables the branded easeinout/600 animation when motion is allowed', () => {
        expect(chartAnimations(false)).toEqual({ enabled: true, easing: 'easeinout', speed: 600 });
    });

    // Reads as chartAnimations(reduced) with the composable's ref passed straight through.
    it('unwraps a ref so it toggles enabled either way', () => {
        expect(chartAnimations(ref(true)).enabled).toBe(false);
        expect(chartAnimations(ref(false)).enabled).toBe(true);
    });
});
