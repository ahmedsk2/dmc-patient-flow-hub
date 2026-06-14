<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Phase 4 — Item 5: read-only report of admission diagnosis codes with no matching icd10 row.
 * These were imported before ICD-10 validation was enforced. Use the Registry to find specific
 * admissions; there is no bulk-fix here (a manual clinical decision).
 */
defineProps({ orphans: { type: Array, default: () => [] } });

const when = (d) => (d ? String(d).slice(0, 10) : '—');
const card = 'overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line';
const th = 'px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-400';
const td = 'px-5 py-3 text-sm text-ink-700';
</script>

<template>
    <Head title="Orphan Diagnosis Codes" />
    <AppLayout title="Orphan Diagnosis Codes">
        <p class="mb-5 max-w-2xl text-sm text-ink-400">
            These diagnosis codes are recorded against admissions but have no matching ICD-10 reference row.
            They were imported before ICD-10 validation was enforced. Use the Registry diagnosis search to find
            the specific admissions.
        </p>
        <div :class="card">
            <table class="w-full">
                <thead><tr class="border-b border-line">
                    <th :class="th" scope="col">Code</th>
                    <th :class="th" scope="col">Admissions</th>
                    <th :class="th" scope="col">Last seen</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(o, i) in orphans" :key="i">
                        <td :class="[td, 'nums font-semibold']">{{ o.icd10_code }}</td>
                        <td :class="[td, 'nums']">{{ o.admissions }}</td>
                        <td :class="[td, 'nums text-ink-400']">{{ when(o.last_seen) }}</td>
                    </tr>
                    <tr v-if="!orphans.length"><td :class="[td, 'text-success-600']" colspan="3">No orphan diagnosis codes — every recorded code matches an ICD-10 row.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
