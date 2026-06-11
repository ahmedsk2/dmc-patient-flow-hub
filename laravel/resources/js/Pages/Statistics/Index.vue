<script setup>
import { ref, computed, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ range: Object, kpis: Object, monthly: Object, los: Object, topDx: Array, reasons: Object, perConsultant: Array, sourceMix: Array, kpiGrid: Array, interval: String, truncated: Boolean, destinations: Object, destByConsultant: Array, readmitWindow: Number, consultants: Array, physician: Object });

const from = ref(props.range.from);
const to = ref(props.range.to);
const interval = ref(props.interval || 'month');
// per-physician drill-down selection (server-computed prop; selection round-trips via the URL)
const physChoice = ref(props.physician?.id ?? '');
const apply = () => router.get('/statistics', {
    from: from.value, to: to.value, interval: interval.value,
    ...(physChoice.value ? { consultant_id: physChoice.value } : {}),
}, { preserveState: true, preserveScroll: true });
const setInterval2 = (i) => { interval.value = i; apply(); };

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

const C = { teal: '#009ca6', tealLt: '#7accc9', navy: '#00565e', gold: '#d9a23c', red: '#e0413e', blue: '#2f7fe0', slate: '#5b6a6e', green: '#16a34a' };

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
    brand: 'text-brand-700', info: 'text-info-500', danger: 'text-danger-600', accent: 'text-accent-600', ink: 'text-ink-700',
}[t]);

// PNG-export-only toolbar (no zoom/pan clutter) — applied to every chart on this page
const dlToolbar = { show: true, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } };

// computed (not plain consts): Apply/interval re-fetches with preserveState, so props change
// in place — every prop-derived chart input must be reactive or the chart keeps stale data
//
// day interval: Fri/Sat ticks (Saudi weekend — D4) tinted amber from the raw bucket keys
const tickColors = computed(() => props.interval !== 'day'
    ? '#94a3b8'
    : (props.monthly.keys || []).map((k) => {
        const dow = new Date(`${k}T00:00:00Z`).getUTCDay();   // 5 = Fri, 6 = Sat
        return dow === 5 || dow === 6 ? '#d9a23c' : '#94a3b8';
    }));
const monthlyChart = computed(() => ({
    chart: { type: 'area', toolbar: dlToolbar, fontFamily: 'inherit' },
    colors: [C.teal, C.blue, C.red, C.gold, C.slate], stroke: { width: [3, 3, 2, 2, 2], curve: 'smooth' },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
    dataLabels: { enabled: false }, legend: { position: 'top', horizontalAlign: 'right' },
    xaxis: { categories: props.monthly.labels, labels: { style: { colors: tickColors.value } } },
    yaxis: { labels: { style: { colors: '#94a3b8' } } }, grid: { borderColor: '#eef2f6' },
}));
const monthlySeries = computed(() => [
    { name: 'Admissions', data: props.monthly.admissions },
    { name: 'Discharges', data: props.monthly.discharges },
    { name: 'Mortality', data: props.monthly.deaths },
    // extra context series — toggle off via the legend if noisy
    { name: 'Consultations', data: props.monthly.consultations },
    { name: 'Sign-offs', data: props.monthly.signoffs },
]);

// KPI-grid header tracks the selected interval + actual applied range
const gridTitle = computed(() => ({ day: 'Daily', quarter: 'Quarterly' }[props.interval] ?? 'Monthly') + ' KPI grid');

// per-consultant chart KPI modes (Admissions | Avg LOS | window readmissions)
const consMode = ref('admissions');
const consModes = computed(() => [
    ['admissions', 'Admissions'],
    ['avgLos', 'Avg LOS'],
    ['readmits', `${props.readmitWindow ?? 3}d readmits`],
]);
const consModeColor = computed(() => ({ admissions: C.tealLt, avgLos: C.gold, readmits: C.red }[consMode.value]));
const consCats = computed(() => props.perConsultant.map((c) => c.name));
const consSeries = computed(() => [{
    name: consModes.value.find((m) => m[0] === consMode.value)?.[1] ?? '',
    data: props.perConsultant.map((c) => c[consMode.value]),
}]);
// the list is no longer capped at 10 — grow the chart with the roster so bars stay readable
const consHeight = computed(() => Math.max(340, props.perConsultant.length * 28 + 60));

