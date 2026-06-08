<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    kpis: Object,
    trend: Object,
    consults: Object,
    los: Object,
    mix: Object,
    perConsultant: Array,
    recent: Array,
    generatedAt: String,
});

const C = { teal: '#0d8a85', tealLight: '#2dbcb8', navy: '#173049', gold: '#efab23', pink: '#cf4b8f', red: '#e0413e', blue: '#2f7fe0', slate: '#94a3b5' };

const kpiCards = computed(() => [
    { label: 'Active Census', value: props.kpis.census, sub: `${props.kpis.ward} ward · ${props.kpis.icu} ICU`, icon: 'bed', tone: 'brand' },
    { label: 'Admissions Today', value: props.kpis.admissionsToday, sub: `${props.kpis.dischargesToday} discharged today`, icon: 'in', tone: 'blue' },
    { label: 'Active Consultations', value: props.kpis.activeConsults, sub: 'awaiting sign-off', icon: 'chat', tone: 'gold' },
    { label: 'Bed Occupancy', value: props.kpis.occupancy + '%', sub: 'hospitalist capacity', icon: 'gauge', tone: 'teal' },
    { label: 'Mortality (Month)', value: props.kpis.deathsMonth, sub: 'this calendar month', icon: 'heart', tone: 'red' },
]);
const toneClass = {
    brand: 'from-brand-500 to-brand-700', blue: 'from-info-500 to-blue-700', gold: 'from-accent-400 to-accent-600',
    teal: 'from-brand-400 to-brand-600', red: 'from-danger-500 to-danger-600',
};
const kpiIcons = {
    bed: 'M3 7.5h13.5a3 3 0 0 1 3 3V18M3 7.5V18m0-10.5V6m18 12H3',
    in: 'M3 12h13.5m0 0-4.5-4.5M16.5 12 12 16.5M21 4.5v15',
    chat: 'M8.25 8.25h7.5m-7.5 3.75h4.5m4.94 4.06a8.25 8.25 0 1 0-3.32 2.0L21 21Z',
    gauge: 'M12 3a9 9 0 1 0 9 9M12 12l4.5-4.5M21 12h-2M5 12H3m9-7v2',
    heart: 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z',
};

const areaOptions = computed(() => ({
    chart: { type: 'area', toolbar: { show: false }, fontFamily: 'inherit', animations: { easing: 'easeinout', speed: 600 } },
    colors: [C.teal, C.gold],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 90] } },
    grid: { borderColor: '#eef2f6', strokeDashArray: 4, padding: { left: 8, right: 8 } },
    xaxis: { categories: props.trend.labels, type: 'datetime', labels: { style: { colors: '#94a3b5' }, datetimeFormatter: { day: 'dd MMM' } }, axisBorder: { show: false }, axisTicks: { show: false }, tickAmount: 6 },
    yaxis: { labels: { style: { colors: '#94a3b5' } } },
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
        hollow: { size: '64%' }, track: { background: '#e6edf3', strokeWidth: '100%' },
        dataLabels: { name: { offsetY: 22, color: '#94a3b5', fontSize: '12px' }, value: { offsetY: -14, color: '#0f172a', fontSize: '30px', fontWeight: 700, formatter: (v) => Math.round(v) + '%' } },
    } },
    fill: { type: 'gradient', gradient: { shade: 'dark', type: 'horizontal', gradientToColors: [C.tealLight], stops: [0, 100] } },
    stroke: { lineCap: 'round' },
    labels: ['Occupancy'],
}));

const colOptions = (cats, colors) => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', stacked: false },
    colors, plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false }, grid: { borderColor: '#eef2f6', strokeDashArray: 4 },
    xaxis: { categories: cats, labels: { style: { colors: '#94a3b5' } }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { style: { colors: '#94a3b5' } } },
    legend: { position: 'top', horizontalAlign: 'right', fontWeight: 600 },
});
const consultsSeries = computed(() => [{ name: 'New', data: props.consults.new }, { name: 'Signed off', data: props.consults.signed }]);
const losSeries = computed(() => [{ name: 'Patients', data: props.los.data }]);

