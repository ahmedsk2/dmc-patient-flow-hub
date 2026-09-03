<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { locTone } from '@/lib/ui.js';

// Service handover sheet (W3). The team's ACTIVE + ONGOING consults with each one's latest
// follow-up note — read at shift change, then printed. Read-only on purpose: ticking a follow-up
// off or moving a status happens on the workspace, so a printed sheet is never a stale write form.
//
// Scoping already happened server-side (ConsultationsController::handover delegates to
// Consultation::scopeVisibleTo). This page renders exactly what it is handed. An ordinary clinical
// viewer receives one group (their own service); admins and coordinators receive every service.
const props = defineProps({ groups: Array, generatedAt: String, today: String });

// Screen-only service picker: every group is already on the page, so narrowing is a pure
// client-side filter — the same idiom as the Active List census consultant picker.
const selected = ref('all');
const visibleGroups = computed(() => selected.value === 'all' ? props.groups : props.groups.filter((g) => g.key === selected.value));
const selectedName = computed(() => visibleGroups.value[0]?.name ?? '');
const sum = (field) => visibleGroups.value.reduce((t, g) => t + g.counts[field], 0);
const totals = computed(() => sum('total'));
const activeTotal = computed(() => sum('active'));

// "Seen today" must count only ACTIVE consults with a today follow-up — active is the only
// status that carries the daily-review obligation this figure is reporting on. The server's
// counts.seen_today is unfiltered by status (any status with a today follow-up, ongoing
// included), so it cannot be used directly here: an ongoing consult that happens to have a
// today follow-up would inflate the numerator and print a false all-clear on the sheet
// ("N of N active seen today" while some genuinely active patients were never seen). Compute
// the numerator from the actual rows instead of trusting that aggregate.
const activeSeenToday = (consultations) => consultations.filter((c) => c.status === 'active' && c.last_followup?.is_today).length;
const seenToday = computed(() => visibleGroups.value.reduce((t, g) => t + activeSeenToday(g.consultations), 0));

// Ageing tone — a consult open past a week must read as overdue on a printed page, not blend in.
// All four tokens are existing AA-verified pairs (see lib/ui.js locTone / deltaChipClass).
const ageTone = (days) => days === null ? 'bg-brand-100 text-brand-700'
    : days > 7 ? 'bg-tint-danger text-on-danger'
    : days > 2 ? 'bg-tint-warning text-on-warning'
    : 'bg-tint-success text-on-success';
// Fail LOUD, not open. The controller filters to active+ongoing with signoff_date NULL, so nothing
// else can reach this sheet today — but its own comment names status/signoff_date drift as the
// standing hazard, and the old `anything-not-active => Ongoing` would print a NEW or signed-off row
// as "no daily follow-up owed": a false all-clear on a sheet carried round a ward.
const statusTone = (s) => s === 'active' ? 'bg-tint-success text-on-success'
    : s === 'ongoing' ? 'bg-tint-warning text-on-warning'
    : 'bg-ink-100 text-ink-500';
const statusLabel = (s) => s === 'active' ? 'Active · daily F/U'
    : s === 'ongoing' ? 'Ongoing'
    : `? ${s || 'unknown'} — check the record`;

const print = () => window.print();
</script>

