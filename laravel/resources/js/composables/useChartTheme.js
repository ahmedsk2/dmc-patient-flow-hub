import { ref, onMounted, onUnmounted } from 'vue';

// Theme-aware chart colors (engine-agnostic hex/token values; consumed by Chart.js options). Charts hard-coded light-only grid/axis/stroke hex values
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

    // Canonical, theme-reactive chart SERIES palette (W4). Reads --chart-* tokens so the deep/muted
    // hues lighten on dark instead of vanishing into the card. Charts must use THIS, never local hex.
    // The object is REPLACED (not mutated) on each read so `series.value.X` inside option computeds
    // re-evaluates on theme change.
    const SERIES_TOKENS = {
        primary: '--chart-primary', accent: '--chart-accent', deep: '--chart-deep',
        info: '--chart-info', muted: '--chart-muted', primarySoft: '--chart-primary-soft',
    };
    const series = ref({ primary: '#009ca6', accent: '#d9a23c', deep: '#00565e', info: '#2f7fe0', muted: '#5b6a6e', primarySoft: '#38b4ba' });

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
        const next = { ...series.value };
        let changed = false;
        for (const [k, tok] of Object.entries(SERIES_TOKENS)) {
            const val = cs.getPropertyValue(tok).trim();
            if (val && val !== next[k]) { next[k] = val; changed = true; }
        }
        if (changed) series.value = next;
    };

    onMounted(() => {
        read();
        document.addEventListener('dmc-theme-change', read);
    });
    onUnmounted(() => document.removeEventListener('dmc-theme-change', read));

    return { gridColor, axisColor, strokeColor, inkColor, series };
}
