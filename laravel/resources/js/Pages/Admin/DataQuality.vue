<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Phase 4 — Item 6: admin data-quality dashboard. Five collapsible canary cards, each with a row
 * count badge and per-row links to the patient board. Read-only hygiene checklist.
 */
const props = defineProps({
    overLos: { type: Array, default: () => [] },
    noDx: { type: Array, default: () => [] },
    badDates: { type: Array, default: () => [] },
    orphanDx: { type: Array, default: () => [] },
    doubleOpen: { type: Array, default: () => [] },
    longLos: { type: Number, default: 11 },
    multiplier: { type: Number, default: 2 },
});

// each section open by default when it has rows
const open = ref({ overLos: true, noDx: true, badDates: true, orphanDx: true, doubleOpen: true });
const toggle = (k) => (open.value[k] = !open.value[k]);

const patientLink = (id) => `/patients?highlight=${id}`;
const when = (d) => (d ? String(d).slice(0, 10) : '—');

const card = 'overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line';
const th = 'px-5 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-ink-400';
const td = 'px-5 py-2.5 text-sm text-ink-700';
const badge = (n) => `nums rounded-full px-2 py-0.5 text-xs font-bold ${n > 0 ? 'bg-tint-warning text-on-warning' : 'bg-tint-success text-on-success'}`;
</script>

<template>
    <AppLayout title="Data Quality" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Data Management' },
        { label: 'Data Quality' },
    ]">
        <p class="mb-5 max-w-2xl text-sm text-ink-400">
            A daily data-hygiene checklist. Stale episodes are active non-long-term patients with a length of
            stay over <span class="nums font-semibold text-ink-700">{{ longLos }} × {{ multiplier }} = {{ longLos * multiplier }}</span> days
            (tunable in Control → Settings).
        </p>

        <div class="space-y-5">
            <!-- Q1 over-LOS -->
            <section :class="card">
                <button class="flex w-full items-center gap-3 px-5 py-3 text-left" @click="toggle('overLos')">
                    <span class="font-bold text-ink-800">Stale episodes (LOS &gt; {{ longLos * multiplier }}d)</span>
                    <span :class="badge(overLos.length)">{{ overLos.length }}</span>
                    <span class="ml-auto text-ink-300">{{ open.overLos ? '−' : '+' }}</span>
                </button>
                <table v-show="open.overLos" class="w-full border-t border-line">
                    <thead><tr><th :class="th">MRN</th><th :class="th">Patient</th><th :class="th">LOS (d)</th><th :class="th">Admitted</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in overLos" :key="r.id">
                            <td :class="[td, 'nums']"><Link :href="patientLink(r.id)" class="text-brand-600 hover:underline">{{ r.mrn }}</Link></td>
                            <td :class="td">{{ r.name }}</td><td :class="[td, 'nums']">{{ r.los }}</td><td :class="[td, 'nums']">{{ when(r.admit_date) }}</td>
                        </tr>
                        <tr v-if="!overLos.length"><td :class="[td, 'text-on-success']" colspan="4">None.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- Q2 no diagnoses -->
            <section :class="card">
                <button class="flex w-full items-center gap-3 px-5 py-3 text-left" @click="toggle('noDx')">
                    <span class="font-bold text-ink-800">Active episodes with no diagnosis</span>
                    <span :class="badge(noDx.length)">{{ noDx.length }}</span>
                    <span class="ml-auto text-ink-300">{{ open.noDx ? '−' : '+' }}</span>
                </button>
                <table v-show="open.noDx" class="w-full border-t border-line">
                    <thead><tr><th :class="th">MRN</th><th :class="th">Patient</th><th :class="th">Admitted</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in noDx" :key="r.id">
                            <td :class="[td, 'nums']"><Link :href="patientLink(r.id)" class="text-brand-600 hover:underline">{{ r.mrn }}</Link></td>
                            <td :class="td">{{ r.name }}</td><td :class="[td, 'nums']">{{ when(r.admit_date) }}</td>
                        </tr>
                        <tr v-if="!noDx.length"><td :class="[td, 'text-on-success']" colspan="3">None.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- Q3 bad dates -->
            <section :class="card">
                <button class="flex w-full items-center gap-3 px-5 py-3 text-left" @click="toggle('badDates')">
                    <span class="font-bold text-ink-800">Impossible / future dates</span>
                    <span :class="badge(badDates.length)">{{ badDates.length }}</span>
                    <span class="ml-auto text-ink-300">{{ open.badDates ? '−' : '+' }}</span>
                </button>
                <table v-show="open.badDates" class="w-full border-t border-line">
                    <thead><tr><th :class="th">MRN</th><th :class="th">Patient</th><th :class="th">Admit</th><th :class="th">Discharge</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in badDates" :key="r.id">
                            <td :class="[td, 'nums']"><Link :href="patientLink(r.id)" class="text-brand-600 hover:underline">{{ r.mrn }}</Link></td>
                            <td :class="td">{{ r.name }}</td><td :class="[td, 'nums']">{{ when(r.admit_date) }}</td><td :class="[td, 'nums']">{{ when(r.discharge_date) }}</td>
                        </tr>
                        <tr v-if="!badDates.length"><td :class="[td, 'text-on-success']" colspan="4">None.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- Q4 orphan codes -->
            <section :class="card">
                <button class="flex w-full items-center gap-3 px-5 py-3 text-left" @click="toggle('orphanDx')">
                    <span class="font-bold text-ink-800">Unknown ICD-10 codes (active episodes)</span>
                    <span :class="badge(orphanDx.length)">{{ orphanDx.length }}</span>
                    <span class="ml-auto text-ink-300">{{ open.orphanDx ? '−' : '+' }}</span>
                </button>
                <table v-show="open.orphanDx" class="w-full border-t border-line">
                    <thead><tr><th :class="th">MRN</th><th :class="th">Patient</th><th :class="th">Code</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(r, i) in orphanDx" :key="i">
                            <td :class="[td, 'nums']"><Link :href="patientLink(r.id)" class="text-brand-600 hover:underline">{{ r.mrn }}</Link></td>
                            <td :class="td">{{ r.name }}</td><td :class="[td, 'nums']">{{ r.icd10_code }}</td>
                        </tr>
                        <tr v-if="!orphanDx.length"><td :class="[td, 'text-on-success']" colspan="3">None.</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- Q5 double open -->
            <section :class="card">
                <button class="flex w-full items-center gap-3 px-5 py-3 text-left" @click="toggle('doubleOpen')">
                    <span class="font-bold text-ink-800">Patients with &gt;1 open episode</span>
                    <span :class="badge(doubleOpen.length)">{{ doubleOpen.length }}</span>
                    <span class="ml-auto text-ink-300">{{ open.doubleOpen ? '−' : '+' }}</span>
                </button>
                <table v-show="open.doubleOpen" class="w-full border-t border-line">
                    <thead><tr><th :class="th">MRN</th><th :class="th">Patient</th><th :class="th">Open episodes</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(r, i) in doubleOpen" :key="i">
                            <td :class="[td, 'nums']"><Link :href="patientLink(r.id)" class="text-brand-600 hover:underline">{{ r.mrn }}</Link></td>
                            <td :class="td">{{ r.name }}</td><td :class="[td, 'nums']">{{ r.open_episodes }}</td>
                        </tr>
                        <tr v-if="!doubleOpen.length"><td :class="[td, 'text-on-success']" colspan="3">None.</td></tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
