<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminBandCard from '@/Components/AdminBandCard.vue';
import OccupancyTracker from '@/Components/OccupancyTracker.vue';
import ChartFigure from '@/Components/ChartFigure.vue';
import Sparkline from '@/Components/Sparkline.vue';
import { useChartTheme } from '@/composables/useChartTheme';
import { useReducedMotion, chartAnimations } from '@/composables/useReducedMotion';
import { locTone, deltaChipClass } from '@/lib/ui.js';

// theme-aware chart colors (grid/axis read CSS tokens; donut gaps match the card)
const { gridColor, axisColor, strokeColor, series } = useChartTheme();
// prefers-reduced-motion → every chart's draw animation is gated through chartAnimations(reduced)
const { reduced } = useReducedMotion();

const props = defineProps({
    // Wave 1, Item 7 — admin landing band counts; null for non-admins (band then renders nothing).
    adminBand: { type: Object, default: null },
    kpis: Object,
    boardingCount: Number,
    boardingWorklist: Array,
    deltas: Object,
    alerts: Array,
    myUnit: Object,
    loadBands: Object,
    trend: Object,
    consults: Object,
    consultDonut: Object,
    los: Object,
    mix: Object,
    donutTotal: Number,
    donutTb: Number,
    perConsultant: Array,
    consultantBoard: Array,
    activity24h: Array,
    ytd: Object,
    topDxWeek: Array,
    topDxWeekNum: Number,
    recent: Array,
    generatedAt: String,
});

const page = usePage();
const auth = computed(() => page.props.auth.user);

// ── Admin landing band (Wave 1, Item 7) ────────────────────────────────────────────────────────
// is_admin only. Each card deep-links into a regrouped admin section; counts come from the
// `adminBand` prop (DashboardController, reusing the digest/Security/soft-delete/handover sources).
// Inline-SVG icon paths follow the app's icon convention (same stroked style as the sidebar).
const bandIcons = {
    quality: 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
    security: 'M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.572-.598-3.751h-.152c-3.196 0-6.1-1.249-8.25-3.285Zm0 13.036h.008v.008H12v-.008Z',
    trash: 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
    handover: 'M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 13.125l8.69-8.69a1.5 1.5 0 0 1 2.12 0L21 11.25M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75',
};
const adminBandCards = computed(() => props.adminBand ? [
    { label: 'Data Quality Issues', count: props.adminBand.dqIssues ?? 0, href: '/data-quality', iconPath: bandIcons.quality, urgent: true },
    { label: 'Security Anomalies', count: props.adminBand.securityAnomalies ?? 0, href: '/security', iconPath: bandIcons.security, urgent: true },
    { label: 'Recently Deleted', count: props.adminBand.recentlyDeleted ?? 0, href: '/trashed', iconPath: bandIcons.trash, urgent: false },
    { label: 'Pending Handovers', count: props.adminBand.pendingHandovers ?? 0, href: '/handovers', iconPath: bandIcons.handover, urgent: false },
] : []);

// ── Drill-through (Item 3) ───────────────────────────────────────────────────────────────────
// every dashboard number links to the matching /patients board view; the destination list re-uses
// the same board filters so it matches the clicked figure (consultant_id stays inside the D1 scope).
const drillTo = (params) => router.visit('/patients', { data: params });
const parseHref = (href) => {
    const [path, qs] = href.split('?');
    return qs ? { path, query: Object.fromEntries(new URLSearchParams(qs)) } : { path };
};
const goHref = (href) => {
    const { path, query } = parseHref(href);
    router.visit(path, query ? { data: query } : {});
};
// SPC-TM-011 (Wave 1): a Recent-Admissions row jumps to the board by POSTing the MRN in the body —
// the term never enters a URL (goHref stays for the non-PII drill-through links only).
const openPatient = (mrn) => router.post('/patients', { search: String(mrn ?? '') });
const consultantIdByName = computed(() =>
    Object.fromEntries((props.consultantBoard || []).map((c) => [c.name, c.id])));
const drillToConsultant = (name) => {
    const id = consultantIdByName.value[name];
    if (id) drillTo({ consultant_id: id });
};

// ── KPI deltas (Item 2 + W4 polarity) ──────────────────────────────────────────────────────────
// A delta {value, delta, direction} plus the KPI's own `polarity` → an up/down chip. The chip's
// colour is driven by polarity + direction (deltaChipClass in lib/ui.js), NOT by the delta's own
// good_up flag: a rise in a 'bad' KPI (mortality, occupancy) is red; a rise in a 'good' KPI is
// green; a 'neutral' KPI (census, admissions) is always ink. The arrow glyph + number carry the
// meaning too, so colour is never the sole signal.
const deltaChip = (d, polarity) => {
    if (!d || d.delta === undefined || d.delta === null) return null;
    const arrow = d.direction === 'flat' ? '■' : d.direction === 'up' ? '▲' : '▼';
    return { label: `${arrow} ${Math.abs(d.delta)}`, cls: deltaChipClass(polarity, d.direction) };
};
const chip = (c) => (c.deltaKey ? deltaChip(props.deltas?.[c.deltaKey], c.polarity) : null);

// ── Threshold alert strip (Item 4) ───────────────────────────────────────────────────────────
// dismissible per-key via sessionStorage (tab-scoped) so a ward screen re-surfaces the alert on
// reload, while a nurse who dismisses it during a shift isn't re-nagged in the same tab.
const dismissed = ref(new Set(JSON.parse(sessionStorage.getItem('dmc-alerts-dismissed') || '[]')));
const dismiss = (key) => {
    dismissed.value.add(key);
    dismissed.value = new Set(dismissed.value);
    sessionStorage.setItem('dmc-alerts-dismissed', JSON.stringify([...dismissed.value]));
};
const visibleAlerts = computed(() => (props.alerts || []).filter((a) => !dismissed.value.has(a.key)));

