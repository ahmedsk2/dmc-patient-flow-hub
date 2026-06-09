<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ range: Object, kpis: Object, monthly: Object, los: Object, topDx: Array, reasons: Object, perConsultant: Array, sourceMix: Array });

const from = ref(props.range.from);
const to = ref(props.range.to);
const apply = () => router.get('/statistics', { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });

const C = { teal: '#009ca6', tealLt: '#7accc9', navy: '#00565e', gold: '#d9a23c', red: '#e0413e', blue: '#2f7fe0', slate: '#5b6a6e', green: '#16a34a' };

const kpiCards = computed(() => [
    { label: 'Admissions', value: props.kpis.admissions, tone: 'brand' },
    { label: 'Discharges', value: props.kpis.discharges, tone: 'info' },
    { label: 'ICU admissions', value: props.kpis.icuAdmissions, tone: 'danger' },
    { label: 'Mortality', value: props.kpis.deaths, sub: props.kpis.mortalityRate + '%', tone: 'ink' },
    { label: 'Avg LOS', value: props.kpis.avgLos, sub: 'days', tone: 'accent' },
    { label: 'Consultations', value: props.kpis.consultations, tone: 'brand' },
    { label: 'Sign-offs', value: props.kpis.signoffs, tone: 'info' },
    { label: '72h readmits', value: props.kpis.readmissions, tone: 'danger' },
]);
const toneClass = (t) => ({
    brand: 'text-brand-700', info: 'text-info-500', danger: 'text-danger-600', accent: 'text-accent-600', ink: 'text-ink-700',
}[t]);

const monthlyChart = {
    chart: { type: 'area', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: [C.teal, C.blue, C.red], stroke: { width: [3, 3, 2], curve: 'smooth' },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
    dataLabels: { enabled: false }, legend: { position: 'top', horizontalAlign: 'right' },
    xaxis: { categories: props.monthly.labels, labels: { style: { colors: '#94a3b8' } } },
    yaxis: { labels: { style: { colors: '#94a3b8' } } }, grid: { borderColor: '#eef2f6' },
};
const monthlySeries = [
    { name: 'Admissions', data: props.monthly.admissions },
    { name: 'Discharges', data: props.monthly.discharges },
    { name: 'Mortality', data: props.monthly.deaths },
];

const barChart = (cats, color, horizontal = false) => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: [color], plotOptions: { bar: { horizontal, borderRadius: 6, columnWidth: '55%', barHeight: '65%' } },
    dataLabels: { enabled: false }, xaxis: { categories: cats, labels: { style: { colors: '#94a3b8' } } },
    yaxis: { labels: { style: { colors: '#94a3b8' }, maxWidth: 200 } }, grid: { borderColor: '#eef2f6' },
});
const donut = (labels) => ({
    chart: { type: 'donut', fontFamily: 'inherit' }, labels,
    colors: [C.teal, C.gold, C.blue, C.navy, C.tealLt, C.slate],
    legend: { position: 'bottom' }, dataLabels: { enabled: true }, stroke: { width: 0 },
    plotOptions: { pie: { donut: { size: '68%' } } },
});
</script>

<template>
    <Head title="Statistics" />
    <AppLayout title="Statistics">
        <!-- range -->
        <div class="mb-5 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-card ring-1 ring-ink-100/60">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">From</label>
                <input v-model="from" type="date" class="rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">To</label>
                <input v-model="to" type="date" class="rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" />
            </div>
            <button @click="apply" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">Apply</button>
            <span class="ml-auto text-sm text-ink-400">{{ range.from }} → {{ range.to }}</span>
        </div>

        <!-- KPIs -->
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4 xl:grid-cols-8">
            <div v-for="k in kpiCards" :key="k.label" class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-ink-100/60">
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-400">{{ k.label }}</div>
                <div class="mt-1 flex items-baseline gap-1">
                    <span class="nums text-2xl font-bold" :class="toneClass(k.tone)">{{ k.value }}</span>
                    <span v-if="k.sub" class="text-xs text-ink-400">{{ k.sub }}</span>
                </div>
            </div>
        </div>

        <!-- charts -->
        <div class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60 lg:col-span-2">
                <h3 class="mb-3 font-bold text-ink-800">Monthly admissions, discharges & mortality</h3>
                <apexchart type="area" height="300" :options="monthlyChart" :series="monthlySeries" />
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Length of stay distribution</h3>
                <apexchart type="bar" height="280" :options="barChart(los.labels, C.teal)" :series="[{ name: 'Discharges', data: los.data }]" />
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Admission source</h3>
                <apexchart type="donut" height="280" :options="donut(sourceMix.map(s => s.src))" :series="sourceMix.map(s => s.c)" />
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Top diagnoses</h3>
                <apexchart type="bar" height="320" :options="barChart(topDx.map(d => d.label), C.navy, true)" :series="[{ name: 'Admissions', data: topDx.map(d => d.value) }]" />
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Consultation indications</h3>
                <apexchart type="bar" height="320" :options="barChart(reasons.labels, C.gold, true)" :series="[{ name: 'Consultations', data: reasons.data }]" />
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60 lg:col-span-2">
                <h3 class="mb-3 font-bold text-ink-800">Admissions by consultant</h3>
                <apexchart type="bar" height="340" :options="barChart(perConsultant.map(c => c.name), C.tealLt, true)" :series="[{ name: 'Admissions', data: perConsultant.map(c => c.c) }]" />
            </div>
        </div>
    </AppLayout>
</template>
