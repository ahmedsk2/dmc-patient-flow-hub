<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useChartTheme } from '@/composables/useChartTheme';

// theme-aware chart colors (grid/axis read CSS tokens; donut gaps match the card)
const { gridColor, axisColor, strokeColor, inkColor } = useChartTheme();

const props = defineProps({
    kpis: Object,
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

const boardSections = computed(() => {
    const bucket = (c) => c.on_service && c.specialty_id === 1 ? 'hosp' : c.on_service ? 'subs' : 'off';
    return [
        { key: 'hosp', label: 'On-service · Hospitalists', rows: props.consultantBoard.filter((c) => bucket(c) === 'hosp') },
        { key: 'subs', label: 'On-service · Subspecialists', rows: props.consultantBoard.filter((c) => bucket(c) === 'subs') },
        { key: 'off', label: 'Off-service', rows: props.consultantBoard.filter((c) => bucket(c) === 'off') },
    ].filter((s) => s.rows.length);
});

const C = { teal: '#009ca6', tealLight: '#38b4ba', navy: '#00565e', gold: '#d9a23c', pink: '#cf4b8f', red: '#e0413e', blue: '#2f7fe0', slate: '#5b6a6e' };

const kpiCards = computed(() => [
    { label: 'Active Census', value: props.kpis.census, sub: `${props.kpis.ward} ward · ${props.kpis.icu} ICU`, icon: 'bed', tone: 'brand' },
    { label: 'Admissions Today', value: props.kpis.admissionsToday, sub: `${props.kpis.dischargesToday} discharged today`, icon: 'in', tone: 'blue' },
    { label: 'Active Consultations', value: props.kpis.activeConsults, sub: 'awaiting sign-off', icon: 'chat', tone: 'gold' },
    { label: 'Bed Occupancy', value: props.kpis.occupancy + '%', sub: `of ${props.kpis.wardBeds} ward beds`, icon: 'gauge', tone: 'teal' },
    { label: 'Avg LOS (month)', value: props.kpis.avgLosMonth, sub: 'days · non-ICU discharges', icon: 'clock', tone: 'navy' },
    { label: 'Mortality (Month)', value: props.kpis.deathsMonth, sub: 'this calendar month', icon: 'trendDown', tone: 'red' },
]);
const toneClass = {
    brand: 'from-brand-500 to-brand-700', blue: 'from-info-500 to-blue-700', gold: 'from-accent-400 to-accent-600',
    teal: 'from-brand-400 to-brand-600', red: 'from-danger-500 to-danger-600', navy: 'from-navy-700 to-navy-900',
};
const kpiIcons = {
    bed: 'M3 7.5h13.5a3 3 0 0 1 3 3V18M3 7.5V18m0-10.5V6m18 12H3',
    in: 'M3 12h13.5m0 0-4.5-4.5M16.5 12 12 16.5M21 4.5v15',
    chat: 'M8.25 8.25h7.5m-7.5 3.75h4.5m4.94 4.06a8.25 8.25 0 1 0-3.32 2.0L21 21Z',
    gauge: 'M12 3a9 9 0 1 0 9 9M12 12l4.5-4.5M21 12h-2M5 12H3m9-7v2',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    trendDown: 'M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898m0 0 3.182-5.511m-3.182 5.51-5.511-3.181',
};

// PNG-export-only toolbar (no zoom/pan clutter) — applied to every chart on this page
// (the occupancy gauge runs in sparkline mode, which suppresses the toolbar by design)
const dlToolbar = { show: true, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } };

const areaOptions = computed(() => ({
    chart: { type: 'area', toolbar: dlToolbar, fontFamily: 'inherit', animations: { easing: 'easeinout', speed: 600 } },
    colors: [C.teal, C.gold],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 90] } },
    grid: { borderColor: gridColor.value, strokeDashArray: 4, padding: { left: 8, right: 8 } },
    xaxis: { categories: props.trend.labels, type: 'datetime', labels: { style: { colors: axisColor.value }, datetimeFormatter: { day: 'dd MMM' } }, axisBorder: { show: false }, axisTicks: { show: false }, tickAmount: 6 },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { position: 'top', horizontalAlign: 'right', markers: { radius: 12 }, fontWeight: 600 },
    tooltip: { x: { format: 'ddd, dd MMM' } },
}));
const areaSeries = computed(() => [
    { name: 'Admissions', data: props.trend.admissions },
    { name: 'Discharges', data: props.trend.discharges },
]);

