<script setup>
import { computed } from 'vue';

/**
 * Wave 1 (EHC UI) — the identity-tuple primitive. One inline, teal-hairlined tuple
 * (name · MRN · age·sex · location · status) that travels UNCHANGED from a palette/search result
 * row into any action/edit modal header, so the user re-verifies the same five facts at the moment
 * of action that they selected on. Rules it enforces:
 *   • MRN renders in tabular numerals and is never shortened — a clipped MRN is a
 *     wrong-patient hazard;
 *   • text rides the theme-aware ink tokens; the status chip uses only the AA-verified
 *     bg-tint-* + text-on-* pairs (discharged keeps the neutral ink pair, matching PatientFlags);
 *   • absent segments disappear entirely — no dangling separators.
 */
const props = defineProps({
    name: { type: String, required: true },
    mrn: { type: [String, Number], default: null },
    age: { type: [String, Number], default: null },
    sex: { type: String, default: '' },
    location: { type: String, default: '' },
    status: { type: String, default: '' },
});

// "63 · F" — first letter of the sex, matching the board/registry convention
const ageSex = computed(() => {
    const parts = [];
    if (props.age !== null && props.age !== undefined && props.age !== '') parts.push(String(props.age));
    if (props.sex) parts.push(props.sex.slice(0, 1).toUpperCase());
    return parts.join(' · ');
});

const STATUS_TONES = {
    active: ['Active', 'bg-tint-success text-on-success'],
    unassigned: ['Unassigned', 'bg-tint-accent text-on-accent'],
    discharged: ['Discharged', 'bg-ink-100 text-ink-500'],
    deceased: ['Deceased', 'bg-tint-danger text-on-danger'],
};
const statusChip = computed(() => props.status ? (STATUS_TONES[props.status] || [props.status, 'bg-ink-100 text-ink-500']) : null);

const hasMrn = computed(() => props.mrn !== null && props.mrn !== undefined && props.mrn !== '');
</script>

<template>
    <span class="inline-flex max-w-full flex-wrap items-center gap-x-2 gap-y-0.5 rounded-lg border border-brand-500/30 bg-card px-2.5 py-1 text-start">
        <span data-id-name class="text-sm font-bold text-ink-800">{{ name }}</span>
        <span v-if="hasMrn" data-id-mrn class="nums whitespace-nowrap text-xs font-semibold text-ink-500">MRN {{ mrn }}</span>
        <span v-if="ageSex" data-id-agesex class="nums whitespace-nowrap text-xs text-ink-500">{{ ageSex }}</span>
        <span v-if="location" data-id-location class="text-xs text-ink-500">{{ location }}</span>
        <span v-if="statusChip" data-id-status class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="statusChip[1]">{{ statusChip[0] }}</span>
    </span>
</template>