// ── 'My unit today' lens (Item 5) ────────────────────────────────────────────────────────────
const isConsultant = computed(() => auth.value?.role === 3);
const myToggle = ref(localStorage.getItem('dmc-my-unit') !== 'off');   // default ON for consultants
const setMyToggle = (v) => { myToggle.value = v; localStorage.setItem('dmc-my-unit', v ? 'on' : 'off'); };
// W0-T3i. Three of these six fills used to name a `-50` step that @theme never declared, so Tailwind
// emitted no rule and ICU / Boarding / New rendered with NO fill at all — half the row's colour
// coding was dead, invisibly. They now use the theme-aware `tint-*` tokens (the same family the
// alert strip and the flags use), at the alpha whose CIE dE76 from `bg-card` matches the two fills
// that always worked: brand-50 = 4.25, ink-50 = 3.90; tint-danger/30 = 4.23, tint-warning/30 = 4.60,
// tint-info/30 = 4.37. Value labels (`text-ink-900`) land at 13.7-14.2:1 light and 15.0-15.6:1 dark.
// Declaring the `-50` steps instead was rejected: it would light up `text-*-50` and `border-*-50`
// app-wide (see the @theme warning in app.css) to fix three fills.
const myUnitCards = computed(() => props.myUnit ? [
    ['Active', props.myUnit.total, 'bg-brand-50', '/patients'],
    ['Ward', props.myUnit.ward, 'bg-ink-50', '/patients?location=Ward'],
    ['ICU', props.myUnit.icu, 'bg-tint-danger/30', '/patients?location=ICU'],
    ['Boarding', props.myUnit.boarding, 'bg-tint-warning/30', '/patients?view=boarding'],
    ['New (24h)', props.myUnit.new, 'bg-tint-info/30', null],
    ['Consults', props.myUnit.myConsults, 'bg-accent-300/20', '/consultations'],
] : []);

// ── Load-fairness bands (Item 6) ─────────────────────────────────────────────────────────────
// W0-T3l. The `from-warning-400` / `from-danger-400` stops below (and `from-warning-400` in
// `toneClass`) were undeclared steps until cd1f710, so every under/over-loaded bar started from
// `transparent` and only acquired colour at its `to-` stop. They are decorative gradient fills
// carrying no text; declaring the steps simply gives each bar the intended two-stop ramp.
const barTone = (c) => {
    const isHosp = c.specialty_id === 1;
    const min = isHosp ? props.loadBands.minHosp : props.loadBands.minSubs;
    const max = isHosp ? props.loadBands.maxHosp : props.loadBands.maxSubs;
    if (c.c < min) return 'from-warning-400 to-warning-500';
    if (c.c > max) return 'from-danger-400 to-danger-600';
    return 'from-brand-400 to-brand-600';
};
const bandStyle = (c) => {
    const isHosp = c.specialty_id === 1;
    const min = isHosp ? props.loadBands.minHosp : props.loadBands.minSubs;
    const max = isHosp ? props.loadBands.maxHosp : props.loadBands.maxSubs;
    const ref = consultantMax.value;
    return { left: `${Math.min(100, min / ref * 100)}%`, width: `${Math.max(0, (max - min) / ref * 100)}%` };
};
const overloaded = computed(() => (props.perConsultant || []).filter((c) =>
    c.c > (c.specialty_id === 1 ? props.loadBands.maxHosp : props.loadBands.maxSubs)).length);
const underloaded = computed(() => (props.perConsultant || []).filter((c) =>
    c.c < (c.specialty_id === 1 ? props.loadBands.minHosp : props.loadBands.minSubs)).length);
const canShuffle = computed(() => auth.value?.role !== 5 && (auth.value?.is_admin || auth.value?.can?.assign));

const boardSections = computed(() => {
    const bucket = (c) => c.on_service && c.specialty_id === 1 ? 'hosp' : c.on_service ? 'subs' : 'off';
    return [
        { key: 'hosp', label: 'On-service · Hospitalists', rows: props.consultantBoard.filter((c) => bucket(c) === 'hosp') },
        { key: 'subs', label: 'On-service · Subspecialists', rows: props.consultantBoard.filter((c) => bucket(c) === 'subs') },
        { key: 'off', label: 'Off-service', rows: props.consultantBoard.filter((c) => bucket(c) === 'off') },
    ].filter((s) => s.rows.length);
});

// W4: chart series colours come from the theme-reactive token palette (useChartTheme → `series`),
// never local hex — so the deep/muted hues lighten on dark instead of vanishing into the card.

