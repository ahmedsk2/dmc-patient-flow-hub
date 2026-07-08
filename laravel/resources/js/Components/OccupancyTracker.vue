<script setup>
/**
 * OccupancyTracker — the segmented bed-occupancy tracker (Wave 4). Replaces the ApexCharts
 * `radialBar` gauge on the Dashboard, which showed a single blended % and hid the composition:
 * a 90%-full ward + an empty ICU read identically to an even split. A gauge conveys neither the
 * per-unit breakdown NOR the raw bed totals well; this shows BOTH at a glance — one horizontal
 * band per unit (Ward / ICU / …), each labelled with its exact "occupied / total" count, plus a
 * summed overall line when there is more than one unit.
 *
 * STATUS IS NEVER COLOUR-ALONE (WCAG 1.4.1), same rule as FlowAlert/PatientFlags. Each band carries
 * the load three redundant ways: the aria-label spells the tier in words ("within normal range" /
 * "at warning threshold" / "at or over critical threshold"), a visible status pill prints the word,
 * and only THEN does the fill change hue. The default fill is the CALM brand teal — a full-but-fine
 * ward is not painted alarm-red; the amber/red tiers appear only once occupancy crosses the caller's
 * thresholds. The escalation reads as teal → amber → red, but a colour-blind or SR user gets the
 * same information from the word and the printed percentage.
 *
 * COLOUR TOKENS. The alert fills use the theme-aware `bg-tint-warning` / `bg-tint-danger` fills (deep
 * + saturated on dark, pale on light — the tint darkens/brightens per theme so a "critical" ward
 * never re-reads across a shift change), NOT the theme-invariant raw `*-500` hexes. The status pill
 * uses the AA-verified `bg-tint-*` + `text-on-*` pairs (scripts/contrast.mjs). No status colour is
 * ever used as TEXT — the a11y-status-text guard forbids the whole `text-{warning,danger,…}-{400..700}`
 * family, which fails AA on the theme-aware surfaces in dark mode.
 *
 * ACCESSIBILITY SHAPE. Each row is `role="img"` + a self-describing aria-label — the same pattern the
 * dashboard charts already use. role="img" makes the visible numerals/pill presentational to AT, so
 * the label is authoritative and there is no double-announcement; sighted users still read the band.
 * The component is purely informational (no interactive controls), so it is keyboard-inert by design.
 *
 * RTL. The fill grows along the INLINE axis (`inline-size`, not `width`) and the header uses
 * direction-agnostic `justify-between`, so a future RTL mode (Wave 5) mirrors the band with no code
 * change — the fill anchors at the inline-start and the pill floats to the inline-end automatically.
 *
 * EDGE CASES. total<=0 → "No beds configured" empty state (never a 0/0 divide → NaN). occupied>total
 * → the bar clamps at 100% but the printed count and % stay exact (an over-capacity unit reports the
 * real, >100% figure and lands in the critical tier).
 */
import { computed } from 'vue';

// tier → tokens. Fills are theme-aware; the calm default is brand teal, not a status colour.
const FILL = { normal: 'bg-brand-500', warning: 'bg-tint-warning', critical: 'bg-tint-danger' };
// Status pill: AA-verified tint + on-* pairs; the calm tier is neutral ink (not "success" green,
// which would over-claim). All theme-aware.
const PILL = {
    normal: 'bg-ink-100 text-ink-700',
    warning: 'bg-tint-warning text-on-warning',
    critical: 'bg-tint-danger text-on-danger',
};
const WORD = { normal: 'Normal', warning: 'Warning', critical: 'Critical' };
const PHRASE = {
    normal: 'within normal range',
    warning: 'at warning threshold',
    critical: 'at or over critical threshold',
};

const props = defineProps({
    // [{ key, label, occupied, total }]
    units: { type: Array, default: () => [] },
    // Fractions of capacity. Defaults match the design spec; the caller may derive these from `settings`.
    thresholds: { type: Object, default: () => ({ warning: 0.85, critical: 0.95 }) },
});

