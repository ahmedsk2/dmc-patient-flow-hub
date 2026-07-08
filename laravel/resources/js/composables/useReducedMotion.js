import { ref, onMounted, onUnmounted, unref } from 'vue';

// prefers-reduced-motion, reactive. The global CSS block in app.css already neutralizes CSS
// transitions/animations for reduced-motion users, but ApexCharts runs its OWN JS-driven draw
// animation (enabled by default) that CSS can't reach — so a chart still animates in on load unless
// its `animations` option is turned off. This composable exposes that flag; chartAnimations() maps
// it to the option object the charts feed ApexCharts.
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

// Maps the reduced-motion flag to an ApexCharts `animations` option. Accepts the ref or a plain
// boolean (unref handles both) so it reads as `chartAnimations(reduced)` inside an option computed.
// The enabled branch mirrors the easeinout/600 the dashboard charts already used.
export function chartAnimations(reduced) {
    return unref(reduced)
        ? { enabled: false }
        : { enabled: true, easing: 'easeinout', speed: 600 };
}