// W4 triad: each KPI carries a `polarity` (good | bad | neutral) that drives the delta-chip colour,
// and — where a real payload series exists — a `spark` array rendered as an inline Sparkline. Only
// Admissions (trend.admissions) and Active Consultations (consults.new) have a genuine series; the
// rest deliberately have no spark (no invented data).
const kpiCards = computed(() => [
    { label: 'Active Census', value: props.kpis.census, sub: `${props.kpis.ward} ward · ${props.kpis.icu} ICU`, icon: 'bed', tone: 'brand', href: '/patients', polarity: 'neutral' },
    { label: 'Admissions Today', value: props.kpis.admissionsToday, sub: 'vs 7d avg', icon: 'in', tone: 'blue', deltaKey: 'admissions', polarity: 'neutral', spark: props.trend.admissions },
    { label: 'Discharges Today', value: props.kpis.dischargesToday, sub: 'vs 7d avg · non-ICU', icon: 'out', tone: 'teal', deltaKey: 'discharges', polarity: 'good', spark: props.trend.discharges, href: '/recent' },
    { label: 'Active Consultations', value: props.kpis.activeConsults, sub: 'awaiting sign-off', icon: 'chat', tone: 'gold', href: '/consultations', polarity: 'neutral', spark: props.consults.new },
    { label: 'Bed Occupancy', value: props.kpis.occupancy + '%', sub: `of ${props.kpis.wardBeds} ward beds · vs 1w ago`, icon: 'gauge', tone: 'teal', deltaKey: 'occupancy', polarity: 'bad' },
    { label: 'Avg LOS (month)', value: props.kpis.avgLosMonth, sub: 'days · non-ICU discharges', icon: 'clock', tone: 'navy', polarity: 'bad' },
    { label: 'Mortality (Month)', value: props.kpis.deathsMonth, sub: 'this calendar month · vs prior month', icon: 'trendDown', tone: 'red', deltaKey: 'deathsMonth', polarity: 'bad' },
    { label: 'Boarding', value: props.boardingCount, sub: 'medically cleared · bed still occupied', icon: 'boarding', tone: 'warning', href: '/patients?view=boarding', polarity: 'bad' },
]);
const toneClass = {
    brand: 'from-brand-500 to-brand-700', blue: 'from-info-500 to-blue-700', gold: 'from-accent-400 to-accent-600',
    teal: 'from-brand-400 to-brand-600', red: 'from-danger-500 to-danger-600', navy: 'from-navy-700 to-navy-900',
    warning: 'from-warning-400 to-warning-500',
};
const kpiIcons = {
    bed: 'M3 7.5h13.5a3 3 0 0 1 3 3V18M3 7.5V18m0-10.5V6m18 12H3',
    in: 'M3 12h13.5m0 0-4.5-4.5M16.5 12 12 16.5M21 4.5v15',
    out: 'M21 12H7.5m0 0 4.5-4.5M7.5 12l4.5 4.5M3 4.5v15',
    chat: 'M8.25 8.25h7.5m-7.5 3.75h4.5m4.94 4.06a8.25 8.25 0 1 0-3.32 2.0L21 21Z',
    gauge: 'M12 3a9 9 0 1 0 9 9M12 12l4.5-4.5M21 12h-2M5 12H3m9-7v2',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    trendDown: 'M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898m0 0 3.182-5.511m-3.182 5.51-5.511-3.181',
    boarding: 'M12 6v6l3.75 2.25M3.75 12a8.25 8.25 0 1 1 16.5 0 8.25 8.25 0 0 1-16.5 0Z',
};

// Bed-occupancy segmented tracker (W4) — replaces the old radialBar gauge, which blended ward+ICU
// into one % and hid the composition. Ward + ICU each read their licensed bed count from `settings`
// (kpis.wardBeds / kpis.icuBeds); the tracker draws one calm band per unit + a summed line.
const occUnits = computed(() => [
    { key: 'ward', label: 'Ward', occupied: props.kpis.ward, total: props.kpis.wardBeds },
    { key: 'icu', label: 'ICU', occupied: props.kpis.icu, total: props.kpis.icuBeds },
]);
// warning/critical as fractions of capacity — the OccupancyTracker design defaults, kept explicit here.
const occThresholds = { warning: 0.85, critical: 0.95 };

// PNG-export-only toolbar (no zoom/pan clutter) — applied to every chart on this page.
const dlToolbar = { show: true, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } };

// direct end-of-line labels for the trend area (replaces the top legend): a pill at each series'
// last point. Null-safe when there are no labels (empty period).
const areaAnnotations = computed(() => {
    const labels = props.trend.labels;
    if (!labels.length) return {};
    const lastX = new Date(labels[labels.length - 1]).getTime();
    const end = (data, text, color) => ({
        x: lastX, y: data[data.length - 1] ?? 0,
        marker: { size: 0 },
        label: { text, borderWidth: 0, offsetY: -6, textAnchor: 'end',
            style: { color: '#fff', background: color, fontWeight: 700, fontSize: '11px', padding: { left: 6, right: 6, top: 2, bottom: 2 } } },
    });
    return { points: [
        end(props.trend.admissions, 'Admissions', series.value.primary),
        end(props.trend.discharges, 'Discharges', series.value.muted),
    ] };
});
const areaOptions = computed(() => ({
    chart: { type: 'area', toolbar: dlToolbar, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.primary, series.value.muted],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 90] } },
    grid: { borderColor: gridColor.value, strokeDashArray: 4, padding: { left: 8, right: 64 } },   // right pad = room for the end-labels
    xaxis: { categories: props.trend.labels, type: 'datetime', labels: { style: { colors: axisColor.value }, datetimeFormatter: { day: 'dd MMM' } }, axisBorder: { show: false }, axisTicks: { show: false }, tickAmount: 6 },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { show: false },   // direct-labelled at each line's end instead (+ ChartFigure caption/table)
    annotations: areaAnnotations.value,
    tooltip: { x: { format: 'ddd, dd MMM' } },
}));
const areaSeries = computed(() => [
    { name: 'Admissions', data: props.trend.admissions },
    { name: 'Discharges', data: props.trend.discharges },
]);

