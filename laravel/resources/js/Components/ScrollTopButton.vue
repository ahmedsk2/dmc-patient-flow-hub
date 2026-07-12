<script setup>
// Global "back to top" affordance. Mounted once in AppLayout, so it rides on every page. The window
// itself is what scrolls (AppLayout's <main> grows the page; nothing sets an inner overflow), so it
// keys on window.scrollY and calls window.scrollTo. Hidden until the reader is meaningfully scrolled
// (past ~1.5 viewport-worth ≈ 400px) so it never covers content on short pages.
//
// Position/stacking (deliberate — avoids two overlaps):
//  - Lifted to `bottom-24` so it clears the flash toast's own `bottom-6 end-6` corner. The toast fires
//    on nearly every mutating action; at `bottom-6` it (z-50) buried the button for the toast's ~4.5s.
//  - `z-20`: ABOVE page content but BELOW every transient overlay — the mobile-nav drawer backdrop
//    (z-30), the notification-bell backdrop (z-40), the toast (z-50) and the session dialog (z-90) —
//    so opening the drawer while scrolled correctly covers + disables the button.
import { ref, onMounted, onUnmounted } from 'vue';

// Show once past this many pixels. A little over one fold — enough that "top" is a real journey.
const THRESHOLD = 400;
const visible = ref(false);

const onScroll = () => { visible.value = window.scrollY > THRESHOLD; };

const toTop = () => {
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
};

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();   // reflect the initial position (e.g. a restored deep scroll on back-nav)
});
onUnmounted(() => window.removeEventListener('scroll', onScroll));
</script>

<template>
    <Transition
        enter-active-class="transition duration-200" enter-from-class="translate-y-3 opacity-0"
        leave-active-class="transition duration-200" leave-to-class="translate-y-3 opacity-0">
        <button v-if="visible" type="button" @click="toTop" title="Back to top" aria-label="Back to top"
            class="fixed bottom-24 end-6 z-20 grid h-11 w-11 coarse:h-12 coarse:w-12 place-items-center rounded-full bg-card text-ink-500 shadow-lg ring-1 ring-line transition hover:bg-ink-50 hover:text-ink-700">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
            </svg>
        </button>
    </Transition>
</template>
