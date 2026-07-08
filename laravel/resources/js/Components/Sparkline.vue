<script setup>
/**
 * Sparkline — a tiny, axis-less, tooltip-less trend line (Wave 4). Deliberately NOT ApexCharts:
 * a KPI card's inline spark is decoration, not an interactive chart, so it ships as a single
 * inline <svg> <polyline> (a few bytes) rather than pulling a chart instance per card. The line
 * scales to its own viewBox from the data's own min/max, so even a near-flat series fills the band.
 * Colour is `currentColor` — the caller sets a brand text-colour token and the graphic inherits it.
 * A spark is a non-text graphic, so WCAG 1.4.11's 3:1 applies, not 4.5. (Prose here avoids spelling
 * class tokens: the a11y guard and Tailwind's extractor both read comments — see app.css §top.)
 *
 * ACCESSIBILITY. role="img" + the caller's aria-label carry the meaning ("Admissions, last 30
 * days"); the exact numbers live in the KPI value beside it, so the spark itself needs no data
 * table (unlike a full chart — see ChartFigure).
 *
 * DEGRADES to nothing below two points: a single point (or none) is not a trend, and a one-
 * coordinate polyline has no length to draw, so we render no polyline at all.
 * (Comment prose deliberately spells no Tailwind class token — the extractor reads comments and
 * would ship it as a dead rule; see app.css §top and EhcLogo.vue for this recurring trap.)
 */
import { computed } from 'vue';

const props = defineProps({
    // the series to plot — the KPI's existing payload array (already-resolved numbers)
    data: { type: Array, default: () => [] },
    ariaLabel: { type: String, default: '' },
});

// viewBox geometry — arbitrary internal units; the <svg> stretches to the caller's w-full/h-8.
const W = 100;
const H = 32;
const PAD = 2;   // keep the stroke off the top/bottom edge so a peak/trough isn't clipped

const round = (v) => Math.round(v * 100) / 100;

// Only plot with >=2 points; below that there is no line to draw (see the component header).
const enough = computed(() => (props.data?.length ?? 0) >= 2);

const points = computed(() => {
    if (!enough.value) return '';
    const nums = props.data.map((n) => Number(n) || 0);
    const min = Math.min(...nums);
    const max = Math.max(...nums);
    const span = max - min || 1;                       // flat series → mid-height line, never /0
    const stepX = (W - PAD * 2) / (nums.length - 1);
    return nums
        .map((n, i) => `${round(PAD + i * stepX)},${round(PAD + (H - PAD * 2) * (1 - (n - min) / span))}`)
        .join(' ');
});
</script>

<template>
    <svg
        class="h-8 w-full"
        :viewBox="`0 0 ${W} ${H}`"
        preserveAspectRatio="none"
        fill="none"
        role="img"
        :aria-label="ariaLabel"
    >
        <polyline
            v-if="points"
            :points="points"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