const colOptions = (cats, colors) => ({
    chart: { type: 'bar', toolbar: dlToolbar, fontFamily: 'inherit', stacked: false, animations: chartAnimations(reduced) },
    colors, plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false }, grid: { borderColor: gridColor.value, strokeDashArray: 4 },
    xaxis: { categories: cats, labels: { style: { colors: axisColor.value } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { position: 'top', horizontalAlign: 'right', fontWeight: 600, labels: { colors: axisColor.value } },
});
const consultsSeries = computed(() => [{ name: 'New', data: props.consults.new }, { name: 'Signed off', data: props.consults.signed }]);
const losSeries = computed(() => [{ name: 'Patients', data: props.los.data }]);
// Reactive option getters (were inline colOptions() calls in the template, which never re-themed on
// dark-mode flip because the template didn't track axisColor/series). As computeds they update.
const consultsOptions = computed(() => colOptions(props.consults.labels, [series.value.primary, series.value.accent]));
const losOptions = computed(() => colOptions(props.los.labels, [series.value.deep]));

// Both donuts are DIRECT-LABELLED on the slice (dataLabels: % for the mix, raw count for the
// consult pair) and keep only a MINIMAL bottom legend for slice IDENTITY. The legend is not removed
// outright because a value-only slice label doesn't say WHICH slice, and a tiny slice (e.g. a small
// long-term segment) drops its on-slice label below ApexCharts' min-angle threshold — the compact
// legend is the reliable identifier. `chartAnimations(reduced)` gates the draw animation.
const donutLegend = { position: 'bottom', fontSize: '12px', fontWeight: 600, markers: { size: 7 }, itemMargin: { horizontal: 8, vertical: 0 } };
const donutOptions = computed(() => ({
    chart: { type: 'donut', toolbar: dlToolbar, fontFamily: 'inherit', animations: chartAnimations(reduced), events: {
        // drill-through (Item 3): the long-term slice → the long-term board view. Hospitalist /
        // subspecialty slices map to a per-admission field the board can't filter coarsely yet, so
        // only long-term drills for now (consistent with the donut's own definition).
        dataPointSelection: (_e, _c, config) => {
            if (['hospitalist', 'subspecialty', 'longterm'][config.dataPointIndex] === 'longterm') drillTo({ view: 'longterm' });
        },
    } },
    colors: [series.value.primary, series.value.accent, series.value.deep],
    labels: ['Hospitalist', 'Sub-specialty', 'Long-term'],
    legend: { ...donutLegend, labels: { colors: axisColor.value } },
    dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
    stroke: { width: 2, colors: [strokeColor.value] },
    plotOptions: { pie: { donut: { size: '70%', labels: { show: true, total: { show: true, label: 'Census', color: axisColor.value, formatter: (w) => w.globals.seriesTotals.reduce((a, b) => a + b, 0) } } } } },
}));
const donutSeries = computed(() => [props.mix.hospitalist, props.mix.subspecialty, props.mix.longterm]);

// consultation donut — legacy dashboard/1.php pair: [signed off in the last 24h, active] (J2-5)
const consultDonutOptions = computed(() => ({
    chart: { type: 'donut', toolbar: dlToolbar, fontFamily: 'inherit', animations: chartAnimations(reduced) },
    colors: [series.value.accent, series.value.primary],
    labels: ['Signed off (24h)', 'Active'],
    legend: { ...donutLegend, labels: { colors: axisColor.value } },
    dataLabels: { enabled: true, formatter: (v, o) => o.w.globals.series[o.seriesIndex] },
    stroke: { width: 2, colors: [strokeColor.value] },
    plotOptions: { pie: { donut: { size: '70%' } } },
}));
const consultDonutSeries = computed(() => [props.consultDonut.signed24h, props.consultDonut.active]);

const consultantMax = computed(() => Math.max(1, ...props.perConsultant.map((c) => c.c)));

// per-consultant activity since yesterday (grouped bars)
const act24Options = computed(() => colOptions(props.activity24h.map((r) => r.name), [series.value.info, series.value.accent]));
const act24Series = computed(() => [
    { name: 'Admissions', data: props.activity24h.map((r) => r.admissions) },
    { name: 'Discharges', data: props.activity24h.map((r) => r.discharges) },
]);

// YTD counter strip
const ytdCards = computed(() => [
    ['Admissions', props.ytd.admissions], ['Discharges', props.ytd.discharges],
    ['Consultations', props.ytd.consultations], ['Sign-offs', props.ytd.signoffs],
]);

// ── ChartFigure accessible-alternative tables + empty guards (W4) ────────────────────────────────
// Each full chart is wrapped in <ChartFigure>, which appends an sr-only caption + data table so the
// numbers are reachable without sight. The `has*` guards swap a blank/NaN chart for a calm visible
// "No data for this period." note (donuts especially render broken with an all-zero series). Rows are
// emptied in lock-step so the sr-only table matches what sighted users see.
const hasTrend = computed(() => props.trend.labels.length > 0);
const hasConsults = computed(() => props.consults.labels.length > 0);
const hasLos = computed(() => props.los.labels.length > 0);
const hasMix = computed(() => props.donutTotal > 0);
const hasConsultDonut = computed(() => (props.consultDonut.signed24h + props.consultDonut.active) > 0);
const hasAct24 = computed(() => props.activity24h.length > 0);

const trendRows = computed(() => (hasTrend.value
    ? props.trend.labels.map((d, i) => [d, props.trend.admissions[i] ?? 0, props.trend.discharges[i] ?? 0]) : []));
const consultsRows = computed(() => (hasConsults.value
    ? props.consults.labels.map((m, i) => [m, props.consults.new[i] ?? 0, props.consults.signed[i] ?? 0]) : []));
const losRows = computed(() => (hasLos.value
    ? props.los.labels.map((b, i) => [b, props.los.data[i] ?? 0]) : []));
const mixRows = computed(() => (hasMix.value
    ? [['Hospitalist', props.mix.hospitalist], ['Sub-specialty', props.mix.subspecialty], ['Long-term', props.mix.longterm]] : []));
const consultDonutRows = computed(() => (hasConsultDonut.value
    ? [['Signed off (24h)', props.consultDonut.signed24h], ['Active', props.consultDonut.active]] : []));
const act24Rows = computed(() => (hasAct24.value
    ? props.activity24h.map((r) => [r.name, r.admissions, r.discharges]) : []));

const refresh = () => router.reload({ only: ['kpis', 'boardingCount', 'boardingWorklist', 'deltas', 'alerts', 'myUnit', 'loadBands', 'trend', 'consults', 'consultDonut', 'los', 'mix', 'donutTotal', 'donutTb', 'perConsultant', 'consultantBoard', 'activity24h', 'ytd', 'topDxWeek', 'topDxWeekNum', 'recent', 'generatedAt'] });
const print = () => window.print();

// 5-minute auto-refresh, visibility-gated: a dashboard left open on a ward screen stays
// current, but background tabs don't hammer the server.
let autoRefresh = null;
onMounted(() => {
    autoRefresh = setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 300000);
});
onUnmounted(() => clearInterval(autoRefresh));
</script>

