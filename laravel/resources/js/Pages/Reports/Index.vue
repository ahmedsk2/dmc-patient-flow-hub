<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ year: Number, availableYears: Array, months: Array, totals: Object, avgLos: Number, icuLos: Number, topDx: Array, perConsultant: Array, destinations: Array, perConsultantLos: Array, generatedAt: String });
const year = ref(props.year);
const changeYear = () => router.get('/reports', { year: year.value }, { preserveState: true });
const print = () => window.print();
</script>

<template>
    <Head title="Annual Report" />
    <AppLayout title="Annual Report">
        <!-- toolbar (screen only) -->
        <div class="no-print mb-5 flex flex-wrap items-center gap-3">
            <select v-model="year" @change="changeYear" class="rounded-xl border border-ink-200 bg-card px-4 py-2 text-sm font-semibold outline-none focus:border-brand-500">
                <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
            </select>
            <a :href="`/reports/pdf?year=${year}`" class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                Download PDF
            </a>
            <button @click="print" class="inline-flex items-center gap-2 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-ink-600 shadow ring-1 ring-ink-200 transition hover:bg-ink-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
                Print
            </button>
            <a :href="`/reports/monthly?year=${year}`" class="ml-auto inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">Monthly breakdown →</a>
        </div>

        <!-- A4 document -->
        <div class="report mx-auto max-w-[820px] rounded-2xl bg-card p-10 shadow-card ring-1 ring-line print:rounded-none print:shadow-none print:ring-0">
            <header class="mb-6 flex items-start justify-between border-b-2 border-brand-600 pb-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-navy-900">DMC <span class="text-brand-600">Internal Medicine</span></h1>
                    <p class="text-sm text-ink-500">Annual Activity Report — {{ year }}</p>
                </div>
                <div class="text-right text-xs text-ink-400">
                    <p>Eastern Health Cluster</p><p class="text-brand-600">تجمع الشرقية الصحي</p>
                    <p class="mt-1">Generated {{ generatedAt }}</p>
                </div>
            </header>

            <!-- summary -->
            <div class="mb-6 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                <div v-for="kpi in [['Admissions', totals.admissions],['Discharges', totals.discharges],['ICU', totals.icu],['Mortality %', totals.mortalityRate + '%'],['Ward LOS', avgLos + 'd'],['ICU LOS', icuLos + 'd'],['Long-stay %', totals.lsp + '%']]" :key="kpi[0]"
                    class="rounded-xl bg-app p-3 text-center print:bg-card print:ring-1 print:ring-ink-200">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-ink-400">{{ kpi[0] }}</div>
                    <div class="nums text-xl font-bold text-brand-700">{{ kpi[1] }}</div>
                </div>
            </div>

            <!-- monthly table -->
            <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-navy-800">Monthly breakdown</h2>
            <table class="mb-6 w-full border-collapse text-sm">
                <thead><tr class="bg-navy-900 text-left text-xs font-semibold uppercase tracking-wide text-white print:bg-ink-100 print:text-ink-700">
                    <th scope="col" class="px-3 py-2">Month</th><th scope="col" class="px-3 py-2 text-right">Admissions</th><th scope="col" class="px-3 py-2 text-right">Discharges</th><th scope="col" class="px-3 py-2 text-right">ICU</th><th scope="col" class="px-3 py-2 text-right">Mortality</th><th scope="col" class="px-3 py-2 text-right">Long-stay %</th>
                </tr></thead>
                <tbody>
                    <tr v-for="(m, i) in months" :key="m.label" :class="i % 2 ? 'bg-app/60 print:bg-card' : ''">
                        <td class="border-b border-line px-3 py-1.5 font-medium text-ink-700">{{ m.label }}</td>
                        <td class="nums border-b border-line px-3 py-1.5 text-right">{{ m.admissions }}</td>
                        <td class="nums border-b border-line px-3 py-1.5 text-right">{{ m.discharges }}</td>
                        <td class="nums border-b border-line px-3 py-1.5 text-right">{{ m.icu }}</td>
                        <td class="nums border-b border-line px-3 py-1.5 text-right">{{ m.deaths }}</td>
                        <td class="nums border-b border-line px-3 py-1.5 text-right">{{ m.lsp }}%</td>
                    </tr>
                    <tr class="bg-brand-50 font-bold text-brand-800">
                        <td class="px-3 py-2">Total</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.admissions }}</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.discharges }}</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.icu }}</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.deaths }}</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.lsp }}%</td>
                    </tr>
                </tbody>
            </table>

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-navy-800">Top diagnoses</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="d in topDx" :key="d.code" class="border-b border-ink-50">
                                <td class="py-1.5 text-ink-700">{{ d.name }}</td>
                                <td class="nums py-1.5 text-right font-semibold text-brand-700">{{ d.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-navy-800">Admissions by consultant</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="c in perConsultant" :key="c.name" class="border-b border-ink-50">
                                <td class="py-1.5 text-ink-700">{{ c.name }}</td>
                                <td class="nums py-1.5 text-right font-semibold text-brand-700">{{ c.count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <div>
                    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-navy-800">Discharge destinations</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="d in destinations" :key="d.dest" class="border-b border-ink-50">
                                <td class="py-1.5 text-ink-700">{{ d.dest }}</td>
                                <td class="nums py-1.5 text-right font-semibold text-brand-700">{{ d.count }}</td>
                            </tr>
                            <tr v-if="!destinations.length"><td class="py-2 text-ink-300">No discharges recorded.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-navy-800">Avg ward LOS by consultant</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="c in perConsultantLos" :key="c.name" class="border-b border-ink-50">
                                <td class="py-1.5 text-ink-700">{{ c.name }} <span class="text-ink-300">({{ c.n }})</span></td>
                                <td class="nums py-1.5 text-right font-semibold text-brand-700">{{ c.los }}d</td>
                            </tr>
                            <tr v-if="!perConsultantLos.length"><td class="py-2 text-ink-300">No discharges recorded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <footer class="mt-8 border-t border-line pt-3 text-center text-[11px] text-ink-400">
                DMC Internal Medicine · Patient-Flow Hub · Confidential — contains aggregate clinical data
            </footer>
        </div>
    </AppLayout>
</template>

<style>
@page { size: A4; margin: 14mm; }
@media print {
    aside, header.sticky { display: none !important; }
    [class*="pl-64"] { padding-left: 0 !important; }
    main { padding: 0 !important; }
    body { background: #fff !important; }
    .no-print { display: none !important; }
}
</style>