const gaugeOptions = computed(() => ({
    chart: { type: 'radialBar', fontFamily: 'inherit', sparkline: { enabled: true } },
    colors: [C.teal],
    plotOptions: { radialBar: {
        hollow: { size: '64%' }, track: { background: gridColor.value, strokeWidth: '100%' },
        dataLabels: { name: { offsetY: 22, color: axisColor.value, fontSize: '12px' }, value: { offsetY: -14, color: inkColor.value, fontSize: '30px', fontWeight: 700, formatter: () => props.kpis.occupancy + '%' } },
    } },
    fill: { type: 'gradient', gradient: { shade: 'dark', type: 'horizontal', gradientToColors: [C.tealLight], stops: [0, 100] } },
    stroke: { lineCap: 'round' },
    labels: ['Occupancy'],
}));

const colOptions = (cats, colors) => ({
    chart: { type: 'bar', toolbar: dlToolbar, fontFamily: 'inherit', stacked: false },
    colors, plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false }, grid: { borderColor: gridColor.value, strokeDashArray: 4 },
    xaxis: { categories: cats, labels: { style: { colors: axisColor.value } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: axisColor.value } } },
    legend: { position: 'top', horizontalAlign: 'right', fontWeight: 600 },
});
const consultsSeries = computed(() => [{ name: 'New', data: props.consults.new }, { name: 'Signed off', data: props.consults.signed }]);
const losSeries = computed(() => [{ name: 'Patients', data: props.los.data }]);

const donutOptions = computed(() => ({
    chart: { type: 'donut', toolbar: dlToolbar, fontFamily: 'inherit' },
    colors: [C.teal, C.gold, C.navy],
    labels: ['Hospitalist', 'Sub-specialty', 'Long-term'],
    legend: { position: 'bottom', fontWeight: 600 },
    dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
    stroke: { width: 2, colors: [strokeColor.value] },
    plotOptions: { pie: { donut: { size: '70%', labels: { show: true, total: { show: true, label: 'Census', color: axisColor.value, formatter: (w) => w.globals.seriesTotals.reduce((a, b) => a + b, 0) } } } } },
}));
const donutSeries = computed(() => [props.mix.hospitalist, props.mix.subspecialty, props.mix.longterm]);

// consultation donut — legacy dashboard/1.php pair: [signed off in the last 24h, active] (J2-5)
const consultDonutOptions = computed(() => ({
    chart: { type: 'donut', toolbar: dlToolbar, fontFamily: 'inherit' },
    colors: [C.gold, C.teal],
    labels: ['Signed off (24h)', 'Active'],
    legend: { position: 'bottom', fontWeight: 600 },
    dataLabels: { enabled: true, formatter: (v, o) => o.w.globals.series[o.seriesIndex] },
    stroke: { width: 2, colors: [strokeColor.value] },
    plotOptions: { pie: { donut: { size: '70%' } } },
}));
const consultDonutSeries = computed(() => [props.consultDonut.signed24h, props.consultDonut.active]);

const consultantMax = computed(() => Math.max(1, ...props.perConsultant.map((c) => c.c)));
const locTone = (loc) => loc === 'ICU' ? 'bg-danger-100 text-danger-600' : loc === 'ER' ? 'bg-warning-100 text-warning-500' : 'bg-brand-100 text-brand-700';

// per-consultant activity since yesterday (grouped bars)
const act24Options = computed(() => colOptions(props.activity24h.map((r) => r.name), [C.blue, C.gold]));
const act24Series = computed(() => [
    { name: 'Admissions', data: props.activity24h.map((r) => r.admissions) },
    { name: 'Discharges', data: props.activity24h.map((r) => r.discharges) },
]);

// YTD counter strip
const ytdCards = computed(() => [
    ['Admissions', props.ytd.admissions], ['Discharges', props.ytd.discharges],
    ['Consultations', props.ytd.consultations], ['Sign-offs', props.ytd.signoffs],
]);

const refresh = () => router.reload({ only: ['kpis', 'trend', 'consults', 'consultDonut', 'los', 'mix', 'donutTotal', 'donutTb', 'perConsultant', 'consultantBoard', 'activity24h', 'ytd', 'topDxWeek', 'topDxWeekNum', 'recent', 'generatedAt'] });

