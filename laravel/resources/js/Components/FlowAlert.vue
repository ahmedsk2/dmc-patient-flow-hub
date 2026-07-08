<script setup>
/**
 * FlowAlert — the 3-tier callout of the "Census Board" signature (Wave 0).
 *
 * Rule (NHS-derived, WCAG 1.4.1): urgency is NEVER carried by colour alone. Every alert renders
 * three redundant signals — a visually-hidden screen-reader prefix, an icon, and a text title —
 * on top of the tone colour. `critical` gets role="alert" so assistive tech announces it; calmer
 * tones use role="note" and stay quiet.
 *
 * Colours come from the AA-verified `tint-*` / `on-*` token pairs (scripts/contrast.mjs proves the
 * ratios in BOTH themes). Never use the raw `*-500` status colours as text — most fail AA.
 *
 * Rail tone names match the colour tokens they dereference (`rail-danger`), not this component's
 * alert vocabulary (`critical`) — hence the RAIL map below.
 *
 * Cap at two callouts per view — see docs/superpowers/specs/2026-07-08-ehc-ui-ux-delta-design.md §2.
 */
import { computed } from 'vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info',
        validator: (v) => ['info', 'warning', 'critical'].includes(v),
    },
    title: { type: String, required: true },
});

// Trailing space so AT announces "Important: Two discharges…" rather than running the prefix into
// the title. Template whitespace can't be used (Vue's `condense` strips it); @vue/test-utils'
// .text() trims, so the spec's toBe('Important:') assertions still hold unchanged.
const PREFIX = { info: 'Information: ', warning: 'Important: ', critical: 'Action needed: ' };
const RAIL = { info: 'rail-info', warning: 'rail-warning', critical: 'rail-danger' };
const TINT = { info: 'bg-tint-info', warning: 'bg-tint-warning', critical: 'bg-tint-danger' };
const TEXT = { info: 'text-on-info', warning: 'text-on-warning', critical: 'text-on-danger' };
// 24x24 paths, all rendered as OUTLINES (the <svg> sets fill="none"; nothing here is filled). The
// three tones are distinguished by SILHOUETTE, not fill and not hue: circle (info) · triangle
// (warning) · octagon (critical), the universal stop/halt glyph. A shared silhouette across two
// tiers would make the icon contribute nothing at those tiers — see the distinct-paths spec, which
// locks the property rather than the curve data. The SR prefix and the text title still carry the
// real load; the icon is the third redundant signal, not the primary one.
const ICON = {
    info: 'M12 16v-4m0-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    warning: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z',
    critical: 'M12 8v4m0 4h.01M8.5 2h7L22 8.5v7L15.5 22h-7L2 15.5v-7L8.5 2Z',
};

const prefix = computed(() => PREFIX[props.tone]);
const rail = computed(() => RAIL[props.tone]);
const tint = computed(() => TINT[props.tone]);
const text = computed(() => TEXT[props.tone]);
const iconPath = computed(() => ICON[props.tone]);
const role = computed(() => (props.tone === 'critical' ? 'alert' : 'note'));
</script>

<!-- rounded-e-* (logical) leaves the rail edge square: the "ticket" silhouette. -->
<template>
    <div :role="role" :class="['status-rail flex gap-3 rounded-e-xl p-3', rail, tint, text]">
        <svg
            class="mt-0.5 h-5 w-5 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path :d="iconPath" />
        </svg>
        <div class="min-w-0">
            <p class="text-sm font-semibold"><span class="sr-only">{{ prefix }}</span>{{ title }}</p>
            <div v-if="$slots.default" class="mt-0.5 text-sm opacity-90"><slot /></div>
        </div>
    </div>
</template>
