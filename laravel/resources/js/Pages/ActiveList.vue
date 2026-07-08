<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PatientFlags from '@/Components/PatientFlags.vue';
import { locTone } from '@/lib/ui.js';

// Printable read-only census board — UNSCOPED (every consultant, for every role: the legacy
// active-list.php whole-ward census), every group expanded, diagnosis names listed, no action
// buttons, headed by the legacy "Patient Count per Consultant" table incl. the OFF-service section.
const props = defineProps({ groups: Array, readmitWindow: Number, generatedAt: String });

const totals = props.groups.reduce((t, g) => t + g.counts.total, 0);
const wardTotal = props.groups.reduce((t, g) => t + g.counts.ward, 0);

// counts-table sections: on-service hospitalists → on-service subspecialists → OFF-service
const bucket = (g) => g.on_service && g.specialty_id === 1 ? 'hosp' : g.on_service ? 'subs' : 'off';
const sections = [
    { key: 'hosp', label: 'On-service · Hospitalists', rows: props.groups.filter((g) => bucket(g) === 'hosp') },
    { key: 'subs', label: 'On-service · Subspecialists', rows: props.groups.filter((g) => bucket(g) === 'subs') },
    { key: 'off', label: 'OFF Service', rows: props.groups.filter((g) => bucket(g) === 'off') },
].filter((s) => s.rows.length);

const print = () => window.print();
</script>