// 5-minute auto-refresh, visibility-gated: a dashboard left open on a ward screen stays
// current, but background tabs don't hammer the server.
let autoRefresh = null;
onMounted(() => {
    autoRefresh = setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 300000);
});
onUnmounted(() => clearInterval(autoRefresh));
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout title="Command Center">
        <!-- sub header -->
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-sm text-ink-500">Internal Medicine · live operational overview</p>
            </div>
            <div class="flex items-center gap-3 text-sm text-ink-400">
                <span class="nums">Updated {{ generatedAt }}</span>
                <button @click="refresh" class="inline-flex items-center gap-2 rounded-xl border border-line bg-card px-3 py-2 font-semibold text-ink-600 shadow-sm transition hover:border-brand-300 hover:text-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992m-4.49-4.51a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m-15.91 8.51 3.181 3.182a8.25 8.25 0 0 0 13.803-3.7" /></svg>
                    Refresh
                </button>
            </div>
        </div>

        <!-- KPI hero row -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
            <div v-for="c in kpiCards" :key="c.label" class="relative overflow-hidden rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ c.label }}</p>
                        <p class="font-display nums mt-2 text-3xl font-extrabold text-ink-900">{{ c.value }}</p>
                        <p class="mt-1 text-xs text-ink-400">{{ c.sub }}</p>
                    </div>
                    <div class="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br text-white shadow-lg" :class="toneClass[c.tone]">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="kpiIcons[c.icon]" /></svg>
                    </div>
                </div>
                <div class="pointer-events-none absolute -bottom-6 -right-4 h-20 w-20 rounded-full bg-gradient-to-br opacity-10" :class="toneClass[c.tone]"></div>
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
                    <h3 class="font-bold text-ink-800">Admissions vs Discharges</h3>
                    <span class="rounded-full bg-ink-50 px-3 py-1 text-xs font-semibold text-ink-500">Last 30 days</span>
                </div>
                <apexchart type="area" height="300" :options="areaOptions" :series="areaSeries" role="img" aria-label="Area chart: admissions versus discharges over the last 30 days" />
            </div>
            <!-- occupancy gauge -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-2 font-bold text-ink-800">Bed Occupancy</h3>
                <apexchart type="radialBar" height="260" :options="gaugeOptions" :series="[kpis.occupancyGauge]" role="img" :aria-label="`Gauge: bed occupancy ${kpis.occupancy}% (${kpis.ward} ward patients of ${kpis.wardBeds} beds)`" />
                <div class="mt-1 grid grid-cols-2 gap-3 text-center">
                    <div class="rounded-xl bg-brand-50 py-2"><p class="nums text-xl font-bold text-brand-700">{{ kpis.ward }}</p><p class="text-xs text-ink-400">Ward</p></div>
                    <div class="rounded-xl bg-danger-100 py-2"><p class="nums text-xl font-bold text-danger-600">{{ kpis.icu }}</p><p class="text-xs text-ink-400">ICU</p></div>
                </div>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-2 font-bold text-ink-800">Consultations</h3>
                <apexchart type="bar" height="260" :options="colOptions(consults.labels, [C.teal, C.gold])" :series="consultsSeries" role="img" aria-label="Bar chart: consultations received and signed off" />
            </div>
            <!-- legacy dashboard/1.php consultation donut: signed off in the last 24h vs active (J2-5) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-2 font-bold text-ink-800">Consultations <span class="font-normal text-ink-400">(24h sign-offs vs active)</span></h3>
                <apexchart type="donut" height="260" :options="consultDonutOptions" :series="consultDonutSeries" role="img" :aria-label="`Donut chart: ${consultDonut.signed24h} consultations signed off in the last 24 hours, ${consultDonut.active} active`" />
            </div>
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-2 font-bold text-ink-800">Length of Stay <span class="font-normal text-ink-400">(this year)</span></h3>
                <apexchart type="bar" height="260" :options="colOptions(los.labels, [C.navy])" :series="losSeries" role="img" aria-label="Bar chart: length-of-stay distribution this year" />
            </div>
            <!-- legacy census donut title carries the headline + TB count over the DONUT'S OWN
                 population (assigned non-ICU — dashboard/1.php:151-154), not the all-active KPI (M1/5) -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-2 font-bold text-ink-800">Current patients: <span class="nums">{{ donutTotal }}</span> <span class="font-normal text-ink-400">(incl. {{ donutTb }} TB)</span></h3>
                <apexchart type="donut" height="260" :options="donutOptions" :series="donutSeries" role="img" :aria-label="`Donut chart: assigned non-ICU census by service — ${donutTotal} patients including ${donutTb} TB`" />
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- per consultant -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-4 font-bold text-ink-800">Active Load by Consultant</h3>
                <div class="space-y-3">
                    <div v-for="c in perConsultant" :key="c.name" class="flex items-center gap-3">
                        <div class="w-40 shrink-0 truncate text-sm font-medium text-ink-600">{{ c.name }}</div>
                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-ink-50">
                            <div class="h-full rounded-full bg-gradient-to-r from-brand-400 to-brand-600" :style="{ width: (c.c / consultantMax * 100) + '%' }"></div>
                        </div>
                        <div class="nums w-8 text-right text-sm font-bold text-ink-800">{{ c.c }}</div>
                    </div>
                    <p v-if="!perConsultant.length" class="text-sm text-ink-400">No active patients assigned.</p>
                </div>
            </div>
            <!-- recent admissions -->
            <div class="rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
                <h3 class="mb-4 font-bold text-ink-800">Recent Admissions</h3>
                <div class="divide-y divide-line">
                    <div v-for="r in recent" :key="r.mrn + r.admitted" class="flex items-center gap-3 py-2.5">
                        <div class="grid h-9 w-9 place-items-center rounded-full bg-ink-50 text-xs font-bold text-ink-500">{{ (r.name || '?').slice(0,2).toUpperCase() }}</div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-ink-800">{{ r.name || 'Unknown' }}</p>
                            <p class="nums truncate text-xs text-ink-400">MRN {{ r.mrn }} · {{ r.consultant || 'Unassigned' }}</p>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="locTone(r.loc)">{{ r.loc || '—' }}</span>
                        <span class="nums hidden text-xs text-ink-400 sm:block">{{ r.admitted }}</span>
                    </div>
                    <p v-if="!recent.length" class="py-2 text-sm text-ink-400">No recent admissions.</p>
                </div>
            </div>
        </div>

        <!-- top diagnoses: this calendar week-number across ALL years (legacy seasonal view) -->
        <div class="mt-5 rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
            <h3 class="mb-4 font-bold text-ink-800">Top diagnoses <span class="font-normal text-ink-400">(week {{ topDxWeekNum }}, all years)</span></h3>
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
        <div v-if="activity24h.length" class="mt-5 rounded-2xl bg-card p-5 shadow-card ring-1 ring-line">
            <h3 class="mb-2 font-bold text-ink-800">Admissions / Discharges per consultant <span class="font-normal text-ink-400">(since yesterday)</span></h3>
            <apexchart type="bar" height="280" :options="act24Options" :series="act24Series" role="img" aria-label="Grouped bar chart: admissions and discharges per consultant since yesterday" />
        </div>

        <!-- per-consultant breakdown table -->
        <div class="mt-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <div class="border-b border-line px-5 py-3"><h3 class="font-bold text-ink-800">Patient count per consultant</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-2.5">Consultant</th><th scope="col" class="px-3 py-2.5 text-center">Old</th><th scope="col" class="px-3 py-2.5 text-center">New</th><th scope="col" class="px-3 py-2.5 text-center">Active</th><th scope="col" class="px-3 py-2.5 text-center">Ward</th><th scope="col" class="px-3 py-2.5 text-center">ICU</th><th scope="col" class="px-3 py-2.5 text-center">TB</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <template v-for="sec in boardSections" :key="sec.key">
                            <tr class="bg-app/70"><td colspan="7" class="px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-ink-500">{{ sec.label }}</td></tr>
                            <tr v-for="c in sec.rows" :key="c.name" class="hover:bg-brand-50/40">
                                <td class="px-5 py-2 font-semibold text-ink-700">Dr. {{ c.name }}</td>
                                <td class="nums px-3 py-2 text-center text-ink-600">{{ c.old || '' }}</td>
                                <td class="nums px-3 py-2 text-center text-info-500">{{ c.new || '' }}</td>
                                <td class="nums px-3 py-2 text-center font-semibold text-brand-700">{{ c.active }}</td>
                                <td class="nums px-3 py-2 text-center text-ink-600">{{ c.ward }}</td>
                                <td class="nums px-3 py-2 text-center text-danger-600">{{ c.icu || '' }}</td>
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
