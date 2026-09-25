<script setup>
/**
 * Read-only admission review block (Wave 3, Item 4). Extracted from the two byte-identical
 * "Kindly review admission details" panels in the medical-discharge and ICU-discharge sub-forms of
 * Patients/Index. Display-only — no actions, no wiring — so it doubles as a future patient-detail
 * panel host. Diagnoses render as a plain list (matching the legacy review block exactly), not the
 * removable DxChips edit row.
 *
 * (role walkthrough 2026-09-25, U12) heading text changed from the all-caps-authored "KINDLY REVIEW
 * ADMISSION DETAILS" (read literally, not just styled that way — the shouty legacy phrasing) to
 * sentence case; the `uppercase tracking-wide` CLASS stays — it's the same section-label treatment
 * used consistently across the app (e.g. the discharge type / delay reason labels), so only the
 * words changed, not the visual style.
 */
defineProps({
    patient: { type: Object, required: true },
});
</script>

<template>
    <div class="rounded-xl bg-app/70 p-3 ring-1 ring-line">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-on-warning">Review the admission details</p>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
            <div><dt class="font-semibold text-ink-400">Name</dt><dd class="text-ink-700">{{ patient.name }}</dd></div>
            <div><dt class="font-semibold text-ink-400">MRN</dt><dd class="nums text-ink-700">{{ patient.mrn || '—' }}</dd></div>
            <div><dt class="font-semibold text-ink-400">Age / Gender</dt><dd class="nums text-ink-700">{{ patient.age ?? '—' }}y · {{ patient.gender || '—' }}</dd></div>
            <div><dt class="font-semibold text-ink-400">Bed</dt><dd class="nums text-ink-700">{{ patient.bed || '—' }}</dd></div>
            <div><dt class="font-semibold text-ink-400">Admitted from</dt><dd class="text-ink-700">{{ patient.admitted_from || '—' }}</dd></div>
            <div><dt class="font-semibold text-ink-400">Admit date</dt><dd class="nums text-ink-700">{{ patient.admit_date || '—' }}</dd></div>
        </dl>
        <ul v-if="patient.diagnoses?.length" class="mt-2 space-y-0.5 border-t border-line pt-2 text-[11px] leading-snug text-ink-600">
            <li v-for="d in patient.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</li>
        </ul>
    </div>
</template>
