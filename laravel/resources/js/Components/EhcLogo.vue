<script setup>
import { ref } from 'vue';

// Uses the official EHC asset if present at /images/ehc-logo.svg (drop the file there — no rebuild
// needed); otherwise renders a vector recreation of the EHC 5-point flame star with a central
// medallion, in the brand blue→teal.
const failed = ref(false);
const petals = [0, 72, 144, 216, 288];
</script>

<template>
    <img v-if="!failed" src="/images/ehc-logo.svg" alt="Eastern Health Cluster" @error="failed = true" />
    <svg v-else viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Eastern Health Cluster">
        <defs>
            <linearGradient id="ehcFlame" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#2f97c4" />
                <stop offset="55%" stop-color="#1f86bf" />
                <stop offset="100%" stop-color="#0e6fa6" />
            </linearGradient>
        </defs>
        <g fill="url(#ehcFlame)">
            <path v-for="a in petals" :key="a" d="M50,50 C 40,40 36,22 50,7 C 64,22 60,40 50,50 Z"
                :transform="`rotate(${a} 50 50)`" />
        </g>
        <circle cx="50" cy="50" r="10.5" fill="#eaf4f4" stroke="#2f97c4" stroke-width="1.4" />
        <circle cx="50" cy="50" r="3.4" fill="#7fa8bd" />
    </svg>
</template>
