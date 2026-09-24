<script setup>
import { computed } from 'vue';
import { CHECKPOINT_FIELDS, CODE_STATUS_OPTIONS } from '@/lib/handover.js';

/**
 * Shared handover-checkpoint chip row (spec §D4). Extracted verbatim from HandoverModal.vue's
 * read-view — one chip per SET flag / non-null code_status — so the SAME chips render in the three
 * places the design calls for: the HandoverModal read view, the board's PatientCard handover
 * affordance, and the Handovers inbox rows. Purely presentational: renders nothing when `checkpoints`
 * is null/empty (no handover row yet, or every flag unset).
 *
 * Flag keys + labels come from the shared CHECKPOINT_FIELDS/CODE_STATUS_OPTIONS (lib/handover.js) —
 * this component no longer keeps its own copy of those strings. The colour mapping below is NOT
 * derivable from that shared shape (it's a chip-display concern, not a field-definition one) and is
 * intentionally kept local: high_risk uses the warning token, dnr/dni use the danger token, everything
 * else uses the brand token. Token colours are reused verbatim from PatientFlags.vue
 * (bg-tint-warning/text-on-warning, bg-tint-danger/text-on-danger) and DxChips.vue
 * (bg-brand-100/text-brand-700) — no new Tailwind utilities introduced. Callers may add spacing (e.g.
 * `class="mb-2"`) — it merges onto this root via Vue's normal fallthrough-attribute behaviour.
 */
const props = defineProps({
    checkpoints: { type: Object, default: null },
});

const WARNING_FLAG_KEYS = new Set(['high_risk']);
const DANGER_CODE_STATUSES = new Set(['dnr', 'dni']);

// #23 (role/UX review 2026-09-24): the chips render only the SHORT abbreviation (VTE, D/C ready,
// DNR, DNI, TB…) with no on-page expansion anywhere they appear (board card, handover editor,
// Handovers inbox). The full wording already exists — CHECKPOINT_FIELDS.label for the flag chips —
// this just wires it up as each chip's accessible tooltip (title on hover/tap, aria-label for a
// screen reader). Code-status abbreviations aren't spelled out anywhere in the shared option list,
// so the clinical meaning is added here directly (never invented — DNR/DNI are the standard terms).
const CODE_STATUS_TITLES = {
    full: 'Full resuscitation',
    dnr: 'Do-not-resuscitate',
    dni: 'Do-not-intubate',
};

const chips = computed(() => {
    const cp = props.checkpoints;
    if (!cp) return [];
    const out = [];
    for (const f of CHECKPOINT_FIELDS) {
        if (!cp[f.key]) continue;
        out.push({ key: f.key, label: f.short, title: f.label, classes: WARNING_FLAG_KEYS.has(f.key) ? 'bg-tint-warning text-on-warning' : 'bg-brand-100 text-brand-700' });
    }
    if (cp.code_status) {
        const opt = CODE_STATUS_OPTIONS.find((o) => o.value === cp.code_status);
        const label = opt ? opt.label : cp.code_status;
        out.push({
            key: 'cs',
            label,
            title: CODE_STATUS_TITLES[cp.code_status] || label,
            classes: DANGER_CODE_STATUSES.has(cp.code_status) ? 'bg-tint-danger text-on-danger' : 'bg-brand-100 text-brand-700',
        });
    }
    return out;
});
</script>

<template>
    <div v-if="chips.length" class="flex flex-wrap gap-1.5">
        <span v-for="c in chips" :key="c.key" :title="c.title" :aria-label="c.title" class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold" :class="c.classes">{{ c.label }}</span>
    </div>
</template>