<template>
    <AppLayout title="Service Handover">
        <!-- toolbar (screen only) -->
        <div class="no-print mb-5 flex flex-wrap items-center gap-3">
            <button @click="print" class="inline-flex items-center gap-2 rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-solid-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
                Print
            </button>
            <label v-if="groups.length > 1" class="inline-flex items-center gap-2 text-sm font-semibold text-ink-600">
                Service
                <select v-model="selected" aria-label="Choose which service to print" class="rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm font-normal text-ink-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <option value="all">All services</option>
                    <option v-for="g in groups" :key="g.key" :value="g.key">{{ g.name }} ({{ g.counts.total }})</option>
                </select>
            </label>
            <span class="text-sm text-ink-400">Shift-handover sheet — active and ongoing consults with the latest follow-up note.</span>
        </div>

        <!-- printable document -->
        <div class="report mx-auto max-w-full rounded-2xl bg-card p-8 shadow-card ring-1 ring-line print:rounded-none print:shadow-none print:ring-0">
            <header class="mb-5 flex items-start justify-between border-b-2 border-brand-600 pb-3">
                <div>
                    <!-- h2, not h1: AppLayout already renders the page's single h1 (UX-03) -->
                    <h2 class="text-2xl font-extrabold text-navy-900">DMC <span class="text-brand-600">Internal Medicine</span></h2>
                    <p class="text-sm text-ink-500">Consultation Service Handover<span v-if="selected !== 'all'"> — {{ selectedName }}</span></p>
                </div>
                <div class="text-right text-xs text-ink-400">
                    <p>Eastern Health Cluster</p><p class="text-brand-600">تجمع الشرقية الصحي</p>
                    <p class="mt-1">Generated {{ generatedAt }}</p>
                    <p class="nums font-semibold text-ink-600">{{ totals }} open consult(s)</p>
                    <p class="nums font-semibold text-ink-600">{{ seenToday }} of {{ activeTotal }} active seen today</p>
                </div>
            </header>

            <section v-for="g in visibleGroups" :key="g.key" class="group-block mb-5">
                <div class="mb-1.5 flex items-baseline justify-between border-b border-line pb-1">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-navy-800">{{ g.name }}</h2>
                    <span class="nums text-xs text-ink-400">{{ g.counts.total }} open · Active {{ g.counts.active }} · Ongoing {{ g.counts.ongoing }} · Seen today {{ activeSeenToday(g.consultations) }}</span>
                </div>
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr class="bg-app/80 text-left font-semibold uppercase tracking-wide text-ink-500 print:bg-ink-100">
                            <th scope="col" class="px-2 py-1.5">Bed</th><th scope="col" class="px-2 py-1.5">MRN</th>
                            <th scope="col" class="px-2 py-1.5">Patient</th><th scope="col" class="px-2 py-1.5">Age</th>
                            <th scope="col" class="px-2 py-1.5">Loc</th><th scope="col" class="px-2 py-1.5">Status</th>
                            <th scope="col" class="px-2 py-1.5">Ageing</th><th scope="col" class="px-2 py-1.5">Consultant</th>
                            <th scope="col" class="px-2 py-1.5">Indication</th><th scope="col" class="px-2 py-1.5">Latest follow-up</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!g.consultations.length"><td colspan="10" class="px-2 py-2 text-ink-500">Nothing on the books.</td></tr>
                        <tr v-for="c in g.consultations" :key="c.id" class="border-b border-ink-50 align-top">
                            <td class="nums px-2 py-1.5 font-semibold text-ink-700">{{ c.bed || '—' }}</td>
                            <td class="nums px-2 py-1.5">{{ c.mrn || '—' }}</td>
                            <td class="px-2 py-1.5 font-semibold text-ink-800">{{ c.name }}</td>
                            <td class="nums px-2 py-1.5">{{ c.age ?? '—' }}</td>
                            <td class="px-2 py-1.5"><span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold print:p-0 print:text-xs" :class="locTone(c.location)">{{ c.location || '—' }}</span></td>
                            <td class="px-2 py-1.5"><span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold print:p-0 print:text-xs" :class="statusTone(c.status)">{{ statusLabel(c.status) }}</span></td>
                            <td class="px-2 py-1.5">
                                <span class="nums rounded-full px-1.5 py-0.5 text-[10px] font-semibold print:p-0 print:text-xs" :class="ageTone(c.open_days)">{{ c.open_days === null ? 'unknown' : 'open ' + c.open_days + 'd' }}</span>
                                <div class="nums mt-0.5 text-ink-400">{{ c.requested_on || '—' }}</div>
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="font-semibold text-ink-700">{{ c.consultant }}</div>
                                <div class="text-ink-400">entered by {{ c.entered_by }}</div>
                            </td>
                            <td class="px-2 py-1.5 text-ink-600">
                                <div v-for="r in c.reasons" :key="r">{{ r }}</div>
                                <div v-if="c.other" class="text-ink-500">{{ c.other }}</div>
                                <span v-if="!c.reasons.length && !c.other" class="text-ink-500">—</span>
                            </td>
                            <td class="px-2 py-1.5 text-ink-600">
                                <template v-if="c.last_followup">
                                    <div>
                                        <span class="nums font-semibold text-brand-700">{{ c.last_followup.date }}</span>
                                        · {{ c.last_followup.author }}
                                        <span v-if="c.last_followup.is_today" class="ml-1 rounded-full bg-tint-success px-1.5 py-0.5 text-[10px] font-semibold text-on-success print:p-0 print:text-xs">seen today</span>
                                    </div>
                                    <div v-if="c.last_followup.note">{{ c.last_followup.note }}</div>
                                </template>
                                <span v-else class="text-ink-500">No follow-up recorded</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <p v-if="!groups.length" class="py-10 text-center text-sm text-ink-400">No active or ongoing consultations to hand over.</p>

            <footer class="mt-6 border-t border-line pt-3 text-center text-[11px] text-ink-400">
                DMC Internal Medicine · Patient-Flow Hub · Coordination record — the clinical note lives in the HIS · Confidential — contains patient-identifiable data
                <p class="print-classification mt-1">SECRET — Patient data / <span lang="ar" dir="rtl">سري — بيانات مرضى</span></p>
            </footer>
        </div>
    </AppLayout>
</template>

<style>
@page { size: A4; margin: 12mm; }
/* DATA-CLASSIFICATION.md §4: the bilingual classification line only belongs on the physical
   printout, never on screen — the mirror image of .no-print below. */
.print-classification { display: none; }
@media print {
    aside, header.sticky { display: none !important; }
    [class*="pl-64"] { padding-left: 0 !important; }
    main { padding: 0 !important; }
    body { background: #fff !important; }
    .no-print { display: none !important; }
    /* a service's block must not be split across a page break — the sheet is read per team */
    .group-block { break-inside: avoid; }
    /* a service with many open consults WILL span pages; repeat the column header on each one */
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
    .print-classification { display: block; }
}
</style>
