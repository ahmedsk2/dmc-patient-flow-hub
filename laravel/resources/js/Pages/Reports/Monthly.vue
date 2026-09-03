<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ year: Number, month: Number, monthName: String, days: Array, totals: Object, generatedAt: String, availableYears: Array });
const year = ref(props.year);
const month = ref(props.month);
const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const change = () => router.get('/reports/monthly', { year: year.value, month: month.value }, { preserveState: true });
const print = () => window.print();

// §3.6: offer a queued (background) generation path for the heavy current-year full booklet
// (12-month × per-day chart data). Client heuristic: current year and >6 months elapsed.
const now = new Date();
const heavyBooklet = computed(() => Number(year.value) === now.getFullYear() && now.getMonth() >= 6);
const generateAsync = () => router.get('/reports/monthly/pdf', { year: year.value, async: 1 }, { preserveState: true, preserveScroll: true });
</script>

<template>
    <AppLayout :title="`Monthly Report — ${monthName} ${year}`">
        <div class="no-print mb-5 flex flex-wrap items-center gap-3">
            <select v-model="month" @change="change" class="rounded-xl border border-ink-200 bg-card px-4 py-2 text-sm font-semibold outline-none focus:border-brand-500">
                <option v-for="(n, i) in monthNames" :key="i" :value="i + 1">{{ n }}</option>
            </select>
            <select v-model="year" @change="change" class="rounded-xl border border-ink-200 bg-card px-4 py-2 text-sm font-semibold outline-none focus:border-brand-500">
                <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
            </select>
            <a :href="`/reports/monthly/pdf?year=${year}&month=${month}`" class="inline-flex items-center gap-2 rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-solid-hover">Download PDF</a>
            <!-- §3.6: queue the heavy full-year booklet and get notified when ready -->
            <button v-if="heavyBooklet" @click="generateAsync" class="inline-flex items-center gap-2 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-brand-700 shadow ring-1 ring-brand-200 transition hover:bg-brand-50" title="Generate the full-year booklet in the background and notify when ready">Generate in background</button>
            <button @click="print" class="inline-flex items-center gap-2 rounded-xl bg-card px-4 py-2 text-sm font-semibold text-ink-600 shadow ring-1 ring-ink-200 transition hover:bg-ink-50">Print</button>
            <Link href="/reports" class="ml-auto inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">← Annual report</Link>
        </div>

        <div class="report mx-auto max-w-[820px] rounded-2xl bg-card p-10 shadow-card ring-1 ring-line print:rounded-none print:shadow-none print:ring-0">
            <header class="mb-6 flex items-start justify-between border-b-2 border-brand-600 pb-4">
                <div>
                    <!-- h2, not h1: AppLayout already renders the page's single h1 (UX-03) -->
                    <h2 class="text-2xl font-extrabold text-navy-900">DMC <span class="text-brand-600">Internal Medicine</span></h2>
                    <p class="text-sm text-ink-500">Monthly Activity Report — {{ monthName }} {{ year }}</p>
                </div>
                <div class="text-right text-xs text-ink-400">
                    <p>Eastern Health Cluster</p><p class="text-brand-600">تجمع الشرقية الصحي</p><p class="mt-1">Generated {{ generatedAt }}</p>
                </div>
            </header>

            <div class="mb-6 grid grid-cols-4 gap-3">
                <div v-for="kpi in [['Admissions', totals.admissions],['Discharges', totals.discharges],['ICU', totals.icu],['Mortality', totals.deaths]]" :key="kpi[0]" class="rounded-xl bg-app p-3 text-center print:bg-card print:ring-1 print:ring-ink-200">
                    <div class="text-[10px] font-semibold uppercase tracking-wide text-ink-400">{{ kpi[0] }}</div>
                    <div class="nums text-xl font-bold text-brand-700">{{ kpi[1] }}</div>
                </div>
            </div>

            <table class="w-full border-collapse text-sm">
                <thead><tr class="bg-navy-900 text-left text-xs font-semibold uppercase tracking-wide text-white print:bg-ink-100 print:text-ink-700">
                    <th scope="col" class="px-3 py-2">Day</th><th scope="col" class="px-3 py-2 text-right">Admissions</th><th scope="col" class="px-3 py-2 text-right">Discharges</th><th scope="col" class="px-3 py-2 text-right">ICU</th><th scope="col" class="px-3 py-2 text-right">Mortality</th>
                </tr></thead>
                <tbody>
                    <!-- weekend = Friday/Saturday (D4 — Saudi work week), matching the report weekend-discharge metric -->
                    <tr v-for="(d, i) in days" :key="d.day" :class="['Fri','Sat'].includes(d.weekday) ? 'bg-accent-300/15' : (i % 2 ? 'bg-app/60 print:bg-card' : '')">
                        <td class="border-b border-line px-3 py-1 font-medium text-ink-700">{{ d.weekday }} {{ d.day }}</td>
                        <td class="nums border-b border-line px-3 py-1 text-right">{{ d.admissions }}</td>
                        <td class="nums border-b border-line px-3 py-1 text-right">{{ d.discharges }}</td>
                        <td class="nums border-b border-line px-3 py-1 text-right">{{ d.icu }}</td>
                        <td class="nums border-b border-line px-3 py-1 text-right">{{ d.deaths }}</td>
                    </tr>
                    <tr class="bg-brand-50 font-bold text-brand-800">
                        <td class="px-3 py-2">Total</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.admissions }}</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.discharges }}</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.icu }}</td>
                        <td class="nums px-3 py-2 text-right">{{ totals.deaths }}</td>
                    </tr>
                </tbody>
            </table>

            <footer class="mt-8 border-t border-line pt-3 text-center text-[11px] text-ink-400">DMC Internal Medicine · Patient-Flow Hub · Confidential</footer>
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
