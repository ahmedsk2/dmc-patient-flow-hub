<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useConfirm } from '@/composables/useConfirm';

const { ask } = useConfirm();

const props = defineProps({ discharges: Array, signoffs: Array, since: String });

// Wave 1, Item 3: the VIEW is read-only for all clinical roles; the same-day UNDO controls show
// only to admins. The server-side reverse-discharge / reverse-signoff endpoints enforce isAdmin()
// regardless — this just hides the dead button for everyone else (cosmetic, never the gate).
const isAdmin = computed(() => !!usePage().props.auth?.user?.is_admin);

const tab = ref('discharges');
const undoDischarge = async (d) => { if (await ask('Reverse discharge', `Reverse the discharge for ${d.name} (MRN ${d.mrn}) — the patient returns to the active board.`, 'danger')) router.post(`/admissions/${d.id}/reverse-discharge`, {}, { preserveScroll: true }); };
const undoSignoff = async (s) => { if (await ask('Reverse sign-off', `Reverse the sign-off for ${s.name} (MRN ${s.mrn}) — the consultation returns to active.`, 'danger')) router.post(`/consultations/${s.id}/reverse-signoff`, {}, { preserveScroll: true }); };

// discharges grouped per consultant like the legacy "Dr X Patient List" sections (J1-8);
// rows arrive ordered by discharge date — groups keep first-appearance order
const dischargeGroups = computed(() => {
    const groups = [];
    const byName = {};
    for (const d of props.discharges) {
        const key = d.consultant || 'Unassigned';
        if (!byName[key]) { byName[key] = { name: key, rows: [] }; groups.push(byName[key]); }
        byName[key].rows.push(d);
    }
    return groups;
});

const outcomeTone = (o) => o === 'Dead' ? 'bg-danger-100 text-danger-600' : o === 'Alive' ? 'bg-success-100 text-success-600' : 'bg-ink-100 text-ink-500';
// same short/long LOS band colors as the board cards (J2-7)
const losTone = (b) => b === 'short' ? 'bg-success-100 text-success-600' : b === 'long' ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-500';
</script>

<template>
    <AppLayout title="Recent Activity">
        <div class="mb-5 flex items-center gap-3">
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line w-fit">
                <button @click="tab = 'discharges'" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="tab === 'discharges' ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">Discharges ({{ discharges.length }})</button>
                <button @click="tab = 'signoffs'" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="tab === 'signoffs' ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">Sign-offs ({{ signoffs.length }})</button>
            </div>
            <span class="text-sm text-ink-400">yesterday + today (since {{ since }}) · undo is admin-only, same-day only</span>
        </div>

        <!-- discharges: one section per consultant (legacy "Dr X Patient List") -->
        <div v-show="tab === 'discharges'">
            <div v-for="g in dischargeGroups" :key="g.name" class="mb-4 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
                <div class="border-b border-line px-5 py-3">
                    <h3 class="font-bold text-ink-800">{{ g.name === 'Unassigned' ? 'Unassigned' : `Dr. ${g.name}` }} Patient List <span class="ml-1 text-sm font-normal text-ink-400">· {{ g.rows.length }} discharge(s)</span></h3>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3">Admitted</th><th scope="col" class="px-3 py-3">Discharged</th><th scope="col" class="px-3 py-3 text-center">LOS</th><th scope="col" class="px-3 py-3">Diagnoses</th><th scope="col" class="px-3 py-3">From</th><th scope="col" class="px-3 py-3">To</th><th scope="col" class="px-3 py-3">Outcome</th><th scope="col" class="px-3 py-3">Admitted by</th><th scope="col" class="px-3 py-3">By</th><th scope="col" class="px-5 py-3 text-right">Undo</th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="d in g.rows" :key="d.id" class="hover:bg-brand-50/40">
                            <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ d.name }}</div><div class="nums text-xs text-ink-400">MRN {{ d.mrn }}</div></td>
                            <td class="nums px-3 py-3 text-ink-500">{{ d.admit_date || '—' }}</td>
                            <td class="nums px-3 py-3 text-ink-500">{{ d.discharge_date }}</td>
                            <td class="nums px-3 py-3 text-center"><span v-if="d.los !== null" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="losTone(d.los_band)">{{ d.los }}d</span><span v-else class="text-ink-300">—</span></td>
                            <td class="max-w-56 px-3 py-3 text-xs text-ink-600" :title="d.diagnoses"><span class="line-clamp-2">{{ d.diagnoses || '—' }}</span></td>
                            <td class="px-3 py-3 text-ink-600">{{ d.current_location || '—' }}</td>
                            <td class="px-3 py-3 text-ink-600">{{ d.discharge_to || '—' }}</td>
                            <td class="px-3 py-3"><span v-if="d.outcome" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="outcomeTone(d.outcome)">{{ d.outcome }}</span></td>
                            <td class="px-3 py-3 text-ink-600">{{ d.admitter || '—' }}</td>
                            <td class="px-3 py-3 text-ink-600">{{ d.actor || '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <button v-if="isAdmin && d.reversible" @click="undoDischarge(d)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-danger-600 hover:bg-danger-100">Undo</button>
                                <span v-else class="text-xs text-ink-300" :title="isAdmin ? 'Undo is same-day only' : 'Undo is admin-only'">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
            <div v-if="!discharges.length" class="rounded-2xl bg-card px-5 py-10 text-center text-ink-400 shadow-card ring-1 ring-line">No discharges yesterday or today.</div>
        </div>

        <!-- signoffs (legacy 48consultation columns: age, indications, dates, consulted/entered by) -->
        <div v-show="tab === 'signoffs'" class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                    <th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3 text-center">Age</th><th scope="col" class="px-3 py-3">Indications</th><th scope="col" class="px-3 py-3">Consultation</th><th scope="col" class="px-3 py-3">Consulted by</th><th scope="col" class="px-3 py-3">Signed off</th><th scope="col" class="px-3 py-3">Service</th><th scope="col" class="px-3 py-3">Consultant</th><th scope="col" class="px-3 py-3">Entered by</th><th scope="col" class="px-5 py-3 text-right">Undo</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="s in signoffs" :key="s.id" class="hover:bg-brand-50/40">
                        <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ s.name }}</div><div class="nums text-xs text-ink-400">MRN {{ s.mrn }}</div></td>
                        <td class="nums px-3 py-3 text-center text-ink-600">{{ s.age ?? '—' }}</td>
                        <td class="px-3 py-3"><span v-for="r in s.reasons" :key="r" class="mr-1 inline-block rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700">{{ r }}</span><span v-if="!s.reasons?.length" class="text-ink-300">—</span></td>
                        <td class="nums px-3 py-3 text-ink-500">{{ s.consultation_date || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ s.consultation_from || '—' }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ s.signoff_date }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ s.to_service || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ s.consultant || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ s.entered_by || '—' }}</td>
                        <td class="px-5 py-3 text-right">
                            <button v-if="isAdmin && s.reversible" @click="undoSignoff(s)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-danger-600 hover:bg-danger-100">Undo</button>
                            <span v-else class="text-xs text-ink-300" :title="isAdmin ? 'Undo is same-day only' : 'Undo is admin-only'">—</span>
                        </td>
                    </tr>
                    <tr v-if="!signoffs.length"><td colspan="10" class="px-5 py-10 text-center text-ink-400">No sign-offs yesterday or today.</td></tr>
                </tbody>
            </table>
            </div>
        </div>
    </AppLayout>
</template>
