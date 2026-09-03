/**
 * Chart.js foundation (MIT) — the single place the app registers Chart.js and the only chart
 * engine in the bundle. Replaces ApexCharts/vue3-apexcharts (removed 2026-09-03: the owner asked
 * for an unambiguously MIT-licensed chart library; Chart.js and its one transitive dep @kurkle/color
 * are both MIT, with no revenue clause).
 *
 * Tree-shaken registration: we register exactly the controllers/elements/scales/plugins the three
 * dashboards use (bar, line+area-fill, doughnut) and nothing else, so the vendor chunk stays small.
 *
 * datalabels is registered but OFF by default (Chart.defaults) — only the donuts opt back in
 * (per-chart `options.plugins.datalabels.display = true`). Without this every bar would print a
 * number on each column, which the design deliberately does not do (ChartFigure carries the exact
 * numbers for AT instead).
 */
import {
    Chart,
    BarController, BarElement,
    LineController, LineElement, PointElement,
    DoughnutController, ArcElement,
    CategoryScale, LinearScale,
    Filler, Tooltip, Legend,
} from 'chart.js';
import ChartDataLabels from 'chartjs-plugin-datalabels';

Chart.register(
    BarController, BarElement,
    LineController, LineElement, PointElement,
    DoughnutController, ArcElement,
    CategoryScale, LinearScale,
    Filler, Tooltip, Legend,
    ChartDataLabels,
);

// datalabels OFF everywhere unless a chart turns it on. (Bars/area show no in-chart numbers.)
Chart.defaults.plugins.datalabels = { ...(Chart.defaults.plugins.datalabels || {}), display: false };
// Inherit the card's font, not Chart.js's Helvetica default.
Chart.defaults.font.family = 'inherit';
// We drive our own draw animation flag through chartAnimations(reduced); leave the default as-is.

/**
 * A vertical linear gradient for an area fill, from `from` at the top to `to` at the bottom — the
 * Chart.js equivalent of ApexCharts' `fill.gradient`. Use as a SCRIPTABLE dataset backgroundColor:
 *
 *   backgroundColor: (c) => areaGradient(c, 'rgba(0,156,166,.35)', 'rgba(0,156,166,.02)')
 *
 * Returns the flat `from` colour until the chart area exists (first frame), then the gradient.
 */
export function areaGradient(context, from, to) {
    const { chart } = context;
    const { ctx, chartArea } = chart;
    if (!chartArea) return from;
    const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    g.addColorStop(0, from);
    g.addColorStop(1, to);
    return g;
}

/**
 * An inline plugin that prints a two-line total in the hole of a doughnut (the ApexCharts
 * `donut.labels.total` equivalent). Pass the already-resolved centre value + label; colours come
 * from the theme via the caller. Register PER-CHART (ChartCanvas `plugins` prop), not globally, so
 * only the census donut shows it.
 */
export function centerTotalPlugin({ value, label, valueColor = '#1e2a2e', labelColor = '#94a3b5' }) {
    return {
        id: 'centerTotal',
        afterDraw(chart) {
            const { ctx, chartArea: { left, right, top, bottom } } = chart;
            const x = (left + right) / 2;
            const y = (top + bottom) / 2;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = valueColor;
            ctx.font = '700 22px inherit, system-ui, sans-serif';
            ctx.fillText(String(value), x, y - 8);
            ctx.fillStyle = labelColor;
            ctx.font = '600 12px inherit, system-ui, sans-serif';
            ctx.fillText(String(label), x, y + 12);
            ctx.restore();
        },
    };
}

/**
 * A NON-reactive deep copy for handing Vue-owned data/options to Chart.js. Chart.js mutates its
 * config internally, so a reactive proxy would cause redraw loops — but a naive
 * JSON.parse(JSON.stringify()) silently DROPS function-valued config (scriptable colours, datalabels
 * formatters, tooltip callbacks, onClick). detachReactive deep-copies plain objects/arrays (which
 * strips the proxy) while passing functions and non-plain objects through BY REFERENCE, so every
 * scriptable and callback survives. See ChartCanvas.vue.
 */
export function detachReactive(v) {
    if (Array.isArray(v)) return v.map(detachReactive);
    if (v && typeof v === 'object') {
        const proto = Object.getPrototypeOf(v);
        if (proto === Object.prototype || proto === null) {
            const out = {};
            for (const k of Object.keys(v)) out[k] = detachReactive(v[k]);
            return out;
        }
        return v; // Date, CanvasGradient, class instances, …
    }
    return v; // primitives AND functions pass through untouched
}

export { Chart };
