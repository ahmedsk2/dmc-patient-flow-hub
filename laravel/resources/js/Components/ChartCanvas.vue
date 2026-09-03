<script setup>
/**
 * ChartCanvas — the app's Chart.js (MIT) renderer, the single replacement for the old global
 * <apexchart> (vue3-apexcharts, removed 2026-09-03). Renders ONE <canvas role="img"> and owns a
 * Chart.js instance's whole lifecycle: create on mount, update in place when `data`/`options`
 * change (theme flips and reduced-motion both arrive this way, because the caller builds `data`
 * and `options` from reactive composables), recreate on a `type` change, destroy on unmount.
 *
 * ACCESSIBILITY. A canvas is opaque to a screen reader, so — exactly as the ApexCharts version did
 * — every caller wraps this in <ChartFigure> (visually-hidden caption + data table) AND passes
 * role="img" + aria-label, which fall through to the <canvas> (inheritAttrs). role="img" makes the
 * pixels presentational; the label + the ChartFigure table carry the meaning.
 *
 * TEST/SSR SAFETY. In jsdom a <canvas> has no 2D context (getContext returns null), so we simply
 * never instantiate Chart.js there — the bare labelled <canvas> renders and nothing throws. That
 * means the page tests need no chart stub (though the existing ones still work), and axe still sees
 * a role="img" element. Same guard covers server render.
 */
import { onMounted, onBeforeUnmount, watch, shallowRef, nextTick } from 'vue';
import { Chart, detachReactive } from '@/lib/chartjs';

defineOptions({ inheritAttrs: true });

const props = defineProps({
    // Chart.js chart type: 'bar' | 'line' | 'doughnut' (area = 'line' with a filled dataset).
    type: { type: String, required: true },
    // Chart.js data object: { labels: [...], datasets: [...] }.
    data: { type: Object, required: true },
    // Chart.js options object (scales, plugins, animation, …). Optional.
    options: { type: Object, default: () => ({}) },
    // Per-chart inline plugins (e.g. centerTotalPlugin for the census donut). Optional.
    plugins: { type: Array, default: () => [] },
    // Fixed pixel height; the width is fluid (the canvas fills its column).
    height: { type: [Number, String], default: 260 },
});

const canvas = shallowRef(null);
let chart = null;

function build() {
    const el = canvas.value;
    if (!el) return;
    const ctx = typeof el.getContext === 'function' ? el.getContext('2d') : null;
    if (!ctx) return; // jsdom / SSR — no 2D context; render the bare labelled canvas only.
    chart = new Chart(ctx, {
        type: props.type,
        data: detachReactive(props.data),
        options: detachReactive(props.options),
        plugins: props.plugins,
    });
}

function refresh() {
    if (!chart) { build(); return; }
    if (chart.config.type !== props.type) { chart.destroy(); chart = null; build(); return; }
    chart.data = detachReactive(props.data);
    chart.options = detachReactive(props.options);
    chart.update();
}

onMounted(build);
onBeforeUnmount(() => { if (chart) { chart.destroy(); chart = null; } });
watch(() => [props.type, props.data, props.options], () => nextTick(refresh), { deep: true });
</script>

<template>
    <div class="relative w-full" :style="{ height: typeof height === 'number' ? height + 'px' : height }">
        <canvas ref="canvas"></canvas>
    </div>
</template>
