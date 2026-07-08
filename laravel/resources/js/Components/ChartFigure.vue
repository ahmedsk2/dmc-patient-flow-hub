<script setup>
/**
 * ChartFigure — the accessible-alternative wrapper for a FULL chart (Wave 4).
 *
 * A chart is an image: a screen-reader user gets nothing from the <apexchart> canvas. The delta
 * spec's rule for full charts is that each ALSO carries a one-line plain-language <figcaption> and a
 * visually-hidden data table, so the same numbers are reachable without sight. This wrapper adds
 * exactly that around the caller's chart WITHOUT changing its look: the chart renders in the default
 * slot untouched (keeping its own caller-supplied role="img"/aria-label); ChartFigure only appends
 * the text + table alternative, both `.sr-only`, so nothing visual moves.
 *
 * The table is skipped entirely when there are no rows — an empty <table> is noise to AT — and an
 * sr-only empty-state note stands in its place (overridable via the #empty slot). The visible chart
 * shows its own no-data state, so the look is still the caller's.
 */
const props = defineProps({
    // the chart's heading; also the data table's <caption>
    title: { type: String, required: true },
    // one-line plain-language summary read as the <figcaption>; falls back to the title
    caption: { type: String, default: '' },
    // data-table header cells, e.g. ['Day', 'Admissions', 'Discharges']
    columns: { type: Array, default: () => [] },
    // data-table body rows: arrays of already-formatted cell values
    rows: { type: Array, default: () => [] },
});
</script>

<template>
    <figure>
        <slot />
        <figcaption class="sr-only">{{ caption || title }}</figcaption>

        <!-- The sight-free equivalent of the chart. Skipped when there's no data. -->
        <table v-if="rows.length" class="sr-only">
            <caption>{{ title }}</caption>
            <thead>
                <tr><th v-for="col in columns" :key="col" scope="col">{{ col }}</th></tr>
            </thead>
            <tbody>
                <tr v-for="(row, r) in rows" :key="r">
                    <td v-for="(cell, c) in row" :key="c">{{ cell }}</td>
                </tr>
            </tbody>
        </table>
        <p v-else class="sr-only"><slot name="empty">No data for this period.</slot></p>
    </figure>
</template>