// Merge per-field, not per-object: a caller passing `{ warning: 0.5 }` (only) must keep the default
// critical, but Vue replaces the WHOLE default object once the prop is supplied.
const warn = computed(() => props.thresholds?.warning ?? 0.85);
const crit = computed(() => props.thresholds?.critical ?? 0.95);

// One unit → a fully-derived display row. Coerces junk/negative input to a safe 0 so no NaN and no
// negative bar width can ever reach the DOM.
const build = (u) => {
    const label = u.label ?? u.key ?? '';
    const total = Math.max(0, Number(u.total) || 0);
    const occupied = Math.max(0, Number(u.occupied) || 0);
    const empty = total === 0;
    const ratio = empty ? 0 : occupied / total;
    const pct = Math.round(ratio * 100);           // real figure — may exceed 100 (over capacity)
    const widthPct = Math.max(0, Math.min(100, pct)); // bar geometry — clamped to [0, 100]
    const status = empty ? 'normal' : ratio >= crit.value ? 'critical' : ratio >= warn.value ? 'warning' : 'normal';
    const aria = empty
        ? `${label}: no beds configured`
        : `${label}: ${occupied} of ${total} beds occupied, ${pct}%, ${PHRASE[status]}`;
    return { key: u.key ?? label, label, occupied, total, empty, pct, widthPct, status, aria };
};

const rows = computed(() => props.units.map(build));

// Summed line — only meaningful with >=2 units. Re-runs `build` on the totals so it clamps/empties
// by the same rules (all units at total=0 → the overall row also shows the empty state).
const overall = computed(() => {
    if (props.units.length < 2) return null;
    const sum = (k) => props.units.reduce((s, u) => s + Math.max(0, Number(u[k]) || 0), 0);
    return build({ key: '__total', label: 'Total', occupied: sum('occupied'), total: sum('total') });
});
</script>

<template>
    <div class="space-y-4">
        <!-- Unit bands. role="img" + aria-label carries the full meaning to AT (numerals below are
             then presentational, so nothing is announced twice). -->
        <div v-for="row in rows" :key="row.key" data-unit-row :data-key="row.key" role="img" :aria-label="row.aria">
            <div class="mb-1 flex items-baseline justify-between gap-2">
                <span class="text-sm font-medium text-ink-700">{{ row.label }}</span>
                <span
                    v-if="!row.empty"
                    class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                    :class="PILL[row.status]"
                >{{ WORD[row.status] }}</span>
            </div>

            <p v-if="row.empty" class="text-sm text-ink-400">No beds configured</p>
            <template v-else>
                <!-- track (neutral) + inline-size fill (logical axis → RTL-safe, clamps at 100%) -->
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-ink-100">
                    <div
                        data-fill
                        :data-width="row.widthPct"
                        class="h-full rounded-full"
                        :class="FILL[row.status]"
                        :style="{ inlineSize: row.widthPct + '%' }"
                    />
                </div>
                <p class="mt-1 text-xs tabular-nums text-ink-500">
                    {{ row.occupied }} / {{ row.total }} beds ({{ row.pct }}%)
                </p>
            </template>
        </div>

        <!-- Summed line: divided off from the per-unit bands above. Same markup, different data hook. -->
        <div
            v-if="overall"
            data-overall-row
            :data-key="overall.key"
            role="img"
            :aria-label="overall.aria"
            class="border-t border-line pt-3"
        >
            <div class="mb-1 flex items-baseline justify-between gap-2">
                <span class="text-sm font-semibold text-ink-800">{{ overall.label }}</span>
                <span
                    v-if="!overall.empty"
                    class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                    :class="PILL[overall.status]"
                >{{ WORD[overall.status] }}</span>
            </div>
            <p v-if="overall.empty" class="text-sm text-ink-400">No beds configured</p>
            <template v-else>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-ink-100">
                    <div
                        data-fill
                        :data-width="overall.widthPct"
                        class="h-full rounded-full"
                        :class="FILL[overall.status]"
                        :style="{ inlineSize: overall.widthPct + '%' }"
                    />
                </div>
                <p class="mt-1 text-xs tabular-nums text-ink-500">
                    {{ overall.occupied }} / {{ overall.total }} beds ({{ overall.pct }}%)
                </p>
            </template>
        </div>
    </div>
</template>
