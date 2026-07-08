<script setup>
// zxcvbn strength meter (legacy parity: the old app gated registration/reset on a strong
// password). The library is ~400 KB, so it is LAZY-loaded on first keystroke via a dynamic
// import — Vite splits it into its own chunk and the main bundle stays lean.
import { ref, watch } from 'vue';

const props = defineProps({ password: { type: String, default: '' } });
const emit = defineEmits(['score']);

const score = ref(0);
let zxcvbn = null;

const LABELS = ['Very weak', 'Weak', 'Fair', 'Strong', 'Very strong'];
const BAR = ['bg-danger-500', 'bg-danger-500', 'bg-warning-500', 'bg-success-500', 'bg-success-600'];
const TEXT = ['text-on-danger', 'text-on-danger', 'text-on-warning', 'text-on-success', 'text-on-success'];

watch(() => props.password, async (pwd) => {
    if (!pwd) {
        score.value = 0;
        emit('score', 0);
        return;
    }
    if (!zxcvbn) ({ default: zxcvbn } = await import('zxcvbn'));
    if (pwd !== props.password) return;              // stale async result — a newer keystroke won
    score.value = zxcvbn(pwd.slice(0, 72)).score;    // zxcvbn slows sharply on very long input
    emit('score', score.value);
}, { immediate: true });
</script>

<template>
    <div v-if="password" class="mt-2" aria-live="polite">
        <div class="flex gap-1" role="presentation">
            <div v-for="i in 4" :key="i" class="h-1.5 flex-1 rounded-full transition-colors"
                :class="i <= score ? BAR[score] : 'bg-ink-100'"></div>
        </div>
        <p class="mt-1 text-xs font-semibold" :class="TEXT[score]">
            {{ LABELS[score] }}<span v-if="score < 3" class="font-normal text-ink-400"> — must be at least “Strong”</span>
        </p>
    </div>
</template>
