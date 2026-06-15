<script setup>
// Wave 1, Item 5 — a pure presentational breadcrumb trail rendered near the page <h1> by
// AppLayout. Renders nothing for 0/1 crumbs (a single crumb == the current page, no trail needed).
// Each crumb: { label: string, href?: string }; the LAST crumb has no href and is the current page
// (aria-current="page"). No animation → reduced-motion safe by construction.
import { Link } from '@inertiajs/vue3';

defineProps({
    crumbs: { type: Array, default: () => [] },
});
</script>

<template>
    <nav v-if="crumbs.length > 1" aria-label="Breadcrumb" class="mt-1">
        <ol class="flex flex-wrap items-center gap-1.5 text-xs text-ink-400">
            <li v-for="(crumb, i) in crumbs" :key="i" class="flex items-center gap-1.5">
                <Link v-if="crumb.href" :href="crumb.href" class="rounded transition-colors hover:text-brand-600">{{ crumb.label }}</Link>
                <span v-else-if="i === crumbs.length - 1" class="font-semibold text-ink-600" aria-current="page">{{ crumb.label }}</span>
                <span v-else class="text-ink-400">{{ crumb.label }}</span>
                <svg v-if="i < crumbs.length - 1" class="h-3 w-3 text-ink-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </li>
        </ol>
    </nav>
</template>
