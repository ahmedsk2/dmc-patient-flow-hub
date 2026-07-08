<script setup>
import { ref, computed, watch, useId } from 'vue';

// Uses the official EHC asset if present at /images/ehc-logo.svg — or /images/ehc-logo-mono.svg for
// dark chrome and print — so dropping the file in needs no rebuild. Otherwise renders a vector
// recreation of the EHC 5-point flame star with a central medallion.
//
// `mono` drops the brand gradient and paints the recreation in `currentColor`. That means THE CALLER
// SUPPLIES THE COLOUR AND OWNS THE CONTRAST: render it as dark ink on a light surface, as white on
// dark chrome, or as solid black in print, by setting `color` on (or above) this element. The caller
// must ensure that inherited `color` contrasts with the caller's own backdrop — this component
// cannot know what it is sitting on.
//
// Note this is why AppLayout's sidebar does NOT pass `mono`: the logo there sits in a `bg-card` chip
// (#ffffff light / #13201f dark), not on the navy gradient, and `currentColor` would inherit the
// aside's `text-navy-100` (#cfe9e7) — 1.28:1 on white, i.e. no visible glyph at all. The chip correctly uses the
// full-colour variant. `mono` is for callers that set an appropriate `color` themselves.
const props = defineProps({ mono: { type: Boolean, default: false } });

const failed = ref(false);
const src = computed(() => (props.mono ? '/images/ehc-logo-mono.svg' : '/images/ehc-logo.svg'));
// Re-arm the <img> whenever the URL changes: a different file may exist even if the other 404'd.
// Watching `src` (not `props.mono`) states the actual invariant, and survives a future variant prop.
// Trade-off: if BOTH assets 404 and a caller toggles the variant at runtime, each flip re-issues the
// request, since 404s are not reliably negatively cached. Accepted — `mono` is static at every call
// site.
watch(src, () => { failed.value = false; });

// Scoped per instance: Login.vue mounts two EhcLogo at once (`hidden lg:flex` + `lg:hidden`, both in
// the DOM), and duplicate `id`s are invalid HTML — `url(#id)` would bind whichever came first.
const gradId = `ehcFlame-${useId()}`;

const petals = [0, 72, 144, 216, 288];
</script>

<template>
    <img v-if="!failed" :src="src" alt="Eastern Health Cluster" @error="failed = true" />
    <svg v-else viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Eastern Health Cluster">
        <defs v-if="!mono">
            <linearGradient :id="gradId" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#2f97c4" />
                <stop offset="55%" stop-color="#1f86bf" />
                <stop offset="100%" stop-color="#0e6fa6" />
            </linearGradient>
        </defs>
        <g :fill="mono ? 'currentColor' : `url(#${gradId})`">
            <path
                v-for="a in petals"
                :key="a"
                d="M50,50 C 40,40 36,22 50,7 C 64,22 60,40 50,50 Z"
                :transform="`rotate(${a} 50 50)`"
            />
        </g>
        <circle
            cx="50" cy="50" r="10.5"
            :fill="mono ? 'currentColor' : '#eaf4f4'"
            :fill-opacity="mono ? 0.25 : 1"
            :stroke="mono ? 'currentColor' : '#2f97c4'"
            stroke-width="1.4"
        />
        <circle cx="50" cy="50" r="3.4" :fill="mono ? 'currentColor' : '#7fa8bd'" />
    </svg>
</template>
