<script setup>
import { ref } from 'vue';

// Uses the official EHC asset if present at /images/ehc-logo.svg (drop the file there — no rebuild
// needed); otherwise falls back to a faithful vector recreation of the EHC 5-point "folded" star
// with the central heart-rhythm motif, in the brand blue→teal.
const failed = ref(false);

// folded 5-point star: each arm = a light blade + a deeper blade meeting on the spine (pinwheel look)
const light = [
    '50,50 40,36.2 50,5', '50,50 60,36.2 92.8,36.1', '50,50 66.2,55.3 76.5,86.4',
    '50,50 50,67 23.5,86.4', '50,50 33.8,55.3 7.2,36.1',
];
const dark = [
    '50,50 50,5 60,36.2', '50,50 92.8,36.1 66.2,55.3', '50,50 76.5,86.4 50,67',
    '50,50 23.5,86.4 33.8,55.3', '50,50 7.2,36.1 40,36.2',
];
</script>

<template>
    <img v-if="!failed" src="/images/ehc-logo.svg" alt="Eastern Health Cluster" @error="failed = true" />
    <svg v-else viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Eastern Health Cluster">
        <polygon v-for="(p, i) in light" :key="`l${i}`" :points="p" fill="#2f97c4" />
        <polygon v-for="(p, i) in dark" :key="`d${i}`" :points="p" fill="#176fa6" />
        <circle cx="50" cy="50" r="13" fill="#fff" />
        <polyline points="42,50 45.5,50 47.5,44 50,57 52,47 54.5,50 58,50" fill="none"
            stroke="#009ca6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</template>