<template>
    <AppLayout title="Active List">
        <!-- toolbar (screen only) -->
        <div class="no-print mb-5 flex flex-wrap items-center gap-3">
            <button @click="print" class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
                Print
            </button>
            <span class="text-sm text-ink-400">Read-only census board — formatted for printing.</span>
        </div>

        <!-- printable document -->
        <div class="report mx-auto max-w-[1000px] rounded-2xl bg-card p-8 shadow-card ring-1 ring-line print:rounded-none print:shadow-none print:ring-0">
            <header class="mb-5 flex items-start justify-between border-b-2 border-brand-600 pb-3">
                <div>
                    <h1 class="text-2xl font-extrabold text-navy-900">DMC <span class="text-brand-600">Internal Medicine</span></h1>
                    <p class="text-sm text-ink-500">Active Patient Census Board</p>
                </div>
                <div class="text-right text-xs text-ink-400">
                    <p>Eastern Health Cluster</p><p class="text-brand-600">تجمع الشرقية الصحي</p>
                    <p class="mt-1">Generated {{ generatedAt }} · <span class="nums font-semibold text-ink-600">{{ totals }}</span> patient(s) · <span class="nums font-semibold text-ink-600">{{ wardTotal }}</span> non-ICU</p>
                </div>
            </header>

            <!-- legacy "Patient Count per Consultant" summary (active-list.php), incl. OFF-service -->
            <section v-if="groups.length" class="group-block mb-6">
                <h2 class="mb-1.5 border-b border-line pb-1 text-sm font-bold uppercase tracking-wide text-navy-800">Patient count per consultant</h2>
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr class="bg-app/80 text-left font-semibold uppercase tracking-wide text-ink-500 print:bg-ink-100">
                            <th scope="col" class="px-2 py-1.5">Consultant</th><th scope="col" class="px-2 py-1.5 text-center">Old</th><th scope="col" class="px-2 py-1.5 text-center">New</th>
                            <th scope="col" class="px-2 py-1.5 text-center">Active</th><th scope="col" class="px-2 py-1.5 text-center">Ward</th>
                            <th scope="col" class="px-2 py-1.5 text-center">ICU</th><th scope="col" class="px-2 py-1.5 text-center">TB</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="sec in sections" :key="sec.key">
                            <tr><td colspan="7" class="border-b border-ink-50 px-2 py-1 text-[11px] font-bold uppercase tracking-wide" :class="sec.key === 'off' ? 'text-on-danger' : 'text-ink-500'">{{ sec.label }}</td></tr>
                            <tr v-for="g in sec.rows" :key="g.id" class="border-b border-ink-50">
                                <td class="px-2 py-1 font-semibold text-ink-700">Dr. {{ g.name }}</td>
                                <td class="nums px-2 py-1 text-center">{{ g.counts.old }}</td>
                                <td class="nums px-2 py-1 text-center">{{ g.counts.new }}</td>
                                <td class="nums px-2 py-1 text-center font-semibold text-brand-700">{{ g.counts.active }}</td>
                                <td class="nums px-2 py-1 text-center">{{ g.counts.ward }}</td>
                                <td class="nums px-2 py-1 text-center text-on-danger">{{ g.counts.icu || '' }}</td>
                                <td class="nums px-2 py-1 text-center">{{ g.counts.tb || '' }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </section>

            <section v-for="g in groups" :key="g.id" class="group-block mb-5">
                <div class="mb-1.5 flex items-baseline justify-between border-b border-line pb-1">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-navy-800">Dr. {{ g.name }}</h2>
                    <span class="nums text-xs text-ink-400">{{ g.counts.total }} patient(s) · Ward {{ g.counts.ward }} · ICU {{ g.counts.icu }}<template v-if="g.counts.tb"> · TB {{ g.counts.tb }}</template></span>
                </div>
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr class="bg-app/80 text-left font-semibold uppercase tracking-wide text-ink-500 print:bg-ink-100">
                            <th scope="col" class="px-2 py-1.5">Bed</th><th scope="col" class="px-2 py-1.5">MRN</th><th scope="col" class="px-2 py-1.5">Patient</th>
                            <th scope="col" class="px-2 py-1.5">Age/Sex</th><th scope="col" class="px-2 py-1.5">Loc</th><th scope="col" class="px-2 py-1.5">Admitted</th>
                            <th scope="col" class="px-2 py-1.5 text-right">LOS</th><th scope="col" class="px-2 py-1.5">Diagnoses</th><th scope="col" class="px-2 py-1.5">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!g.patients.length"><td colspan="9" class="px-2 py-2 text-ink-300">No patients.</td></tr>
                        <tr v-for="p in g.patients" :key="p.id" class="border-b border-ink-50 align-top">
                            <td class="nums px-2 py-1.5 font-semibold text-ink-700">{{ p.bed || '—' }}</td>
                            <td class="nums px-2 py-1.5">{{ p.mrn }}</td>
                            <td class="px-2 py-1.5 font-semibold text-ink-800">{{ p.name }}</td>
                            <td class="nums px-2 py-1.5">{{ p.age ?? '—' }} / {{ (p.gender || '—').slice(0, 1) }}</td>
                            <td class="px-2 py-1.5"><span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold print:p-0 print:text-xs" :class="locTone(p.location)">{{ p.location || '—' }}</span></td>
                            <td class="nums px-2 py-1.5">{{ p.admit_date || '—' }}</td>
                            <td class="nums px-2 py-1.5 text-right font-semibold">{{ p.los !== null ? p.los + 'd' : '—' }}</td>
                            <td class="px-2 py-1.5 text-ink-600">
                                <template v-if="p.diagnoses?.length">
                                    <div v-for="d in p.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</div>
                                </template>
                                <span v-else class="text-ink-300">—</span>
                            </td>
                            <td class="px-2 py-1.5">
                                <PatientFlags :patient="p" :readmit-window="readmitWindow" variant="plain" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <p v-if="!groups.length" class="py-10 text-center text-sm text-ink-400">No assigned active patients.</p>

            <footer class="mt-6 border-t border-line pt-3 text-center text-[11px] text-ink-400">
                DMC Internal Medicine · Patient-Flow Hub · Confidential — contains patient-identifiable data
            </footer>
        </div>
    </AppLayout>
</template>

<style>
@page { size: A4; margin: 12mm; }
@media print {
    aside, header.sticky { display: none !important; }
    [class*="pl-64"] { padding-left: 0 !important; }
    main { padding: 0 !important; }
    body { background: #fff !important; }
    .no-print { display: none !important; }
    .group-block { break-inside: avoid; }
}
</style>
