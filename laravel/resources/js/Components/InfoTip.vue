<script setup>
import { ref, computed, nextTick, onBeforeUnmount, useId } from 'vue';

// A small "!" info mark — the owner's request after the 2026-09-24 role/UX review: wherever a
// first-time user might not understand a label, badge, figure or setting, put a "!" next to it that
// explains it in plain words. It is a real <button>: the explanation shows on hover and on keyboard
// focus, and a click/tap pins it open (phones have no hover) until Escape, a second tap, or a tap
// anywhere else.
//
// Rules for callers: plain language, at most about 25 words, and only what the code and the docs
// (DATABASE-AND-BEHAVIOR.md, DASHBOARD-AND-STATISTICS-METRICS.md) say — never invent a clinical,
// legal or numeric fact. Give `label` (what the mark is about) so a screen reader announces
// "More information: <label>" rather than a bare "!".
//
// The bubble is teleported to <body> and placed from the button's bounding box, so no overflow-hidden
// card, table or modal can clip it. Placement is written through element.style (the CSSOM), which the
// nonce-only CSP does not police. It sits above modals (z-50) and the confirm dialog (z-[60]), uses
// theme-invariant colours (navy-900 / white) so it reads the same in light and dark mode, and is
// hidden in print.
const props = defineProps({
    text: { type: String, required: true },
    label: { type: String, default: '' },
});

const tipId = `infotip-${useId()}`;
const open = ref(false);
const pinned = ref(false);
const btn = ref(null);
const bubble = ref(null);
const pos = ref({ top: 0, left: 0 });

const accessibleName = computed(() => (props.label ? `More information: ${props.label}` : 'More information'));

const GAP = 6;
const EDGE = 8;

function place() {
    const b = btn.value?.getBoundingClientRect();
    const t = bubble.value?.getBoundingClientRect();
    if (!b || !t) return;
    const vw = window.innerWidth || document.documentElement.clientWidth;
    const vh = window.innerHeight || document.documentElement.clientHeight;
    let left = b.left + b.width / 2 - t.width / 2;
    left = Math.max(EDGE, Math.min(left, vw - t.width - EDGE));
    let top = b.bottom + GAP;
    if (top + t.height > vh - EDGE && b.top - GAP - t.height >= EDGE) {
        top = b.top - GAP - t.height;   // not enough room below: flip above
    }
    pos.value = { top: Math.round(top), left: Math.round(left) };
}

function onViewportChange() {
    if (open.value) place();
}

function onOutside(e) {
    if (btn.value?.contains(e.target) || bubble.value?.contains(e.target)) return;
    hide(true);
}

function onKey(e) {
    // Dismiss only; focus stays where it is (on the button when opened by focus). Moving focus back
    // to the button here would fire its focus handler and reopen the bubble at once.
    if (e.key === 'Escape' && open.value) {
        hide(true);
    }
}

// The viewport-size event name is assembled in two parts so Tailwind's source scan does not mint an
// unused utility from the bare word (CLAUDE.md §13: de-spell class-like tokens in strings).
const VIEWPORT_SIZE_EVENT = ['re', 'size'].join('');

function listen(on) {
    const fn = on ? 'addEventListener' : 'removeEventListener';
    window[fn]('scroll', onViewportChange, true);
    window[fn](VIEWPORT_SIZE_EVENT, onViewportChange);
    document[fn]('keydown', onKey);
    document[fn]('pointerdown', onOutside, true);
}

async function show() {
    if (open.value) return;
    open.value = true;
    listen(true);
    await nextTick();
    place();
}

function hide(force = false) {
    if (!open.value || (pinned.value && !force)) return;
    open.value = false;
    pinned.value = false;
    listen(false);
}

function toggle() {
    if (open.value && pinned.value) {
        hide(true);
        return;
    }
    pinned.value = true;
    show();
}

onBeforeUnmount(() => listen(false));

defineExpose({ open, pinned });
</script>

<template>
    <span class="inline-flex align-middle print:hidden">
        <button
            ref="btn"
            type="button"
            data-infotip
            class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-ink-300 bg-card text-[10px] font-bold leading-none text-ink-500 hover:border-brand-600 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            :aria-label="accessibleName"
            :aria-describedby="open ? tipId : undefined"
            :aria-expanded="open ? 'true' : 'false'"
            @mouseenter="show"
            @mouseleave="hide()"
            @focus="show"
            @blur="hide()"
            @click.stop.prevent="toggle"
        >!</button>
        <Teleport to="body">
            <span
                v-if="open"
                :id="tipId"
                ref="bubble"
                role="tooltip"
                data-infotip-bubble
                class="fixed z-[70] max-w-xs rounded-md bg-navy-900 px-3 py-2 text-left text-xs font-normal normal-case leading-snug tracking-normal text-white shadow-lg print:hidden"
                :style="{ top: `${pos.top}px`, left: `${pos.left}px` }"
            >{{ text }}</span>
        </Teleport>
    </span>
</template>
