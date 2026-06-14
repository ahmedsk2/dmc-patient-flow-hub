import { ref, onMounted, onUnmounted } from 'vue';

// Theme-aware ApexCharts colors. Charts hard-coded light-only grid/axis/stroke hex values
// that vanished (white-on-white) in dark mode. These refs read the CSS theme tokens
// (--chart-grid / --chart-axis / --chart-stroke) and refresh whenever the .dark class flips
// — the AppLayout toggle dispatches a `dmc-theme-change` event on document.
//
// Usage:  const { gridColor, axisColor, strokeColor } = useChartTheme();
//         grid: { borderColor: gridColor.value }, ...
// (call inside `computed(() => ({...}))` chart-option getters so they stay reactive).
export function useChartTheme() {
    const gridColor = ref('#eef2f6');
    const axisColor = ref('#94a3b5');
    const strokeColor = ref('#ffffff');
    const inkColor = ref('#1e2a2e');   // strong text (e.g. gauge centre value) — resolved, not a var

    const read = () => {
        if (typeof window === 'undefined') return;
        const cs = getComputedStyle(document.documentElement);
        const g = cs.getPropertyValue('--chart-grid').trim();
        const a = cs.getPropertyValue('--chart-axis').trim();
        const s = cs.getPropertyValue('--chart-stroke').trim();
        const i = cs.getPropertyValue('--ink-900').trim();
        if (g) gridColor.value = g;
        if (a) axisColor.value = a;
        if (s) strokeColor.value = s;
        if (i) inkColor.value = i;
    };

    onMounted(() => {
        read();
        document.addEventListener('dmc-theme-change', read);
    });
    onUnmounted(() => document.removeEventListener('dmc-theme-change', read));

    return { gridColor, axisColor, strokeColor, inkColor };
}
