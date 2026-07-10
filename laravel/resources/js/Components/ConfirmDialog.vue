<script setup>
import { computed, ref, watch, nextTick } from 'vue';
import { useConfirm } from '@/composables/useConfirm';

// Themed, accessible replacement for window.confirm(). Reads the module singleton from
// useConfirm() — no props. Mounted once in AppLayout (teleported to <body> so it floats above
// every z-50 modal). Cancel is the FIRST tab stop, Confirm the LAST; Tab/Shift+Tab cycle within
// the two buttons; Escape + backdrop click both cancel (resolve false). Focus returns to whatever
// element opened the dialog (captured the moment the dialog appears).
const { state, answer } = useConfirm();

const tone = computed(() => state.value?.tone ?? 'danger');
const danger = computed(() => tone.value === 'danger');

// per-tone classes (spec tone mapping)
const headerClass = computed(() => danger.value ? 'bg-tint-danger text-on-danger' : 'bg-ink-50 text-ink-700');
const confirmClass = computed(() => danger.value
    ? 'bg-danger-600 hover:bg-danger-700 text-white focus-visible:ring-danger-500'
    : 'bg-brand-solid hover:bg-brand-solid-hover text-white focus-visible:ring-brand-500');

const panel = ref(null);
const cancelBtn = ref(null);
let opener = null;

// When a confirmation opens, remember who opened it and move focus to Cancel (the safe default).
watch(() => state.value, async (s) => {
    if (s) {
        opener = document.activeElement;
        await nextTick();
        cancelBtn.value?.focus();
    } else if (opener) {
        // Return focus to the opener after the dialog closes (it may have re-rendered — refocus
        // on the next tick so the element is still connected).
        const el = opener;
        opener = null;
        nextTick(() => el.focus?.());
    }
});

// Esc cancels; Tab/Shift+Tab cycle within Cancel↔Confirm only (a two-stop focus trap).
const onKeydown = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); answer(false); return; }
    if (e.key !== 'Tab' || !panel.value) return;
    const items = [...panel.value.querySelectorAll('button:not([disabled])')];
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
};
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="state" data-test="cd-backdrop"
                class="fixed inset-0 z-[60] grid place-items-center bg-navy-950/50 p-4 backdrop-blur-sm"
                @click.self="answer(false)">
                <div ref="panel" data-test="cd-panel" role="dialog" aria-modal="true" aria-labelledby="cd-title"
                    class="w-full max-w-md overflow-hidden rounded-2xl bg-card shadow-2xl"
                    @keydown="onKeydown">
                    <div class="flex items-start gap-3 px-6 py-4" :class="headerClass">
                        <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path v-if="danger" stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                        </svg>
                        <h3 id="cd-title" class="text-base font-bold">{{ state.title }}</h3>
                    </div>
                    <div class="px-6 py-5">
                        <p class="text-sm leading-relaxed text-ink-600">{{ state.body }}</p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-line px-6 py-4">
                        <button ref="cancelBtn" type="button" data-test="cd-cancel" @click="answer(false)"
                            class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500 ring-1 ring-line transition hover:bg-ink-50">Cancel</button>
                        <button type="button" data-test="cd-confirm" @click="answer(true)"
                            class="rounded-xl px-5 py-2 text-sm font-semibold transition focus-visible:ring-2" :class="confirmClass">Confirm</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
