import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { useChartTheme } from '@/composables/useChartTheme';

// Mount a throwaway component so the composable's onMounted/onUnmounted run in a real
// component instance (the hooks are required for the event listener + initial read).
const mountWith = () => {
    let api;
    const wrapper = mount({
        setup() { api = useChartTheme(); return () => null; },
    });
    return { wrapper, api };
};

describe('useChartTheme', () => {
    let original;
    beforeEach(() => {
        original = window.getComputedStyle;
        // Return known CSS-var values so we can pin the resolved colors.
        window.getComputedStyle = vi.fn(() => ({
            getPropertyValue: (name) => ({
                '--chart-grid': '#111111',
                '--chart-axis': '#222222',
                '--chart-stroke': '#333333',
                '--ink-900': '#444444',
            }[name.trim()] ?? ''),
        }));
    });
    afterEach(() => { window.getComputedStyle = original; });

    it('reads the CSS-var values on mount', () => {
        const { api } = mountWith();
        expect(api.gridColor.value).toBe('#111111');
        expect(api.axisColor.value).toBe('#222222');
        expect(api.strokeColor.value).toBe('#333333');
        expect(api.inkColor.value).toBe('#444444');
    });

    it('refreshes when the dmc-theme-change event fires', async () => {
        const { api } = mountWith();
        expect(api.gridColor.value).toBe('#111111');

        // Flip the mocked CSS vars and dispatch the theme-change event.
        window.getComputedStyle = vi.fn(() => ({
            getPropertyValue: (name) => ({
                '--chart-grid': '#aaaaaa',
                '--chart-axis': '#bbbbbb',
                '--chart-stroke': '#cccccc',
                '--ink-900': '#dddddd',
            }[name.trim()] ?? ''),
        }));
        document.dispatchEvent(new CustomEvent('dmc-theme-change'));

        expect(api.gridColor.value).toBe('#aaaaaa');
        expect(api.axisColor.value).toBe('#bbbbbb');
        expect(api.strokeColor.value).toBe('#cccccc');
        expect(api.inkColor.value).toBe('#dddddd');
    });

    it('keeps the previous value when a CSS var resolves to empty', () => {
        const { api } = mountWith();
        // Now return empty strings — the composable must NOT clobber with ''.
        window.getComputedStyle = vi.fn(() => ({ getPropertyValue: () => '' }));
        document.dispatchEvent(new CustomEvent('dmc-theme-change'));
        expect(api.gridColor.value).toBe('#111111');
    });
});
