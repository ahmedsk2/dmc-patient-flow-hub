<script>
/**
 * The tone registry. It lives in a normal <script> block, NOT in <script setup>, because
 * `defineProps()` is hoisted out of setup() and therefore cannot reference any script-setup local —
 * the compiler rejects it outright (see @vue/compiler-sfc `checkInvalidScopeReference`, which allows
 * only `literal-const` bindings; an object literal is not one). Vue's own error message prescribes
 * exactly this workaround. Module-scope consts here are still visible to the template.
 *
 * Keeping the five maps together, and validating the `tone` prop against ICON, means ONE list of
 * tones rather than a sixth parallel list that can silently drift out of step with the other five.
 */

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
</script>

<script setup>
/**
 * FlowAlert — the 3-tier callout of the "Census Board" signature (Wave 0).
 *
 * Rule (NHS-derived, WCAG 1.4.1): urgency is NEVER carried by colour alone. Every alert renders
 * three redundant signals — a visually-hidden screen-reader prefix, an icon, and a text title —
 * on top of the tone colour.
 *
 * LIVE REGIONS. `critical` is role="status" (an implicitly POLITE live region); calmer tones are the
 * inert role="note". Two facts drive this:
 *   - A live region already in the DOM at page load is NOT announced. On a hard load, `critical` is
 *     spoken by the same mechanism as every other tone: its SR prefix, when the user reaches it.
 *     role="status" contributes nothing there — and neither would role="alert".
 *   - Under Inertia SPA navigation the node IS inserted, so it fires. role="alert" is assertive and
 *     would INTERRUPT the page-title/heading announcement on every navigation. For a standing
 *     summary of state ("Two discharges are overdue") that is the classic assertive antipattern, so
 *     we queue politely instead. The SR prefix, not the live region, is what guarantees the tier.
 *
 * Colours come from the AA-verified `tint-*` / `on-*` token pairs (scripts/contrast.mjs proves the
 * ratios in BOTH themes). Never use the raw `*-500` status colours as text — most fail AA. And never
 * put `opacity-*` on the body: `opacity` composites the text in sRGB over the tint, which drops
 * light `on-info` to 4.41:1 and light `on-warning` to 4.21:1 — under the 4.5:1 that 14px normal-
 * weight text requires (WCAG 1.4.3). Hierarchy is carried by font-weight, not by fading the copy a
 * low-vision reader most needs. (Break-even alphas are 0.912 / 0.935; no Tailwind step is safe.)
 *
 * Rail tone names match the colour tokens they dereference (`rail-danger`), not this component's
 * alert vocabulary (`critical`) — hence the RAIL map above.
 *
 * Cap at two callouts per view — see docs/superpowers/specs/2026-07-08-ehc-ui-ux-delta-design.md §2.
 */
import { computed } from 'vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info',
        // Object.hasOwn, never `v in ICON` — `'constructor' in ICON` is true.
        validator: (v) => Object.hasOwn(ICON, v),
    },
    title: { type: String, required: true },
});

// An unknown tone must never render as an unmarked grey box: with no rail/tint/on-* class and no
// icon path, all three redundant signals vanish at once and the callout reads as page furniture.
// Degrade UP — in a clinical UI an over-loud alert beats one nobody can see. The validator above
// cannot protect this: Vue strips prop validators from production builds entirely.
const t = computed(() => (Object.hasOwn(ICON, props.tone) ? props.tone : 'critical'));
const role = computed(() => (t.value === 'critical' ? 'status' : 'note'));
</script>

<!-- rounded-e-* (logical) leaves the rail edge square: the "ticket" silhouette. -->
<template>
    <div :role="role" :class="['status-rail flex gap-3 rounded-e-xl p-3', RAIL[t], TINT[t], TEXT[t]]">
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
            <path :d="ICON[t]" />
        </svg>
        <div class="min-w-0">
            <p class="text-sm font-semibold"><span class="sr-only">{{ PREFIX[t] }}</span>{{ title }}</p>
            <div v-if="$slots.default" class="mt-0.5 text-sm"><slot /></div>
        </div>
    </div>
</template>
