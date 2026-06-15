<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ availableYears: Array });

const periodType = ref('month');
const year = ref(props.availableYears?.[0] ?? new Date().getFullYear());
const month = ref(new Date().getMonth() + 1);
const quarter = ref(Math.floor(new Date().getMonth() / 3) + 1);
const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// download href reflects the current selection (the PDF is the deliverable — no live charts)
const href = computed(() => '/reports/governance/pdf?' + new URLSearchParams({
    period_type: periodType.value,
    year: year.value,
    ...(periodType.value === 'month' ? { month: month.value } : { quarter: quarter.value }),
}).toString());
const fld = 'rounded-xl border border-ink-200 bg-card px-4 py-2 text-sm font-semibold outline-none focus:border-brand-500';
</script>

<template>
    <AppLayout title="Governance / M&M Pack" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Analytics & Reports' },
        { label: 'Reports', href: '/reports' },
        { label: 'M&M Pack' },
    ]">
        <div class="mx-auto max-w-2xl rounded-2xl bg-card p-8 shadow-card ring-1 ring-line">
            <h1 class="text-xl font-bold text-ink-900">Morbidity &amp; Mortality Pack</h1>
            <p class="mt-1 text-sm text-ink-500">A de-identified governance review for a chosen month or quarter — headline safety KPIs, a period trend, and line lists of every death and every readmission. MRN is included as the clinical identifier; patient names are never shown.</p>

            <div class="mt-6 space-y-5">
                <div class="flex gap-2 rounded-xl bg-app p-1 ring-1 ring-line w-fit">
                    <label v-for="t in [['month','Month'],['quarter','Quarter']]" :key="t[0]" class="cursor-pointer rounded-lg px-4 py-1.5 text-sm font-semibold transition" :class="periodType === t[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">
                        <input type="radio" class="hidden" :value="t[0]" v-model="periodType" /> {{ t[1] }}
                    </label>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">Year</label>
                        <select v-model="year" :class="fld"><option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option></select>
                    </div>
                    <div v-if="periodType === 'month'">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">Month</label>
                        <select v-model="month" :class="fld"><option v-for="(n, i) in monthNames" :key="i" :value="i + 1">{{ n }}</option></select>
                    </div>
                    <div v-else>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">Quarter</label>
                        <select v-model="quarter" :class="fld"><option v-for="q in [1,2,3,4]" :key="q" :value="q">Q{{ q }}</option></select>
                    </div>
                    <a :href="href" class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        Download M&amp;M pack PDF
                    </a>
                </div>
            </div>

            <div class="mt-8 border-t border-line pt-4">
                <Link href="/reports" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline">← Back to reports</Link>
            </div>
        </div>
    </AppLayout>
</template>
