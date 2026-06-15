<script setup>
// Wave 1, Item 7 — a compact stat card for the admin landing band on the Dashboard. Deep-links
// into a regrouped admin section. `urgent` tints the card danger when the count is non-zero
// (active problems: data-quality / security); informational counts stay neutral. The parent passes
// the resolved inline-SVG `iconPath` to match the app-wide icon convention (no new icon system).
import { Link } from '@inertiajs/vue3';

defineProps({
    label: { type: String, required: true },
    count: { type: Number, required: true },
    href: { type: String, required: true },
    iconPath: { type: String, required: true },
    urgent: { type: Boolean, default: false },   // true → danger tint when count > 0
});
</script>

<template>
    <Link :href="href"
        class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-3 shadow-sm transition hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
        :class="urgent && count > 0 ? 'border-danger-300/60 bg-danger-50/60' : 'border-line'">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl"
            :class="urgent && count > 0 ? 'bg-danger-100 text-danger-600' : 'bg-ink-100 text-ink-500'">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath" />
            </svg>
        </span>
        <div class="min-w-0">
            <p class="nums text-2xl font-bold leading-none" :class="urgent && count > 0 ? 'text-danger-600' : 'text-ink-900'">{{ count }}</p>
            <p class="mt-1 truncate text-xs font-medium text-ink-500">{{ label }}</p>
        </div>
    </Link>
</template>