<template>
    <AppLayout title="Command Center">
        <!-- sub header -->
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-sm text-ink-500">Internal Medicine · live operational overview</p>
            </div>
            <div class="flex items-center gap-3 text-sm text-ink-400">
                <span class="nums">Updated {{ generatedAt }}</span>
                <button @click="print" class="no-print inline-flex items-center gap-2 rounded-xl border border-line bg-card px-3 py-2 font-semibold text-ink-600 shadow-sm transition hover:border-brand-300 hover:text-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
                    Print
                </button>
                <button @click="refresh" class="no-print inline-flex items-center gap-2 rounded-xl border border-line bg-card px-3 py-2 font-semibold text-ink-600 shadow-sm transition hover:border-brand-300 hover:text-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992m-4.49-4.51a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m-15.91 8.51 3.181 3.182a8.25 8.25 0 0 0 13.803-3.7" /></svg>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Admin landing band (Wave 1, Item 7): operational-health cards deep-linking into the
             regrouped admin sections — admins only; clinical roles never see it. -->
        <section v-if="auth.is_admin && adminBand" aria-label="Administrative overview" class="no-print mb-6">
            <h2 class="font-display mb-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-400">Administrative</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AdminBandCard v-for="card in adminBandCards" :key="card.href"
                    :label="card.label" :count="card.count" :href="card.href" :icon-path="card.iconPath" :urgent="card.urgent" />
            </div>
        </section>

        <!-- Threshold alert strip (Item 4): dismissible, severity-coloured, atop the dashboard.
             W0-T3e: danger-700 / warning-600 AS TEXT were DEAD (undeclared steps → zero CSS), so
             both branches rendered in the inherited body ink. Declaring those steps is not the fix:
             they are dark fills, and on the theme-INVARIANT `*-100/80` strip they'd land at 3.84:1
             (danger, dark) and 1.66:1 (warning, dark). Migrated to the FlowAlert vocabulary instead —
             `bg-tint-*` + `text-on-*`, both theme-aware. The LIGHT fill is byte-identical to before
             (tint-danger == danger-100), so this is a dark-mode-only visual change. The rings are now
             live (warning-300 / danger-300).

             BACKDROP: this strip is a direct child of AppLayout's <main>, which sets no background —
             so an /80 fill composites over `--surface-app`, NOT over `bg-card`. Every ratio W0-T3e
             recorded here used bg-card and was therefore slightly off. Correct figures:
                        light  dark
               danger   5.64   8.45      (bg-card would have said 5.76 / 8.29)
               warning  5.21   8.84      (bg-card would have said 5.30 / 8.65)
             All four still clear AA, so this is a documentation fix, not a colour change. (For the
             record, the "1.98:1" above was the same bg-card error: warning-600 #c87d06 as text on
             that strip was 1.90:1 over bg-card and 1.86:1 over the real backdrop. warning-600 has
             since moved to the lighter #d58506, which takes it to 1.69 / 1.66 — still nowhere near
             text, which is the point.) -->
        <div v-for="alert in visibleAlerts" :key="alert.key"
             class="no-print mb-4 flex items-start justify-between gap-3 rounded-2xl px-5 py-3 ring-1"
             :class="alert.severity === 'danger'
                 ? 'bg-tint-danger/80 ring-danger-300/60 text-on-danger'
                 : 'bg-tint-warning/80 ring-warning-300/60 text-on-warning'">
            <div class="flex items-center gap-3">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <span class="text-sm font-semibold">{{ alert.message }}</span>
                <button v-if="alert.link" @click="goHref(alert.link)" class="ml-2 text-xs font-bold underline hover:no-underline">View →</button>
            </div>
            <!-- W0-T3j. Hover affordance is drawn on the button's border (see the class), NOT via
                 `opacity` and NOT via a background wash. `opacity` composites the whole glyph toward the
                 backdrop (~2.5:1 on hover; WCAG 1.4.3 has no hover exemption). A DARKENING background wash
                 also cuts contrast, because contrast is glyph-vs-LOCAL-background: a 10%-black wash behind
                 the ✕ drops light on-warning from 5.20 to 4.16:1, below AA. A border stroke never touches
                 the glyph's background, so the ✕ keeps its full rest contrast (≥5.2:1 light, ≥6.3:1 dark)
                 in every state. (Prose here avoids spelling class tokens — Tailwind's extractor reads
                 comments and would mint them; see app.css §top and EhcLogo.vue.) -->
            <button @click="dismiss(alert.key)" aria-label="Dismiss alert" class="shrink-0 rounded p-0.5 text-inherit transition hover:ring-1 hover:ring-inset hover:ring-current">✕</button>
        </div>

        <!-- 'My unit today' lens (Item 5): consultants only; default ON, toggle persisted per-browser -->
        <div v-if="isConsultant && myUnit && myToggle" class="mb-5 rounded-2xl bg-card p-5 shadow-card-lg ring-1 ring-brand-200/60">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold text-ink-800">My patients today</h2>
                <button @click="setMyToggle(false)" class="text-xs text-ink-400 hover:text-ink-600">Hide</button>
            </div>
            <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
                <button v-for="[label, value, tone, link] in myUnitCards" :key="label" type="button"
                        class="rounded-xl p-3 text-left ring-1 ring-line transition" :class="[tone, link ? 'cursor-pointer hover:ring-brand-300' : 'cursor-default']"
                        @click="link && goHref(link)">
                    <p class="nums text-2xl font-extrabold text-ink-900">{{ value }}</p>
                    <p class="text-xs text-ink-400">{{ label }}</p>
                </button>
            </div>
            <Link v-if="myUnit.signPending" href="/handovers"
                  class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 hover:bg-brand-100">
                {{ myUnit.signPending }} pending handover {{ myUnit.signPending === 1 ? 'signature' : 'signatures' }} →
            </Link>
        </div>
        <div v-else-if="isConsultant && myUnit && !myToggle" class="mb-5">
            <button @click="setMyToggle(true)" class="text-xs font-semibold text-brand-600 hover:underline">Show my patients →</button>
        </div>

        <!-- KPI hero row (data-tour anchor for the onboarding tour, Item 10) -->
        <h2 class="sr-only">Key performance indicators</h2>
        <div data-tour="dashboard-hero" class="grid grid-cols-2 gap-4 sm:grid-cols-4 xl:grid-cols-8">
            <!-- W4 triad: label → big tabular numeral → (delta chip + inline sparkline) row, brand icon tile kept -->
            <component :is="c.href ? 'button' : 'div'" v-for="c in kpiCards" :key="c.label" type="button"
                :data-kpi="c.label"
                @click="c.href && goHref(c.href)"
                class="relative w-full overflow-hidden rounded-2xl bg-card p-5 text-start shadow-card-lg ring-1 transition"
                :class="c.tone === 'warning' ? 'ring-warning-300/60' : 'ring-brand-200/60'">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ c.label }}</p>
                        <p class="font-display nums mt-2 text-3xl font-extrabold" :class="c.tone === 'warning' ? 'text-on-warning' : 'text-ink-900'">{{ c.value }}</p>
                        <p class="mt-1 text-xs text-ink-400">{{ c.sub }}</p>
                        <!-- triad row 3: delta chip + inline sparkline. Colour is a graphic hue on the spark
                             (text-brand-600 → currentColor); meaning on the chip is arrow + number, not colour. -->
                        <div v-if="chip(c) || c.spark?.length" class="mt-2 flex items-center gap-2">
                            <span v-if="chip(c)" class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-bold" :class="chip(c).cls">{{ chip(c).label }}</span>
                            <div v-if="c.spark?.length" class="min-w-0 flex-1">
                                <Sparkline :data="c.spark" :aria-label="`${c.label}, recent trend`" class="text-brand-600" />
                            </div>
                        </div>
                    </div>
                    <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br text-white shadow-lg" :class="toneClass[c.tone]">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="kpiIcons[c.icon]" /></svg>
                    </div>
                </div>
                <div class="pointer-events-none absolute -bottom-6 -right-4 h-20 w-20 rounded-full bg-gradient-to-br opacity-10" :class="toneClass[c.tone]"></div>
                <span v-if="c.href" class="pointer-events-none absolute inset-0 rounded-2xl ring-2 ring-transparent transition hover:ring-brand-400"></span>
            </component>
        </div>

        <!-- Boarding worklist (Item 1): ranked longest-boarding first, links to the board view -->
        <div v-if="boardingWorklist.length" class="mt-5 rounded-2xl bg-card p-5 shadow-card ring-1 ring-warning-300/60">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-semibold text-ink-700">Boarding patients
                    <span class="nums ml-2 rounded-full bg-tint-warning px-2 py-0.5 text-xs font-bold text-on-warning">{{ boardingCount }}</span>
                </h2>
                <button @click="goHref('/patients?view=boarding')" class="text-xs font-semibold text-brand-600 hover:underline">View board →</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-2 py-2">Name</th><th scope="col" class="px-2 py-2">MRN</th>
                        <th scope="col" class="px-2 py-2">Consultant</th><th scope="col" class="px-2 py-2 text-right">Delay (days)</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in boardingWorklist" :key="r.id" class="cursor-pointer hover:bg-tint-warning/40" @click="goHref('/patients?view=boarding')">
                            <td class="px-2 py-2 font-semibold text-ink-700">{{ r.name }}</td>
                            <td class="nums px-2 py-2 text-ink-500">{{ r.mrn }}</td>
                            <td class="px-2 py-2 text-ink-600">{{ r.consultant }}</td>
                            <td class="nums px-2 py-2 text-right font-bold text-on-warning">{{ r.delay_days }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- YTD counter strip -->
        <div class="mt-5 rounded-2xl bg-card px-5 py-4 shadow-card ring-1 ring-line">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
                <span class="text-xs font-semibold uppercase tracking-wide text-ink-400">Year to date<span class="block text-[10px] font-normal normal-case text-ink-300">adm / disch non-ICU</span></span>
                <div v-for="[label, value] in ytdCards" :key="label" class="flex items-baseline gap-2">
                    <span class="font-display nums text-2xl font-extrabold text-brand-700">{{ (value ?? 0).toLocaleString() }}</span>
                    <span class="text-xs font-semibold text-ink-500">{{ label }}</span>
                </div>
            </div>
        </div>

        <!-- charts grid -->
        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
            <!-- trend (2/3) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line lg:col-span-2">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold text-ink-700">Admissions vs Discharges</h2>
                    <span class="rounded-full bg-ink-50 px-3 py-1 text-xs font-semibold text-ink-500">Last 30 days</span>
                </div>
                <ChartFigure title="Admissions vs Discharges" caption="Daily non-ICU admissions versus discharges over the last 30 days; each line is labelled at its end." :columns="['Day', 'Admissions', 'Discharges']" :rows="trendRows">
                    <apexchart v-if="hasTrend" type="area" height="300" :options="areaOptions" :series="areaSeries" aria-label="Area chart: admissions versus discharges over the last 30 days" />
                    <p v-else class="grid h-[300px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
            <!-- Bed occupancy — segmented tracker (W4, replaces the radialBar gauge). PRIMARY tier (hero). -->
            <div class="rounded-2xl bg-card p-5 shadow-card-lg ring-1 ring-brand-200/60">
                <h2 class="mb-3 font-bold text-ink-800">Bed Occupancy</h2>
                <OccupancyTracker :units="occUnits" :thresholds="occThresholds" />
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Consultations</h2>
                <ChartFigure title="Consultations" caption="New consultations versus sign-offs over the last six months." :columns="['Month', 'New', 'Signed off']" :rows="consultsRows">
                    <apexchart v-if="hasConsults" type="bar" height="260" :options="consultsOptions" :series="consultsSeries" aria-label="Bar chart: consultations received and signed off" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
            <!-- legacy dashboard/1.php consultation donut: signed off in the last 24h vs active (J2-5) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Consultations <span class="font-normal text-ink-400">(24h sign-offs vs active)</span></h2>
                <ChartFigure title="Consultations — 24h sign-offs vs active" caption="Consultations signed off in the last 24 hours versus those still active." :columns="['Status', 'Count']" :rows="consultDonutRows">
                    <apexchart v-if="hasConsultDonut" type="donut" height="260" :options="consultDonutOptions" :series="consultDonutSeries" :aria-label="`Donut chart: ${consultDonut.signed24h} consultations signed off in the last 24 hours, ${consultDonut.active} active`" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Length of Stay <span class="font-normal text-ink-400">(this year)</span></h2>
                <ChartFigure title="Length of Stay" caption="Distribution of length of stay (days) for non-ICU discharges this year." :columns="['LOS (days)', 'Patients']" :rows="losRows">
                    <apexchart v-if="hasLos" type="bar" height="260" :options="losOptions" :series="losSeries" aria-label="Bar chart: length-of-stay distribution this year" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
            <!-- legacy census donut title carries the headline + TB count over the DONUT'S OWN
                 population (assigned non-ICU — dashboard/1.php:151-154), not the all-active KPI (M1/5) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-2 font-semibold text-ink-700">Current patients: <span class="nums">{{ donutTotal }}</span> <span class="font-normal text-ink-400">(incl. {{ donutTb }} TB)</span></h2>
                <ChartFigure title="Current patients by service" caption="Assigned non-ICU census by service line (Hospitalist, Sub-specialty, Long-term)." :columns="['Service', 'Patients']" :rows="mixRows">
                    <apexchart v-if="hasMix" type="donut" height="260" :options="donutOptions" :series="donutSeries" :aria-label="`Donut chart: assigned non-ICU census by service — ${donutTotal} patients including ${donutTb} TB`" />
                    <p v-else class="grid h-[260px] place-items-center text-sm text-ink-400">No data for this period.</p>
                </ChartFigure>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- per consultant — load-fairness bands (Item 6) + drill-through (Item 3) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <div class="mb-1 flex items-center justify-between">
                    <h2 class="font-semibold text-ink-700">Active Load by Consultant</h2>
                    <span class="text-[11px] text-ink-400">band = min–max census</span>
                </div>
                <div class="space-y-3">
                    <div v-for="c in perConsultant" :key="c.name"
                         class="flex cursor-pointer items-center gap-3 rounded-lg px-1 py-0.5 hover:bg-brand-50/40"
                         @click="drillTo({ consultant_id: c.id })">
                        <div class="w-40 shrink-0 truncate text-sm font-medium text-ink-600">{{ c.name }}</div>
                        <div class="relative h-2.5 flex-1 overflow-hidden rounded-full bg-ink-50">
                            <!-- min–max reference band for this consultant's specialty -->
                            <div class="absolute top-0 h-full rounded-full bg-brand-100/60" :style="bandStyle(c)"></div>
                            <!-- actual load, coloured below-min / in-band / above-max -->
                            <div class="relative h-full rounded-full bg-gradient-to-r" :class="barTone(c)" :style="{ width: (c.c / consultantMax * 100) + '%' }"></div>
                        </div>
                        <div class="nums w-8 text-right text-sm font-bold text-ink-800">{{ c.c }}</div>
                    </div>
                    <p v-if="!perConsultant.length" class="text-sm text-ink-400">No active patients assigned.</p>
                </div>
                <!-- load-spread summary + rebalance hint -->
                <div v-if="overloaded || underloaded" class="mt-3 flex items-center gap-3 text-xs">
                    <span v-if="overloaded" class="font-semibold text-on-danger">{{ overloaded }} over max</span>
                    <span v-if="underloaded" class="font-semibold text-on-warning">{{ underloaded }} below min</span>
                    <button v-if="canShuffle" @click="goHref('/patients')" class="ml-auto font-semibold text-brand-600 hover:underline">
                        Rebalance (Shuffle / Reassign) →
                    </button>
                </div>
            </div>
            <!-- recent admissions — each row deep-links to that patient on the board. SPC-TM-011:
                 the MRN POSTs in the body (never a query string); a native <button> so it is
                 keyboard-operable, text-start + ms-/me- for RTL safety -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h2 class="mb-4 font-semibold text-ink-700">Recent Admissions</h2>
                <div class="divide-y divide-line">
                    <button v-for="r in recent" :key="r.mrn + r.admitted" type="button"
                            @click="openPatient(r.mrn)"
                            class="flex w-full items-center gap-3 rounded-lg px-1 py-2.5 text-start transition hover:bg-brand-50/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                        <div class="grid h-9 w-9 place-items-center rounded-full bg-ink-50 text-xs font-bold text-ink-500">{{ (r.name || '?').slice(0,2).toUpperCase() }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-ink-800">{{ r.name || 'Unknown' }}</p>
                            <p class="nums truncate text-xs text-ink-400">MRN {{ r.mrn }} · {{ r.consultant || 'Unassigned' }}</p>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="locTone(r.loc)">{{ r.loc || '—' }}</span>
                        <span class="nums hidden text-xs text-ink-400 sm:block">{{ r.admitted }}</span>
                    </button>
                    <p v-if="!recent.length" class="py-2 text-sm text-ink-400">No recent admissions.</p>
                </div>
            </div>
        </div>

        <!-- top diagnoses: this calendar week-number across ALL years (legacy seasonal view) -->
        <div class="print-break-before mt-5 rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
            <h2 class="mb-4 font-semibold text-ink-700">Top diagnoses <span class="font-normal text-ink-400">(week {{ topDxWeekNum }}, all years)</span></h2>
            <div class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
                <div v-for="(d, i) in topDxWeek" :key="i" class="flex items-center gap-3">
                    <div class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{{ i + 1 }}</div>
                    <div class="min-w-0 flex-1 truncate text-sm text-ink-700">{{ d.name }}</div>
                    <div class="nums text-sm font-bold text-brand-700">{{ d.count }}</div>
                </div>
                <p v-if="!topDxWeek.length" class="text-sm text-ink-400">No admissions recorded in week {{ topDxWeekNum }} of any year.</p>
            </div>
        </div>

        <!-- per-consultant 24h activity -->
        <div class="mt-5 rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
            <h2 class="mb-2 font-semibold text-ink-700">Admissions / Discharges per consultant <span class="font-normal text-ink-400">(since yesterday)</span></h2>
            <ChartFigure title="Admissions / Discharges per consultant" caption="Per-consultant admissions and discharges since yesterday." :columns="['Consultant', 'Admissions', 'Discharges']" :rows="act24Rows">
                <apexchart v-if="hasAct24" type="bar" height="280" :options="act24Options" :series="act24Series" aria-label="Grouped bar chart: admissions and discharges per consultant since yesterday" />
                <p v-else class="grid h-[280px] place-items-center text-sm text-ink-400">No data for this period.</p>
            </ChartFigure>
        </div>

        <!-- per-consultant breakdown table -->
        <div class="mt-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <div class="border-b border-line px-5 py-3"><h2 class="font-semibold text-ink-700">Patient count per consultant</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-2.5">Consultant</th><th scope="col" class="px-3 py-2.5 text-center">Old</th><th scope="col" class="px-3 py-2.5 text-center">New</th><th scope="col" class="px-3 py-2.5 text-center">Active</th><th scope="col" class="px-3 py-2.5 text-center">Ward</th><th scope="col" class="px-3 py-2.5 text-center">ICU</th><th scope="col" class="px-3 py-2.5 text-center">TB</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <template v-for="sec in boardSections" :key="sec.key">
                            <tr class="bg-app/70"><td colspan="7" class="px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-ink-500">{{ sec.label }}</td></tr>
                            <tr v-for="c in sec.rows" :key="c.name" class="cursor-pointer hover:bg-brand-50/40" @click="drillTo({ consultant_id: c.id })">
                                <td class="px-5 py-2 font-semibold text-ink-700">Dr. {{ c.name }}</td>
                                <td class="nums px-3 py-2 text-center text-ink-600">{{ c.old || '' }}</td>
                                <td class="nums px-3 py-2 text-center text-on-info">{{ c.new || '' }}</td>
                                <td class="nums px-3 py-2 text-center font-semibold text-brand-700">{{ c.active }}</td>
                                <td class="nums px-3 py-2 text-center text-ink-600">{{ c.ward }}</td>
                                <td class="nums px-3 py-2 text-center text-on-danger">{{ c.icu || '' }}</td>
                                <td class="nums px-3 py-2 text-center text-ink-500">{{ c.tb || '' }}</td>
                            </tr>
                        </template>
                        <tr v-if="!consultantBoard.length"><td colspan="7" class="px-5 py-6 text-center text-ink-400">No active patients assigned.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