const donutOptions = computed(() => ({
    chart: { type: 'donut', fontFamily: 'inherit' },
    colors: [C.teal, C.gold, C.navy],
    labels: ['Hospitalist', 'Sub-specialty', 'Long-term'],
    legend: { position: 'bottom', fontWeight: 600 },
    dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
    stroke: { width: 2, colors: ['#fff'] },
    plotOptions: { pie: { donut: { size: '70%', labels: { show: true, total: { show: true, label: 'Census', color: '#94a3b5', formatter: (w) => w.globals.seriesTotals.reduce((a, b) => a + b, 0) } } } } },
}));
const donutSeries = computed(() => [props.mix.hospitalist, props.mix.subspecialty, props.mix.longterm]);

const consultantMax = computed(() => Math.max(1, ...props.perConsultant.map((c) => c.c)));
const locTone = (loc) => loc === 'ICU' ? 'bg-danger-100 text-danger-600' : loc === 'ER' ? 'bg-warning-100 text-warning-500' : 'bg-brand-100 text-brand-700';

const refresh = () => router.reload({ only: ['kpis', 'trend', 'consults', 'los', 'mix', 'perConsultant', 'recent', 'generatedAt'] });
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
                <button @click="refresh" class="inline-flex items-center gap-2 rounded-xl border border-ink-100 bg-white px-3 py-2 font-semibold text-ink-600 shadow-sm transition hover:border-brand-300 hover:text-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992m-4.49-4.51a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m-15.91 8.51 3.181 3.182a8.25 8.25 0 0 0 13.803-3.7" /></svg>
                    Refresh
                </button>
            </div>
        </div>

        <!-- KPI hero row -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
            <div v-for="c in kpiCards" :key="c.label" class="relative overflow-hidden rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ c.label }}</p>
                        <p class="nums mt-2 text-3xl font-extrabold text-ink-900">{{ c.value }}</p>
                        <p class="mt-1 text-xs text-ink-400">{{ c.sub }}</p>
                    </div>
                    <div class="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br text-white shadow-lg" :class="toneClass[c.tone]">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="kpiIcons[c.icon]" /></svg>
                    </div>
                </div>
                <div class="pointer-events-none absolute -bottom-6 -right-4 h-20 w-20 rounded-full bg-gradient-to-br opacity-10" :class="toneClass[c.tone]"></div>
            </div>
        </div>

        <!-- charts grid -->
        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
            <!-- trend (2/3) -->
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60 lg:col-span-2">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="font-bold text-ink-800">Admissions vs Discharges</h3>
                    <span class="rounded-full bg-ink-50 px-3 py-1 text-xs font-semibold text-ink-500">Last 30 days</span>
                </div>
                <apexchart type="area" height="300" :options="areaOptions" :series="areaSeries" />
            </div>
            <!-- occupancy gauge -->
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-2 font-bold text-ink-800">Bed Occupancy</h3>
                <apexchart type="radialBar" height="260" :options="gaugeOptions" :series="[kpis.occupancy]" />
                <div class="mt-1 grid grid-cols-2 gap-3 text-center">
                    <div class="rounded-xl bg-brand-50 py-2"><p class="nums text-xl font-bold text-brand-700">{{ kpis.ward }}</p><p class="text-xs text-ink-400">Ward</p></div>
                    <div class="rounded-xl bg-danger-100 py-2"><p class="nums text-xl font-bold text-danger-600">{{ kpis.icu }}</p><p class="text-xs text-ink-400">ICU</p></div>
                </div>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-2 font-bold text-ink-800">Consultations</h3>
                <apexchart type="bar" height="260" :options="colOptions(consults.labels, [C.teal, C.gold])" :series="consultsSeries" />
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-2 font-bold text-ink-800">Length of Stay <span class="font-normal text-ink-400">(this year)</span></h3>
                <apexchart type="bar" height="260" :options="colOptions(los.labels, [C.navy])" :series="losSeries" />
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-2 font-bold text-ink-800">Census by Service</h3>
                <apexchart type="donut" height="260" :options="donutOptions" :series="donutSeries" />
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- per consultant -->
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
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
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-4 font-bold text-ink-800">Recent Admissions</h3>
                <div class="divide-y divide-ink-50">
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
    </AppLayout>
</template>
