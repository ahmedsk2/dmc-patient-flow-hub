<script setup>
import { computed } from 'vue';

/**
 * Shared handover-checkpoint chip row (spec §D4). Extracted verbatim from HandoverModal.vue's
 * read-view — one chip per SET flag / non-null code_status — so the SAME chips render in the three
 * places the design calls for: the HandoverModal read view, the board's PatientCard handover
 * affordance, and the Handovers inbox rows. Purely presentational: renders nothing when `checkpoints`
 * is null/empty (no handover row yet, or every flag unset).
 *
 * Token colours are reused verbatim from PatientFlags.vue (bg-tint-warning/text-on-warning,
 * bg-tint-danger/text-on-danger) and DxChips.vue (bg-brand-100/text-brand-700) — no new Tailwind
 * utilities introduced. Callers may add spacing (e.g. `class="mb-2"`) — it merges onto this root via
 * Vue's normal fallthrough-attribute behaviour.
 */
const props = defineProps({
    checkpoints: { type: Object, default: null },
});

const CODE_STATUS_LABEL = { full: 'Full', dnr: 'DNR', dni: 'DNI' };

const chips = computed(() => {
    const cp = props.checkpoints;
    if (!cp) return [];
    const out = [];
    if (cp.vte_completed) out.push({ key: 'vte', label: 'VTE', classes: 'bg-brand-100 text-brand-700' });
    if (cp.ready_for_discharge) out.push({ key: 'dc', label: 'D/C ready', classes: 'bg-brand-100 text-brand-700' });
    if (cp.high_risk) out.push({ key: 'hr', label: 'High-risk', classes: 'bg-tint-warning text-on-warning' });
    if (cp.needs_workup) out.push({ key: 'nw', label: 'Needs workup', classes: 'bg-brand-100 text-brand-700' });
    if (cp.workup_pending) out.push({ key: 'wp', label: 'Workup pending', classes: 'bg-brand-100 text-brand-700' });
    if (cp.code_status) {
        out.push({
            key: 'cs',
            label: CODE_STATUS_LABEL[cp.code_status] || cp.code_status,
            classes: cp.code_status === 'full' ? 'bg-brand-100 text-brand-700' : 'bg-tint-danger text-on-danger',
        });
    }
    return out;
});
</script>

<template>
    <div v-if="chips.length" class="flex flex-wrap gap-1.5">
        <span v-for="c in chips" :key="c.key" class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold" :class="c.classes">{{ c.label }}</span>
    </div>
</template>