// drill-down (computed, like every prop-derived chart input — see note above)
const physDonutSeries = computed(() => props.physician?.destinations?.data ?? []);
const physDonutOptions = computed(() => donut(props.physician?.destinations?.labels ?? []));
const physHasDischarges = computed(() => physDonutSeries.value.some((v) => v > 0));
const physNumbers = computed(() => props.physician ? [
    ['Admissions', props.physician.numbers.admissions],
    ['Discharges', props.physician.numbers.discharges],
    ['Deaths', props.physician.numbers.deaths],
    ['Avg LOS', props.physician.numbers.avgLos + 'd'],
    [`≤${props.readmitWindow ?? 3}d readmits`, props.physician.numbers.readmissions],
] : []);

const barChart = (cats, color, horizontal = false) => ({
    chart: { type: 'bar', toolbar: dlToolbar, fontFamily: 'inherit' },
    colors: [color], plotOptions: { bar: { horizontal, borderRadius: 6, columnWidth: '55%', barHeight: '65%' } },
    dataLabels: { enabled: false }, xaxis: { categories: cats, labels: { style: { colors: '#94a3b8' } } },
    yaxis: { labels: { style: { colors: '#94a3b8' }, maxWidth: 200 } }, grid: { borderColor: '#eef2f6' },
});
const donut = (labels) => ({
    chart: { type: 'donut', toolbar: dlToolbar, fontFamily: 'inherit' }, labels,
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
            <div class="flex items-end gap-1">
                <div class="flex gap-1 rounded-xl bg-surface p-1 ring-1 ring-ink-100">
                    <button v-for="iv in [['day','Daily'],['month','Monthly'],['quarter','Quarterly']]" :key="iv[0]" @click="setInterval2(iv[0])" class="rounded-lg px-3 py-1.5 text-sm font-semibold transition" :class="interval === iv[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ iv[1] }}</button>
                </div>
            </div>
            <span class="ml-auto text-sm text-ink-400">{{ range.from }} → {{ range.to }}</span>
        </div>

        <!-- day-interval cap notice -->
        <div v-if="truncated" class="mb-5 flex items-start gap-2 rounded-xl bg-warning-100 px-4 py-3 text-sm font-medium text-warning-500 ring-1 ring-warning-500/20" role="alert">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <span>Daily view is capped at ~370 days — the chart and grid stop before {{ range.to }}. Switch to <button @click="setInterval2('month')" class="font-bold underline underline-offset-2 hover:opacity-80">Monthly</button> to cover the whole range.</span>
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
                <h3 class="mb-3 font-bold text-ink-800">{{ interval === 'day' ? 'Daily' : interval === 'quarter' ? 'Quarterly' : 'Monthly' }} admissions, discharges, mortality & consultations <span v-if="interval === 'day'" class="text-xs font-normal text-accent-600">(Fri/Sat ticks in amber)</span></h3>
                <apexchart role="img" aria-label="Statistics chart (data also shown in the period table below)" type="area" height="300" :options="monthlyChart" :series="monthlySeries" />
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Length of stay distribution</h3>
                <apexchart role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" height="280" :options="barChart(los.labels, C.teal)" :series="[{ name: 'Discharges', data: los.data }]" />
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Admission source</h3>
                <apexchart role="img" aria-label="Statistics chart (data also shown in the period table below)" type="donut" height="280" :options="donut(sourceMix.map(s => s.src))" :series="sourceMix.map(s => s.c)" />
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="font-bold text-ink-800">Discharge destinations</h3>
                    <select v-model="destChoice" class="max-w-[55%] truncate rounded-lg border border-ink-200 px-2 py-1 text-xs outline-none focus:border-brand-500">
                        <option value="">All consultants</option>
                        <option v-for="c in destByConsultant" :key="c.name" :value="c.name">{{ c.name }}</option>
                    </select>
                </div>
                <apexchart role="img" aria-label="Statistics chart (data also shown in the period table below)" v-if="destDonut.data.some((v) => v > 0)" type="donut" height="280" :options="donut(destDonut.labels)" :series="destDonut.data" />
                <p v-else class="py-10 text-center text-sm text-ink-300">{{ destChoice ? 'No discharges for this consultant in range.' : 'No discharges in range.' }}</p>
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Top diagnoses</h3>
                <apexchart role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" height="320" :options="barChart(topDx.map(d => d.label), C.navy, true)" :series="[{ name: 'Admissions', data: topDx.map(d => d.value) }]" />
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60">
                <h3 class="mb-3 font-bold text-ink-800">Consultation indications</h3>
                <apexchart role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" height="320" :options="barChart(reasons.labels, C.gold, true)" :series="[{ name: 'Consultations', data: reasons.data }]" />
            </div>

            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60 lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-bold text-ink-800">By consultant</h3>
                    <div class="flex gap-1 rounded-xl bg-surface p-1 ring-1 ring-ink-100">
                        <button v-for="m in consModes" :key="m[0]" @click="consMode = m[0]" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition" :class="consMode === m[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ m[1] }}</button>
                    </div>
                </div>
                <apexchart role="img" aria-label="Statistics chart (data also shown in the period table below)" type="bar" :height="consHeight" :options="barChart(consCats, consModeColor, true)" :series="consSeries" />
            </div>

            <!-- per-physician drill-down -->
            <div class="rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60 lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-bold text-ink-800">Physician drill-down</h3>
                    <select v-model="physChoice" @change="apply" aria-label="Drill-down consultant" class="max-w-[55%] truncate rounded-lg border border-ink-200 px-2 py-1.5 text-sm outline-none focus:border-brand-500">
                        <option value="">Select a consultant…</option>
                        <option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div v-if="physician" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <div v-for="n in physNumbers" :key="n[0]" class="rounded-xl bg-surface p-3 ring-1 ring-ink-100/60">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-400">{{ n[0] }}</div>
                            <div class="nums mt-0.5 text-xl font-bold text-ink-800">{{ n[1] }}</div>
                        </div>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-ink-600">Discharge destinations</h4>
                            <apexchart v-if="physHasDischarges" role="img" :aria-label="`Discharge destinations for ${physician.name}`" type="donut" height="260" :options="physDonutOptions" :series="physDonutSeries" />
                            <p v-else class="py-10 text-center text-sm text-ink-300">No closed episodes in range.</p>
                        </div>
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-ink-600">Top diagnoses</h4>
                            <ol v-if="physician.topDx.length" class="space-y-1.5">
                                <li v-for="(d, i) in physician.topDx" :key="d.label" class="flex items-center justify-between gap-2 rounded-lg bg-surface px-3 py-2 text-sm ring-1 ring-ink-100/60">
                                    <span class="text-ink-700"><span class="nums mr-2 font-bold text-brand-700">{{ i + 1 }}.</span>{{ d.label }}</span>
                                    <span class="nums font-semibold text-ink-500">{{ d.value }}</span>
                                </li>
                            </ol>
                            <p v-else class="py-10 text-center text-sm text-ink-300">No diagnoses recorded in range.</p>
                        </div>
                    </div>
                </div>
                <p v-else class="py-8 text-center text-sm text-ink-300">Pick a consultant to see their destinations, top diagnoses and KPIs for this range.</p>
            </div>

            <div class="overflow-hidden rounded-2xl bg-white p-5 shadow-card ring-1 ring-ink-100/60 lg:col-span-2">
                <h3 class="mb-3 font-bold text-ink-800">{{ gridTitle }} <span class="nums text-sm font-normal text-ink-400">({{ range.from }} – {{ range.to }})</span></h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                            <th scope="col" class="px-3 py-2">Period</th><th scope="col" class="px-3 py-2 text-right">Adm</th><th scope="col" class="px-3 py-2 text-right">Disch</th><th scope="col" class="px-3 py-2 text-right">ICU adm</th><th scope="col" class="px-3 py-2 text-right">→ICU</th><th scope="col" class="px-3 py-2 text-right">ICU mort</th><th scope="col" class="px-3 py-2 text-right">Ward mort</th><th scope="col" class="px-3 py-2 text-right">Readm</th><th scope="col" class="px-3 py-2 text-right">Consults</th><th scope="col" class="px-3 py-2 text-right">Sign-offs</th><th scope="col" class="px-3 py-2 text-right">Avg LOS</th>
                        </tr></thead>
                        <tbody class="divide-y divide-ink-50">
                            <tr v-for="r in kpiGrid" :key="r.label" class="hover:bg-brand-50/40">
                                <td class="px-3 py-1.5 font-medium text-ink-700">{{ r.label }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.admissions }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.discharges }}</td>
                                <td class="nums px-3 py-1.5 text-right text-danger-600">{{ r.icu }}</td>
                                <td class="nums px-3 py-1.5 text-right text-danger-600">{{ r.transToIcu }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.icuDeaths }}</td>
                                <td class="nums px-3 py-1.5 text-right">{{ r.wardDeaths }}</td>
                                <td class="nums px-3 py-1.5 text-right text-accent-600">{{ r.readmits }}</td>
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
