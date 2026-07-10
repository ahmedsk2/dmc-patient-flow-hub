<script setup>
import { computed } from 'vue';

// Wave 2 — Item 3: a sortable table column header. The clickable surface is a REAL
// <button type="button"> (not a click handler on the <th> itself, which native AT can't reach
// via keyboard/Tab), while the LIVE aria-sort lives on the <th> per the WAI-ARIA table pattern —
// that is what a screen reader actually announces on activation, not the visual glyph.
//
// Cycle: none -> desc -> asc -> none. Clicking any OTHER column always (re)starts it at desc. This
// mirrors useServerTable.js's toggle() cycle exactly (a deliberately duplicated, independently
// unit-tested implementation of the same algorithm) — the parent page owns the actual state and
// passes it back in as `current`.
const props = defineProps({
    label: { type: String, required: true },
    // the server-side column key this header controls (matches useServerTable's `state.key`)
    sortKey: { type: String, required: true },
    // the table's current sort — { key: string|null, dir: 'asc'|'desc'|null }
    current: { type: Object, default: () => ({ key: null, dir: null }) },
});
const emit = defineEmits(['sort']);

const isActive = computed(() => props.current?.key === props.sortKey);
const dir = computed(() => (isActive.value ? (props.current?.dir ?? null) : null));
const ariaSort = computed(() => (dir.value === 'asc' ? 'ascending' : dir.value === 'desc' ? 'descending' : 'none'));

const cycle = () => {
    const next = !isActive.value ? 'desc'
        : props.current.dir === 'desc' ? 'asc'
        : props.current.dir === 'asc' ? null
        : 'desc';
    emit('sort', { key: props.sortKey, dir: next });
};
</script>

<template>
    <!-- No layout classes of our own — the caller supplies padding/position/background via a
         plain `class` attribute (Vue merges it onto this root <th>), so ONE reusable component
         works for narrow/wide columns and sticky-header table headers alike without a conflict
         between a hardcoded px-3 here and whatever the call site actually needs. -->
    <th scope="col" :aria-sort="ariaSort">
        <button type="button" @click="cycle"
            class="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-ink-400 transition hover:text-ink-700">
            {{ label }}
            <span aria-hidden="true" class="text-[10px] leading-none" :class="dir ? 'text-brand-700' : 'text-ink-300'">
                <template v-if="dir === 'asc'">▲</template>
                <template v-else-if="dir === 'desc'">▼</template>
                <template v-else>↕</template>
            </span>
        </button>
    </th>
</template>
