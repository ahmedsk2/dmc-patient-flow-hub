<script setup>
/**
 * Patient status-flag cluster (Wave 3, Item 5): New / Readmit / Long-term / TB / Discharged /
 * Disch-still-in. Previously triplicated across the board card (Patients/Index), the printable
 * census (ActiveList), and (the dx-expander half) the queue.
 *
 * `variant` preserves each call site's EXACT rendering:
 *   - 'badge' (default) → the rounded token pills used on the board card, including the
 *     "Discharged {date}" pill for closed long-term episodes;
 *   - 'plain' → ActiveList's print-friendly coloured text spans (no pill background, no
 *     Discharged-date chip — that page lists closed episodes differently).
 *
 * The Sign-pending Link and the "N dx" expander stay in the parent (they carry navigation /
 * parent-managed open state); this component is purely the status badges.
 *
 * `readmitWindow` is the days label on the Readmit flag (server-computed flag; this is display only).
 */
defineProps({
    patient: { type: Object, required: true },
    readmitWindow: { type: Number, default: 3 },
    variant: { type: String, default: 'badge', validator: (v) => ['badge', 'plain'].includes(v) },
});
</script>

<template>
    <template v-if="variant === 'plain'">
        <span v-if="patient.is_new" class="mr-1 font-semibold text-info-500">New</span>
        <span v-if="patient.is_readmission" class="mr-1 font-semibold text-warning-500">Readmit ≤{{ readmitWindow ?? 3 }}d</span>
        <span v-if="patient.is_longterm" class="mr-1 font-semibold text-accent-600">Long-term</span>
        <span v-if="patient.is_tb" class="mr-1 font-semibold text-danger-600">TB</span>
        <span v-if="patient.medically_discharged" class="font-semibold text-warning-500">Disch. still in</span>
    </template>
    <template v-else>
        <span v-if="patient.is_new" class="rounded-full bg-info-100 px-1.5 py-0.5 text-[10px] font-semibold text-info-500">New</span>
        <span v-if="patient.is_readmission" class="rounded-full bg-warning-100 px-1.5 py-0.5 text-[10px] font-semibold text-warning-500">Readmit ≤{{ readmitWindow ?? 3 }}d</span>
        <span v-if="patient.is_longterm" class="rounded-full bg-accent-300/40 px-1.5 py-0.5 text-[10px] font-semibold text-accent-600">Long-term</span>
        <span v-if="patient.is_tb" class="rounded-full bg-danger-100 px-1.5 py-0.5 text-[10px] font-semibold text-danger-600">TB</span>
        <span v-if="patient.discharged" class="rounded-full bg-ink-100 px-1.5 py-0.5 text-[10px] font-semibold text-ink-500">Discharged {{ patient.discharge_date }}</span>
        <span v-else-if="patient.medically_discharged" class="rounded-full bg-warning-100 px-1.5 py-0.5 text-[10px] font-semibold text-warning-500">Disch. still in</span>
    </template>
</template>
