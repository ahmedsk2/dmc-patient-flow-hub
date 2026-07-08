<script setup>
import { ref, computed, watch } from 'vue';

// Uses the official EHC asset if present at /images/ehc-logo.svg — or /images/ehc-logo-mono.svg for
// dark chrome and print — so dropping the file in needs no rebuild. Otherwise renders a vector
// recreation of the EHC 5-point flame star with a central medallion.
//
// `mono` paints the recreation in currentColor (no brand gradient) so it sits correctly on the dark
// teal sidebar and prints as solid ink.
const props = defineProps({ mono: { type: Boolean, default: false } });

const failed = ref(false);
const src = computed(() => (props.mono ? '/images/ehc-logo-mono.svg' : '/images/ehc-logo.svg'));
// A different file may exist even if the other 404'd — re-arm the <img> when the variant flips.
watch(() => props.mono, () => { failed.value = false; });

const petals = [0, 72, 144, 216, 288];
</script>

<template>
    <img v-if="!failed" :src="src" alt="Eastern Health Cluster" @error="failed = true" />
    <svg v-else viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Eastern Health Cluster">
        <defs v-if="!mono">
            <linearGradient id="ehcFlame" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#2f97c4" />
                <stop offset="55%" stop-color="#1f86bf" />
                <stop offset="100%" stop-color="#0e6fa6" />
            </linearGradient>
        </defs>
        <g :fill="mono ? 'currentColor' : 'url(#ehcFlame)'">
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
