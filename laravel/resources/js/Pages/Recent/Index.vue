<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ discharges: Array, signoffs: Array, since: String });

const tab = ref('discharges');
const undoDischarge = (id) => { if (confirm('Reverse this discharge? The patient returns to the active board.')) router.post(`/admissions/${id}/reverse-discharge`, {}, { preserveScroll: true }); };
const undoSignoff = (id) => { if (confirm('Reverse this sign-off? The consultation returns to active.')) router.post(`/consultations/${id}/reverse-signoff`, {}, { preserveScroll: true }); };

const outcomeTone = (o) => o === 'Dead' ? 'bg-danger-100 text-danger-600' : o === 'Alive' ? 'bg-success-100 text-success-600' : 'bg-ink-100 text-ink-500';
</script>

<template>
    <Head title="Recent Activity" />
    <AppLayout title="Recent Activity">
        <div class="mb-5 flex items-center gap-3">
            <div class="flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100 w-fit">
                <button @click="tab = 'discharges'" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="tab === 'discharges' ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">Discharges ({{ discharges.length }})</button>
                <button @click="tab = 'signoffs'" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="tab === 'signoffs' ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">Sign-offs ({{ signoffs.length }})</button>
            </div>
            <span class="text-sm text-ink-400">since {{ since }} · undo is admin-only</span>
        </div>

        <!-- discharges -->
        <div v-show="tab === 'discharges'" class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                    <th class="px-5 py-3">Patient</th><th class="px-3 py-3">Discharged</th><th class="px-3 py-3">From</th><th class="px-3 py-3">Outcome</th><th class="px-3 py-3">By</th><th class="px-5 py-3 text-right">Undo</th>
                </tr></thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="d in discharges" :key="d.id" class="hover:bg-brand-50/40">
                        <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ d.name }}</div><div class="nums text-xs text-ink-400">MRN {{ d.mrn }}</div></td>
                        <td class="nums px-3 py-3 text-ink-500">{{ d.discharge_date }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ d.current_location || '—' }}</td>
                        <td class="px-3 py-3"><span v-if="d.outcome" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="outcomeTone(d.outcome)">{{ d.outcome }}</span></td>
                        <td class="px-3 py-3 text-ink-600">{{ d.actor || '—' }}</td>
                        <td class="px-5 py-3 text-right"><button @click="undoDischarge(d.id)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-danger-600 hover:bg-danger-100">Undo</button></td>
                    </tr>
                    <tr v-if="!discharges.length"><td colspan="6" class="px-5 py-10 text-center text-ink-400">No discharges in the last 48 hours.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- signoffs -->
        <div v-show="tab === 'signoffs'" class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                    <th class="px-5 py-3">Patient</th><th class="px-3 py-3">Signed off</th><th class="px-3 py-3">Service</th><th class="px-3 py-3">Consultant</th><th class="px-5 py-3 text-right">Undo</th>
                </tr></thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="s in signoffs" :key="s.id" class="hover:bg-brand-50/40">
                        <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ s.name }}</div><div class="nums text-xs text-ink-400">MRN {{ s.mrn }}</div></td>
                        <td class="nums px-3 py-3 text-ink-500">{{ s.signoff_date }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ s.to_service || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ s.consultant || '—' }}</td>
                        <td class="px-5 py-3 text-right"><button @click="undoSignoff(s.id)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-danger-600 hover:bg-danger-100">Undo</button></td>
                    </tr>
                    <tr v-if="!signoffs.length"><td colspan="5" class="px-5 py-10 text-center text-ink-400">No sign-offs in the last 48 hours.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
