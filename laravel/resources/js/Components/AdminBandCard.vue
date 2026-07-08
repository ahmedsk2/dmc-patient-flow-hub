<script setup>
// Wave 1, Item 7 — a compact stat card for the admin landing band on the Dashboard. Deep-links
// into a regrouped admin section. `urgent` tints the card danger when the count is non-zero
// (active problems: data-quality / security); informational counts stay neutral. The parent passes
// the resolved inline-SVG `iconPath` to match the app-wide icon convention (no new icon system).
//
// W0-T3i. The urgent fill was `bg-danger-50/60` — an undeclared step, so it emitted no CSS and the
// "urgent" card was indistinguishable from a calm one. Two things had to change together:
//
//  1. The fill is now the theme-aware `bg-tint-danger/60`, and it REPLACES `bg-card` instead of
//     sitting alongside it. Two background-colour utilities on one element are decided by emission
//     order in the stylesheet, not by the order they appear in `class` — so leaving `bg-card` in the
//     static list would have made the winner an implementation detail of Tailwind's sort.
//     This card has no panel behind it, so /60 composites over `--surface-app`: #f7e6e6 / #281818.
//
//  2. The count moves from `text-danger-600` to `text-on-danger`. danger-600 is a fixed literal:
//     5.63:1 on the white card, but only 2.97:1 on the dark card TODAY, and 3.02:1 on the new dark
//     tint — it was already failing AA in dark mode before this change. `on-danger` is theme-aware:
//     5.82:1 light / 8.78:1 dark on the tint. The icon chip keeps `danger-600` on `danger-100`
//     (4.39:1): it is a non-text graphic, which 1.4.11 holds to 3:1.
// Ratios: scripts/contrast.mjs.
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
        class="flex items-center gap-3 rounded-2xl border px-4 py-3 shadow-sm transition hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
        :class="urgent && count > 0 ? 'border-danger-300/60 bg-tint-danger/60' : 'border-line bg-card'">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl"
            :class="urgent && count > 0 ? 'bg-danger-100 text-danger-600' : 'bg-ink-100 text-ink-500'">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath" />
            </svg>
        </span>
        <div class="min-w-0">
            <p class="nums text-2xl font-bold leading-none" :class="urgent && count > 0 ? 'text-on-danger' : 'text-ink-900'">{{ count }}</p>
            <p class="mt-1 truncate text-xs font-medium text-ink-500">{{ label }}</p>
        </div>
    </Link>
</template>
