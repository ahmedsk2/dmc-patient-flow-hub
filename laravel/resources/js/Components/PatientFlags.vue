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
 *
 * COLOUR (W0-T3d). Every badge draws from the AA-verified `bg-tint-*` + `text-on-*` pairs, exactly
 * as FlowAlert.vue does — never the raw `*-500`/`*-600` status colours. Those raw pairings were real
 * WCAG 1.4.3 failures at this size (scripts/contrast.mjs: warning-500 on warning-100 = 2.15:1,
 * info-500 on info-100 = 3.24:1, danger-600 on danger-100 = 4.39:1, all against a 4.5:1 bar — the
 * 3:1 large-text allowance needs 24px, or 18.66px bold, and these are 10px). The `tint-*`/`on-*`
 * pairs clear 5.14:1–8.25:1 in BOTH themes; they are also theme-AWARE, which fixes a second bug:
 * `*-100` is a fixed hex, so the old pills rendered a light peach lozenge on the dark board.
 *
 * ONE DELIBERATE EXCEPTION, already AA:
 *   - `Discharged` chip: ink-100/ink-500 = 4.62:1 light, 6.63:1 dark. The ink scale inverts under
 *     `.dark`, so it is already theme-aware. Left alone.
 *
 * `Long-term` used to be a second exception — `accent` had no `on-*`/`tint-*` pair, so the badge
 * shipped accent-600 on accent-300/40: 2.90:1 (light, composited over the white card) and 1.67:1
 * (dark, over #13201f). W0-T3e minted the pair (`--tint-accent`/`--on-accent`, theme-aware like the
 * other four), and the badge now reads 7.00:1 / 9.42:1. The plain variant is 8.14:1 / 10.67:1.
 *
 * W0-T3h. The accent pair is OLIVE-gold, not the warm gold of the raw accent scale, and this
 * component is the reason. `Long-term` and `Readmit` sit side by side, same shape, same 10px
 * semibold. W0-T3e's warm-gold pair cleared AA but was dE76 14.15 (light) / 6.37 (dark) from
 * `on-warning`, and its tint was dE76 2.09 / 3.85 from `tint-warning` — two badges, one colour.
 * The olive text is now dE76 30.24 / 23.87 away. The light-mode PILLS remain close (dE76 6.75), so
 * on light the separation is carried by the label and the word, not by the fill.
 */
defineProps({
    patient: { type: Object, required: true },
    readmitWindow: { type: Number, default: 3 },
    variant: { type: String, default: 'badge', validator: (v) => ['badge', 'plain'].includes(v) },
});

// #23/#26/#28 (role/UX review 2026-09-24): these pills repeat on every card on the board and the
// printable census, so an inline "!" InfoTip on each one would be noisy at that scale — a native
// `title` (hover/tap) + `aria-label` (screen reader) on the pill itself, the same pattern
// CheckpointChips.vue already uses for its own repeated per-card chips.
//   - New/Old: the managed is_new_assignment flag (set on assign/handover/shuffle, cleared on
//     discharge/reassignment) — NOT a rolling 24-hour window (a wording the app's own metrics doc
//     currently gets wrong too; see DASHBOARD-AND-STATISTICS-METRICS.md).
//   - Long-term: after #6 (this review), the flag now carries forward across a transfer instead of
//     silently resetting — say so, not the old "resets after a transfer" behaviour.
const TIP = {
    new: 'Assigned, handed over, or shuffled — cleared on discharge or reassignment, not a 24-hour timer.',
    readmit: 'Admitted again within the readmission window of a real discharge.',
    longterm: 'Manually set by staff — not calculated from length of stay. Carries over when the patient transfers.',
    tb: 'Tuberculosis — a diagnosis on the TB reference list.',
    dischargedStillIn: 'Medically cleared to leave but still occupying the bed, awaiting a destination or bed.',
};
</script>

<template>
    <!-- `plain` has no tint behind it, so the on-* token is doing the work against the card/page:
         on-info 6.60:1, on-warning 5.93:1, on-danger 7.01:1, on-accent 8.14:1 (light) and
         9.27/9.96/8.64/10.67:1 (dark).
         This is also what makes the printed census legible — warning-500 was 2.48:1 on white. -->
    <template v-if="variant === 'plain'">
        <span v-if="patient.is_new" :title="TIP.new" :aria-label="`New: ${TIP.new}`" class="mr-1 font-semibold text-on-info">New</span>
        <span v-if="patient.is_readmission" :title="TIP.readmit" :aria-label="`Readmit: ${TIP.readmit}`" class="mr-1 font-semibold text-on-warning">Readmit ≤{{ readmitWindow ?? 3 }}d</span>
        <span v-if="patient.is_longterm" :title="TIP.longterm" :aria-label="`Long-term: ${TIP.longterm}`" class="mr-1 font-semibold text-on-accent">Long-term</span>
        <span v-if="patient.is_tb" :title="TIP.tb" :aria-label="`TB: ${TIP.tb}`" class="mr-1 font-semibold text-on-danger">TB</span>
        <span v-if="patient.medically_discharged" :title="TIP.dischargedStillIn" :aria-label="`Disch. still in: ${TIP.dischargedStillIn}`" class="font-semibold text-on-warning">Disch. still in</span>
    </template>
    <template v-else>
        <span v-if="patient.is_new" :title="TIP.new" :aria-label="`New: ${TIP.new}`" class="rounded-full bg-tint-info px-1.5 py-0.5 text-[10px] font-semibold text-on-info">New</span>
        <span v-if="patient.is_readmission" :title="TIP.readmit" :aria-label="`Readmit: ${TIP.readmit}`" class="rounded-full bg-tint-warning px-1.5 py-0.5 text-[10px] font-semibold text-on-warning">Readmit ≤{{ readmitWindow ?? 3 }}d</span>
        <span v-if="patient.is_longterm" :title="TIP.longterm" :aria-label="`Long-term: ${TIP.longterm}`" class="rounded-full bg-tint-accent px-1.5 py-0.5 text-[10px] font-semibold text-on-accent">Long-term</span>
        <span v-if="patient.is_tb" :title="TIP.tb" :aria-label="`TB: ${TIP.tb}`" class="rounded-full bg-tint-danger px-1.5 py-0.5 text-[10px] font-semibold text-on-danger">TB</span>
        <span v-if="patient.discharged" class="rounded-full bg-ink-100 px-1.5 py-0.5 text-[10px] font-semibold text-ink-500">Discharged {{ patient.discharge_date }}</span>
        <span v-else-if="patient.medically_discharged" :title="TIP.dischargedStillIn" :aria-label="`Disch. still in: ${TIP.dischargedStillIn}`" class="rounded-full bg-tint-warning px-1.5 py-0.5 text-[10px] font-semibold text-on-warning">Disch. still in</span>
    </template>
</template>
