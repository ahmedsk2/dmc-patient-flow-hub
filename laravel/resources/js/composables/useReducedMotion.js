import { ref, onMounted, onUnmounted, unref } from 'vue';

// prefers-reduced-motion, reactive. The global CSS block in app.css already neutralizes CSS
// transitions/animations for reduced-motion users, but Chart.js runs its OWN JS-driven draw
// animation (enabled by default) that CSS can't reach — so a chart still animates in on load unless
// its `animation` option is turned off. This composable exposes that flag; chartAnimations() maps
// it to the value a Chart.js `options.animation` expects.
//
// SSR-safe: with no window/matchMedia (server render, or jsdom without the stub) we report
// `reduced = false` (motion allowed) and wire no listener, so importing never throws.
const QUERY = '(prefers-reduced-motion: reduce)';

export function useReducedMotion() {
    const mql = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        ? window.matchMedia(QUERY) : null;
    const reduced = ref(mql?.matches ?? false);

    const onChange = (e) => { reduced.value = e.matches; };
    onMounted(() => mql?.addEventListener('change', onChange));
    onUnmounted(() => mql?.removeEventListener('change', onChange));

    return { reduced };
}

// Maps the reduced-motion flag to a Chart.js `options.animation` value. Accepts the ref or a
// plain boolean (unref handles both) so it reads as `animation: chartAnimations(reduced)` inside an
// option computed. Reduced → `false` (Chart.js draws instantly); otherwise a 600ms ease that
// mirrors the easeinout/600 the dashboards used under ApexCharts.
export function chartAnimations(reduced) {
    return unref(reduced)
        ? false
        : { duration: 600, easing: 'easeInOutQuart' };
}
