<script setup>
// Shared sidebar link — the single source of truth for the Clinical + Administration nav rows
// (Wave 1, Item 1). It absorbs the markup that used to be duplicated across the two render loops
// in AppLayout.vue. Purely presentational: the parent owns the icon-path lookup (the existing
// inline-SVG `icons` map) and the active-state computation, so the navy-sidebar theme + a11y
// (`aria-current`) stay byte-for-byte identical to the pre-refactor markup.
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    // resolved Heroicon-style outline path string from the parent's `icons` map (24x24 stroke)
    iconPath: { type: String, required: true },
    label: { type: String, required: true },
    active: { type: Boolean, default: false },
    indent: { type: Boolean, default: false },   // M&M nesting under Reports (Item 2)
    badge: { type: Number, default: null },        // optional count chip (e.g. pending items)
});
</script>

<template>
    <Link :href="href"
        class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
        :class="[
            indent ? 'ml-5' : '',
            active ? 'bg-white/10 text-white shadow-inner' : 'text-navy-200 hover:bg-white/5 hover:text-white',
        ]"
        :aria-current="active ? 'page' : undefined">
        <svg class="h-5 w-5 shrink-0" :class="active ? 'text-brand-300' : 'text-navy-400 group-hover:text-brand-300'"
            fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath" />
        </svg>
        {{ label }}
        <span v-if="badge !== null" class="nums ml-auto rounded-full bg-danger-600/20 px-1.5 py-0.5 text-[11px] font-bold text-danger-200"
            :aria-label="`${badge} items`">{{ badge }}</span>
        <span v-else-if="active" class="ml-auto h-1.5 w-1.5 rounded-full bg-brand-400"></span>
    </Link>
</template>
