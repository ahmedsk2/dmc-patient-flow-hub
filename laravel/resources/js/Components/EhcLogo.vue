<script setup>
// Eastern Health Cluster — approximated 5-petal star mark in the brand teal.
// (A faithful re-creation, not the official asset; swap in the supplied SVG/PNG when available.)
const petals = [0, 72, 144, 216, 288];
</script>

<template>
    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Eastern Health Cluster">
        <defs>
            <linearGradient id="ehcPetalGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#38b4ba" />
                <stop offset="100%" stop-color="#00565e" />
            </linearGradient>
        </defs>
        <g fill="url(#ehcPetalGrad)">
            <path v-for="a in petals" :key="a" d="M50,44 C39,32 42,16 50,7 C58,16 61,32 50,44 Z" :transform="`rotate(${a} 50 50)`" />
        </g>
        <circle cx="50" cy="50" r="7.5" fill="#a9ded8" />
        <circle cx="50" cy="50" r="3" fill="#00565e" />
    </svg>
</template>
