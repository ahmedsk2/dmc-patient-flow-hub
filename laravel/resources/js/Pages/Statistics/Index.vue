<script setup>
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ChartCanvas from '@/Components/ChartCanvas.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { useReducedMotion, chartAnimations } from '@/composables/useReducedMotion';
import { areaGradient } from '@/lib/chartjs';
import { barOptions, groupedBarData, barData, doughnutOptions, doughnutData, areaOptions, areaData } from '@/lib/chartConfig';

// theme-aware grid/axis colors + the canonical theme-reactive chart SERIES palette (legible in light + dark)
const { gridColor, axisColor, strokeColor, series } = useChartTheme();
const { reduced } = useReducedMotion();
const anim = computed(() => chartAnimations(reduced));

// area-fill gradients need an rgba(from,to) pair per line colour — the theme tokens come back as
// hex (or a CSS colour keyword) from getComputedStyle, so this turns one into a translucent pair
// the same way ApexCharts' single opacityFrom/opacityTo used to apply automatically.
const fillFrom = (hex) => hexToRgba(hex, 0.25);
const fillTo = (hex) => hexToRgba(hex, 0.02);
function hexToRgba(hex, alpha) {
    const h = (hex || '').trim().replace('#', '');
    if (!/^[0-9a-f]{6}$/i.test(h)) return hex; // not a hex colour — fall back, no opacity control
    const r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
// donut slice % labels (ApexCharts' default `dataLabels.enabled: true` on a donut shows share of total)
const donutDatalabel = computed(() => ({
    color: strokeColor.value,
    formatter: (v, ctx) => {
        const total = ctx.chart.data.datasets[0].data.reduce((a, b) => a + (b || 0), 0);
        return total ? Math.round(v / total * 100) + '%' : '0%';
    },
}));

const props = defineProps({ range: Object, kpis: Object, monthly: Object, los: Object, topDx: Array, reasons: Object, perConsultant: Array, sourceMix: Array, kpiGrid: Array, interval: String, truncated: Boolean, destinations: Object, destByConsultant: Array, readmitWindow: Number, consultants: Array, physician: Object, compareData: Object });

const from = ref(props.range.from);
const to = ref(props.range.to);
const interval = ref(props.interval || 'month');
// per-physician drill-down selection (server-computed prop; selection round-trips via the URL)
const physChoice = ref(props.physician?.id ?? '');
// §3.5: year-over-year / prior-period comparison overlay ('' = off)
const compareMode = ref(props.compareData ? (props.compareData.range.from.slice(0, 4) !== from.value.slice(0, 4) ? 'prior_year' : 'prior_period') : '');
const apply = () => router.get('/statistics', {
    from: from.value, to: to.value, interval: interval.value,
    ...(physChoice.value ? { consultant_id: physChoice.value } : {}),
    ...(compareMode.value ? { compare: compareMode.value } : {}),
}, { preserveState: true, preserveScroll: true });
const setInterval2 = (i) => { interval.value = i; apply(); };
const print = () => window.print();

// §3.4: export the currently-filtered statistics (XLSX / PDF) — same filters, no re-entry
const exportHref = computed(() => '/statistics/export?' + new URLSearchParams(
    Object.entries({ from: from.value, to: to.value, interval: interval.value,
        ...(physChoice.value ? { consultant_id: physChoice.value } : {}) })
        .filter(([, v]) => v !== '' && v != null)).toString());
const exportPdfHref = computed(() => exportHref.value.replace('/export?', '/export/pdf?'));

// per-consultant discharge-destination donut (All = overall)
const destChoice = ref('');
const destDonut = computed(() => {
    if (!destChoice.value) return { labels: props.destinations.labels, data: props.destinations.data };
    const c = props.destByConsultant.find((x) => x.name === destChoice.value);
    return c ? { labels: c.labels, data: c.data } : { labels: [], data: [] };
});
// a new range can drop the selected consultant from the list — fall back to All instead of
// showing a misleading empty donut for a stale choice
watch(() => props.destByConsultant, (list) => {
    if (destChoice.value && !(list || []).some((x) => x.name === destChoice.value)) destChoice.value = '';
});

// W4: chart series colours come from the theme-reactive token palette (useChartTheme → `series`),
// never local hex — so the deep/muted hues lighten on dark instead of vanishing into the card.
// Mapping: teal→primary, gold→accent, navy→deep, blue→info, slate→muted, tealLight→primarySoft.
// The series palette has no danger token (status red is forbidden on charts by default — spec §2).
// Mortality is drawn in `muted` grey: distinct from the teal `primary` admissions line, calm, and
// somber-appropriate; the readmit single-bar keeps `deep`. Meaning is carried by the labelled series,
// not colour.

const kpiCards = computed(() => [
    { label: 'Admissions', value: props.kpis.admissions, tone: 'brand' },
    { label: 'Discharges', value: props.kpis.discharges, tone: 'info' },
    { label: 'ICU admissions', value: props.kpis.icuAdmissions, tone: 'danger' },
    { label: 'Mortality', value: props.kpis.deaths, sub: props.kpis.mortalityRate + '%', tone: 'ink' },
    { label: 'Avg LOS', value: props.kpis.avgLos, sub: 'days', tone: 'accent' },
    { label: 'Consultations', value: props.kpis.consultations, tone: 'brand' },
    { label: 'Sign-offs', value: props.kpis.signoffs, tone: 'info' },
    { label: `≤${props.readmitWindow ?? 3}d readmits`, value: props.kpis.readmissions, tone: 'danger' },
]);
const toneClass = (t) => ({
    brand: 'text-brand-700', info: 'text-info-500', danger: 'text-on-danger', accent: 'text-on-accent', ink: 'text-ink-700',
}[t]);

// computed (not plain consts): Apply/interval re-fetches with preserveState, so props change
// in place — every prop-derived chart input must be reactive or the chart keeps stale data
//
// day interval: Fri/Sat ticks (Saudi weekend — D4) tinted amber from the raw bucket keys
const tickColors = computed(() => props.interval !== 'day'
    ? axisColor.value
    : (props.monthly.keys || []).map((k) => {
        const dow = new Date(`${k}T00:00:00Z`).getUTCDay();   // 5 = Fri, 6 = Sat
        return dow === 5 || dow === 6 ? series.value.accent : axisColor.value;
    }));
// §3.5: when a comparison is active, append 3 dashed "ghost" series (admissions/discharges/deaths
// of the comparison period) drawn at reduced opacity on top of the primary 5-series area chart
const cyr = computed(() => props.compareData?.range?.from?.slice(0, 4) ?? '');
// base 5-series definitions + (when a comparison is active) 3 dashed "ghost" overlays of the
// comparison period, drawn thinner and at reduced opacity on top of the primary series
const monthlySeriesDefs = computed(() => {
    const base = [
        { name: 'Admissions', data: props.monthly.admissions, color: series.value.primary },
        { name: 'Discharges', data: props.monthly.discharges, color: series.value.info },
        { name: 'Mortality', data: props.monthly.deaths, color: series.value.muted },
        // extra context series — toggle off via the legend if noisy
        { name: 'Consultations', data: props.monthly.consultations, color: series.value.accent },
        { name: 'Sign-offs', data: props.monthly.signoffs, color: series.value.deep },
    ];
    if (!props.compareData) return base;
    return [...base,
        { name: `Admissions (${cyr.value})`, data: props.compareData.admissions, color: series.value.primary, ghost: true },
        { name: `Discharges (${cyr.value})`, data: props.compareData.discharges, color: series.value.info, ghost: true },
        { name: `Deaths (${cyr.value})`, data: props.compareData.deaths, color: series.value.muted, ghost: true },
    ];
});
const monthlyOptions = computed(() => {
    const o = areaOptions({ gridColor: gridColor.value, axisColor: axisColor.value, animation: anim.value });
    // area charts hide the legend by default (the caller usually direct-labels via ChartFigure),
    // but this 5-8 series overlay has no table alongside it, so the legend IS the only key
    o.plugins.legend = { display: true, position: 'top', align: 'end', labels: { color: axisColor.value, boxWidth: 12, boxHeight: 12, font: { weight: '600' } } };
    o.scales.x.ticks.color = tickColors.value; // Fri/Sat amber ticks on the day interval
    return o;
});
const monthlyData = computed(() => {
    const defs = monthlySeriesDefs.value;
    const out = areaData(defs.map((s) => ({
        name: s.name, data: s.data, border: s.color,
        background: (c) => areaGradient(c, fillFrom(s.color), fillTo(s.color)),
    })));
    out.datasets.forEach((ds, i) => {
        if (defs[i].ghost) { ds.borderWidth = 2; ds.borderDash = [5, 5]; }
    });
    return { labels: props.monthly.labels, ...out };
});

// §3.5: delta chips (current minus comparison) below the headline KPIs when comparison is active
const deltaCards = computed(() => {
    if (!props.compareData) return [];
    const c = props.compareData.kpis;
    const r1 = (v) => Math.round(v * 10) / 10;
    return [
        { label: 'Admissions', delta: props.kpis.admissions - c.admissions },
        { label: 'Discharges', delta: props.kpis.discharges - c.discharges },
        { label: 'Deaths', delta: props.kpis.deaths - c.deaths },
        { label: 'Avg LOS', delta: r1(props.kpis.avgLos - c.avgLos) },
        { label: 'Mortality %', delta: r1(props.kpis.mortalityRate - c.mortalityRate) },
    ];
});

// KPI-grid header tracks the selected interval + actual applied range
const gridTitle = computed(() => ({ day: 'Daily', quarter: 'Quarterly' }[props.interval] ?? 'Monthly') + ' KPI grid');

// per-consultant chart KPI modes (Admissions | Avg LOS | window readmissions | 4-series Activity)
const consMode = ref('admissions');
const consModes = computed(() => [
    ['admissions', 'Admissions'],
    ['avgLos', 'Avg LOS'],
    ['readmits', `${props.readmitWindow ?? 3}d readmits`],
    ['activity', 'Activity'],
]);
const consModeColor = computed(() => ({ admissions: series.value.primarySoft, avgLos: series.value.accent, readmits: series.value.deep }[consMode.value]));
const consCats = computed(() => props.perConsultant.map((c) => c.name));
// the canonical 4-series activity palette + names — shared by the by-consultant Activity mode
// and the physician drill-down time-series so the two read identically
// computed (not a plain const) so the series colours re-theme on the dark-mode flip
const activityDefs = computed(() => [['admissions', 'Admissions', series.value.primary], ['discharges', 'Discharges', series.value.info], ['consultations', 'Consultations', series.value.accent], ['signoffs', 'Sign-offs', series.value.muted]]);
const consData = computed(() => {
    const labels = consCats.value;
    if (consMode.value === 'activity') {
        return { labels, ...groupedBarData(activityDefs.value.map(([key, name]) => ({ name, data: props.perConsultant.map((c) => c[key]) })), activityDefs.value.map((d) => d[2])) };
    }
    const name = consModes.value.find((m) => m[0] === consMode.value)?.[1] ?? '';
    return { labels, ...barData(name, props.perConsultant.map((c) => c[consMode.value]), consModeColor.value) };
});
const consOptions = computed(() => barOptions({
    gridColor: gridColor.value, axisColor: axisColor.value, animation: anim.value, horizontal: true,
    legend: consMode.value === 'activity' ? { position: 'top', align: 'end' } : false,
}));
// the list is no longer capped at 10 — grow the chart with the roster so bars stay readable
// (activity mode draws 4 grouped bars per consultant, so it needs more vertical room)
const consHeight = computed(() => consMode.value === 'activity'
    ? Math.max(360, props.perConsultant.length * 72 + 80)
    : Math.max(340, props.perConsultant.length * 28 + 60));

// drill-down (computed, like every prop-derived chart input — see note above)
const donutColors = computed(() => [series.value.primary, series.value.accent, series.value.info, series.value.deep, series.value.primarySoft, series.value.muted]);
const donutOpts = computed(() => doughnutOptions({ axisColor: axisColor.value, animation: anim.value, datalabel: donutDatalabel.value }));
const physDonutData = computed(() => ({ labels: props.physician?.destinations?.labels ?? [], ...doughnutData(props.physician?.destinations?.data ?? [], donutColors.value, strokeColor.value) }));
const physHasDischarges = computed(() => (props.physician?.destinations?.data ?? []).some((v) => v > 0));
// second donut (J2-4): legacy 'Discharged to' 6 buckets over the consultant's non-ICU closed episodes
const physDestData = computed(() => ({ labels: props.physician?.dischargedTo?.labels ?? [], ...doughnutData(props.physician?.dischargedTo?.data ?? [], donutColors.value, strokeColor.value) }));
const physHasDest = computed(() => (props.physician?.dischargedTo?.data ?? []).some((v) => v > 0));
const physNumbers = computed(() => props.physician ? [
    ['Admissions', props.physician.numbers.admissions],
    ['Discharges', props.physician.numbers.discharges],
    ['→ ICU', props.physician.numbers.transToIcu],
    ['Deaths', props.physician.numbers.deaths],
    ['Avg LOS', props.physician.numbers.avgLos + 'd'],
    [`≤${props.readmitWindow ?? 3}d readmits`, props.physician.numbers.readmissions],
    ['Consultations', props.physician.numbers.consultations],
    ['Sign-offs', props.physician.numbers.signoffs],
] : []);
// bucketed 4-series activity for the selected physician (interval-aligned with the page)
const physData = computed(() => ({
    labels: props.physician?.series?.labels ?? [],
    ...groupedBarData(activityDefs.value.map(([key, name]) => ({ name, data: props.physician?.series?.[key] ?? [] })), activityDefs.value.map((d) => d[2])),
}));
const physSeriesOptions = computed(() => barOptions({ gridColor: gridColor.value, axisColor: axisColor.value, animation: anim.value, legend: { position: 'top', align: 'end' } }));
const physHasActivity = computed(() => physData.value.datasets.some((s) => s.data.some((v) => v > 0)));

// Inline template chart-option calls promoted to computeds so they re-theme on the dark
// flip (a plain template call doesn't re-evaluate when `series` changes) — same fix as Dashboard.
const losData = computed(() => ({ labels: props.los.labels, ...barData('Discharges', props.los.data, series.value.primary) }));
const losOptions = computed(() => barOptions({ gridColor: gridColor.value, axisColor: axisColor.value, animation: anim.value }));
const sourceMixData = computed(() => ({ labels: props.sourceMix.map((s) => s.src), ...doughnutData(props.sourceMix.map((s) => s.c), donutColors.value, strokeColor.value) }));
const sourceMixOptions = donutOpts;
const destDonutData = computed(() => ({ labels: destDonut.value.labels, ...doughnutData(destDonut.value.data, donutColors.value, strokeColor.value) }));
const destDonutOptions = donutOpts;
const topDxData = computed(() => ({ labels: props.topDx.map((d) => d.label), ...barData('Admissions', props.topDx.map((d) => d.value), series.value.deep) }));
const topDxOptions = computed(() => barOptions({ gridColor: gridColor.value, axisColor: axisColor.value, animation: anim.value, horizontal: true }));
const reasonsData = computed(() => ({ labels: props.reasons.labels, ...barData('Consultations', props.reasons.data, series.value.accent) }));
const reasonsOptions = computed(() => barOptions({ gridColor: gridColor.value, axisColor: axisColor.value, animation: anim.value, horizontal: true }));
</script>

<template>
    <AppLayout title="Statistics" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Analytics & Reports' },
        { label: 'Statistics' },
    ]">
        <!-- range -->
        <div class="no-print mb-5 flex flex-wrap items-end gap-3 rounded-2xl bg-card p-4 shadow-card ring-1 ring-line">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">From</label>
                <input v-model="from" type="date" class="rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">To</label>
                <input v-model="to" type="date" class="rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" />
            </div>
            <div class="flex items-end gap-1">
                <div class="flex gap-1 rounded-xl bg-app p-1 ring-1 ring-line">
                    <button v-for="iv in [['day','Daily'],['month','Monthly'],['quarter','Quarterly']]" :key="iv[0]" @click="setInterval2(iv[0])" class="rounded-lg px-3 py-1.5 text-sm font-semibold transition" :class="interval === iv[0] ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">{{ iv[1] }}</button>
                </div>
            </div>
            <!-- §3.5: year-over-year / prior-period comparison overlay -->
            <select v-model="compareMode" @change="apply" aria-label="Comparison period" class="rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500">
                <option value="">No comparison</option>
                <option value="prior_year">vs prior year</option>
                <option value="prior_period">vs prior period</option>
            </select>
            <button @click="apply" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-solid-hover">Apply</button>
            <!-- §3.4: export the currently-filtered statistics -->
            <a :href="exportHref" class="inline-flex items-center gap-2 rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-success-700">Excel</a>
            <a :href="exportPdfHref" class="inline-flex items-center gap-2 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-ink-600 shadow-sm ring-1 ring-line transition hover:bg-ink-50">Export PDF</a>
            <button @click="print" class="inline-flex items-center gap-2 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-ink-500 shadow-sm ring-1 ring-line transition hover:bg-ink-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
                Print
            </button>
            <span class="ml-auto text-sm text-ink-400">{{ range.from }} → {{ range.to }}</span>
        </div>

        <!-- day-interval cap notice -->
        <div v-if="truncated" class="mb-5 flex items-start gap-2 rounded-xl bg-tint-warning px-4 py-3 text-sm font-medium text-on-warning ring-1 ring-warning-500/20" role="alert">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <span>Daily view is capped at ~370 days — the chart and grid stop before {{ range.to }}. Switch to <button @click="setInterval2('month')" class="font-bold underline underline-offset-2 hover:opacity-80">Monthly</button> to cover the whole range.</span>
        </div>

        <!-- KPIs -->
        <div class="mb-2 grid grid-cols-2 gap-4 sm:grid-cols-4 xl:grid-cols-8">
            <div v-for="k in kpiCards" :key="k.label" class="rounded-2xl bg-card p-4 shadow-card ring-1 ring-line">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ k.label }}</div>
                <div class="mt-1 flex items-baseline gap-1">
                    <span class="font-display nums text-2xl font-bold" :class="toneClass(k.tone)">{{ k.value }}</span>
                    <span v-if="k.sub" class="text-xs text-ink-400">{{ k.sub }}</span>
                </div>
            </div>
        </div>
        <!-- §3.5: delta chips vs the comparison period (red = up, brand = down) -->
        <div v-if="compareData" class="mb-6 grid grid-cols-2 gap-2 sm:grid-cols-5">
            <div v-for="k in deltaCards" :key="k.label" class="rounded-xl bg-app p-2 text-center ring-1 ring-line text-sm">
                <div class="text-[10px] uppercase tracking-wide text-ink-400">{{ k.label }} vs {{ compareData.range.from }}–{{ compareData.range.to }}</div>
                <div class="nums font-bold" :class="k.delta > 0 ? 'text-on-danger' : k.delta < 0 ? 'text-brand-600' : 'text-ink-400'">{{ k.delta > 0 ? '+' : '' }}{{ k.delta }}</div>
            </div>
        </div>
        <div v-else class="mb-6"></div>

        <!-- charts -->
        <div class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line lg:col-span-2">
                <h3 class="mb-3 font-bold text-ink-800">{{ interval === 'day' ? 'Daily' : interval === 'quarter' ? 'Quarterly' : 'Monthly' }} admissions, discharges, mortality & consultations <span v-if="interval === 'day'" class="text-xs font-normal text-on-warning">(Fri/Sat ticks in amber)</span></h3>
                <ChartCanvas role="img" aria-label="Statistics chart (data also shown in the period table below)" type="line" :height="300" :data="monthlyData" :options="monthlyOptions" />
            </div>

            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Length of stay distribution</h3>
                <ChartCanvas role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" :height="280" :data="losData" :options="losOptions" />
            </div>
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Admission source</h3>
                <ChartCanvas role="img" aria-label="Statistics chart (data also shown in the period table below)" type="doughnut" :height="280" :data="sourceMixData" :options="sourceMixOptions" />
            </div>
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="font-bold text-ink-800">Discharge destinations</h3>
                    <select v-model="destChoice" class="max-w-[55%] truncate rounded-lg border border-ink-200 px-2 py-1 text-xs outline-none focus:border-brand-500">
                        <option value="">All consultants</option>
                        <option v-for="c in destByConsultant" :key="c.name" :value="c.name">{{ c.name }}</option>
                    </select>
                </div>
                <ChartCanvas role="img" aria-label="Statistics chart (data also shown in the period table below)" v-if="destDonut.data.some((v) => v > 0)" type="doughnut" :height="280" :data="destDonutData" :options="destDonutOptions" />
                <p v-else class="py-10 text-center text-sm text-ink-300">{{ destChoice ? 'No discharges for this consultant in range.' : 'No discharges in range.' }}</p>
            </div>

            <div class="print-break-before rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Top diagnoses</h3>
                <ChartCanvas role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" :height="320" :data="topDxData" :options="topDxOptions" />
            </div>
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-3 font-bold text-ink-800">Consultation indications</h3>
                <ChartCanvas role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" :height="320" :data="reasonsData" :options="reasonsOptions" />
            </div>

            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-bold text-ink-800">By consultant</h3>
                    <div class="flex gap-1 rounded-xl bg-app p-1 ring-1 ring-line">
                        <button v-for="m in consModes" :key="m[0]" @click="consMode = m[0]" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition" :class="consMode === m[0] ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">{{ m[1] }}</button>
                    </div>
                </div>
                <ChartCanvas role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" :height="consHeight" :data="consData" :options="consOptions" />
            </div>

            <!-- per-physician drill-down -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-bold text-ink-800">Physician drill-down</h3>
                    <select v-model="physChoice" @change="apply" aria-label="Drill-down consultant" class="max-w-[55%] truncate rounded-lg border border-ink-200 px-2 py-1.5 text-sm outline-none focus:border-brand-500">
                        <option value="">Select a consultant…</option>
                        <option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div v-if="physician" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div v-for="n in physNumbers" :key="n[0]" class="rounded-xl bg-app p-3 ring-1 ring-line">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-400">{{ n[0] }}</div>
                            <div class="nums mt-0.5 text-xl font-bold text-ink-800">{{ n[1] }}</div>
                        </div>
                    </div>
                    <!-- §3.1: per-consultant scorecard PDF over the current range -->
                    <a :href="`/reports/consultant/${physician.id}/pdf?from=${from}&to=${to}`" class="inline-flex items-center gap-2 rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-solid-hover">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        Download scorecard PDF
                    </a>
                    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-ink-600">Discharge destinations</h4>
                            <ChartCanvas v-if="physHasDischarges" role="img" :aria-label="`Discharge destinations for ${physician.name}`" type="doughnut" :height="260" :data="physDonutData" :options="donutOpts" />
                            <p v-else class="py-10 text-center text-sm text-ink-300">No closed episodes in range.</p>
                        </div>
                        <!-- legacy charts.php 'Discharged to' donut: Home / Other Facility / LAMA /
                             Absconded / Mortuary / Transfer over non-ICU closed episodes (J2-4) -->
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-ink-600">Discharged to</h4>
                            <ChartCanvas v-if="physHasDest" role="img" :aria-label="`Discharged-to destinations for ${physician.name}`" type="doughnut" :height="260" :data="physDestData" :options="donutOpts" />
                            <p v-else class="py-10 text-center text-sm text-ink-300">No non-ICU closed episodes in range.</p>
                        </div>
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-ink-600">Top diagnoses</h4>
                            <ol v-if="physician.topDx.length" class="space-y-1.5">
                                <li v-for="(d, i) in physician.topDx" :key="d.label" class="flex items-center justify-between gap-2 rounded-lg bg-app px-3 py-2 text-sm ring-1 ring-line">
                                    <span class="text-ink-700"><span class="nums mr-2 font-bold text-brand-700">{{ i + 1 }}.</span>{{ d.label }}</span>
                                    <span class="nums font-semibold text-ink-500">{{ d.value }}</span>
                                </li>
                            </ol>
                            <p v-else class="py-10 text-center text-sm text-ink-300">No diagnoses recorded in range.</p>
                        </div>
                    </div>
                    <div>
                        <h4 class="mb-2 text-sm font-semibold text-ink-600">Activity over range <span class="font-normal text-ink-400">({{ interval === 'day' ? 'daily' : interval === 'quarter' ? 'quarterly' : 'monthly' }})</span></h4>
                        <ChartCanvas v-if="physHasActivity" role="img" :aria-label="`Bucketed admissions, discharges, consultations and sign-offs for ${physician.name}`" type="bar" :height="240" :data="physData" :options="physSeriesOptions" />
                        <p v-else class="py-8 text-center text-sm text-ink-300">No activity in range.</p>
                    </div>
                </div>
                <p v-else class="py-8 text-center text-sm text-ink-300">Pick a consultant to see their destinations, top diagnoses and KPIs for this range.</p>
            </div>

            <div class="overflow-hidden rounded-2xl bg-card p-5 shadow-card ring-1 ring-line lg:col-span-2">
                <h3 class="mb-3 font-bold text-ink-800">{{ gridTitle }} <span class="nums text-sm font-normal text-ink-400">({{ range.from }} – {{ range.to }})</span></h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                            <th scope="col" class="px-3 py-2">Period</th><th scope="col" class="px-3 py-2 text-right">Adm</th><th scope="col" class="px-3 py-2 text-right">Disch</th><th scope="col" class="px-3 py-2 text-right">ICU adm</th><th scope="col" class="px-3 py-2 text-right">→ICU</th><th scope="col" class="px-3 py-2 text-right">ICU mort</th><th scope="col" class="px-3 py-2 text-right">Ward mort</th><th scope="col" class="px-3 py-2 text-right">Readm</th><th scope="col" class="px-3 py-2 text-right">Consults</th><th scope="col" class="px-3 py-2 text-right">Sign-offs</th><th scope="col" class="px-3 py-2 text-right">Avg LOS</th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="r in kpiGrid" :key="r.label" class="hover:bg-brand-50/40">
                                <td class="px-3 py-1.5 font-medium text-ink-700">{{ r.label }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.admissions }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.discharges }}</td>
                                <td class="nums px-3 py-1.5 text-right text-on-danger">{{ r.icu }}</td>
                                <td class="nums px-3 py-1.5 text-right text-on-danger">{{ r.transToIcu }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.icuDeaths }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.wardDeaths }}</td>
                                <td class="nums px-3 py-1.5 text-right text-on-accent">{{ r.readmits }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.consultations }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.signoffs }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.avgLos }}d</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
