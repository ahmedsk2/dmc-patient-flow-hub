<script setup>
// Wave 2, Item 2: global patient quick-jump. Press "/" anywhere to open, type ≥2 chars (debounced),
// pick a result (or Enter on the first) to navigate. Results come from the role-scoped
// /api/patients/quick-search endpoint (server enforces PHI scope). Keyboard-first + focus return.
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';

const trigger = ref(null);   // the header button — focus returns here on close (a11y)
const input = ref(null);
const open = ref(false);
const q = ref('');
const results = ref([]);
const busy = ref(false);
const active = ref(0);   // keyboard-highlighted result index
let timer = null;

const focusInput = () => { open.value = true; nextTick(() => input.value?.focus()); };
const close = () => {
    open.value = false; q.value = ''; results.value = []; active.value = 0;
    nextTick(() => trigger.value?.focus());   // return focus to the opener
};

// "/" global shortcut — ignore when focus is inside a field/contenteditable (so typing "/" works).
const onKey = (e) => {
    if (e.key !== '/' || open.value) return;
    const tag = document.activeElement?.tagName;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.activeElement?.isContentEditable) return;
    e.preventDefault();
    focusInput();
};
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => { window.removeEventListener('keydown', onKey); clearTimeout(timer); });

watch(q, (v) => {
    clearTimeout(timer);
    active.value = 0;
    if (v.trim().length < 2) { results.value = []; return; }
    timer = setTimeout(async () => {
        busy.value = true;
        try {
            const r = await fetch(`/api/patients/quick-search?q=${encodeURIComponent(v.trim())}`,
                { headers: { Accept: 'application/json' } });
            results.value = r.ok ? await r.json() : [];
        } catch { results.value = []; } finally { busy.value = false; }
    }, 250);
});

const go = (item) => { close(); router.visit(item.href); };
const onArrow = (dir) => {
    if (!results.value.length) return;
    active.value = (active.value + dir + results.value.length) % results.value.length;
};
const onEnter = () => { if (results.value[active.value]) go(results.value[active.value]); };
</script>

<template>
    <div class="relative" data-tour="quick-jump">
        <button ref="trigger" type="button" @click="focusInput" title="Quick patient search (press /)"
            aria-label="Quick patient search"
            class="hidden items-center gap-2 rounded-xl border border-line bg-card px-3 py-1.5 text-sm text-ink-400 shadow-sm transition hover:border-brand-400 hover:text-ink-600 sm:flex">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
            </svg>
            <span>Search patient…</span>
            <kbd class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] font-bold text-ink-500">/</kbd>
        </button>

        <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-20" @click.self="close">
            <div class="w-full max-w-md rounded-2xl bg-card shadow-2xl ring-1 ring-line"
                 role="dialog" aria-label="Quick patient search" aria-modal="true" @keydown.esc.prevent="close">
                <div class="flex items-center gap-3 border-b border-line px-4 py-3">
                    <svg class="h-5 w-5 shrink-0 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
                    </svg>
                    <input ref="input" v-model="q" placeholder="MRN or patient name…"
                           class="flex-1 bg-transparent text-sm text-ink-800 outline-none placeholder:text-ink-400"
                           aria-label="Patient search query" autocomplete="off"
                           @keydown.down.prevent="onArrow(1)" @keydown.up.prevent="onArrow(-1)"
                           @keydown.enter.prevent="onEnter" />
                    <span v-if="busy" class="text-xs text-ink-400" aria-hidden="true">…</span>
                    <kbd class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] font-bold text-ink-500">Esc</kbd>
                </div>

                <ul v-if="results.length" class="max-h-72 divide-y divide-line overflow-auto py-1" role="listbox">
                    <li v-for="(r, i) in results" :key="r.id" role="option" :aria-selected="i === active">
                        <button type="button" @click="go(r)" @mouseenter="active = i"
                            class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition focus:outline-none"
                            :class="i === active ? 'bg-brand-50/60' : 'hover:bg-brand-50/40'">
                            <span class="nums min-w-[5rem] text-xs font-semibold text-ink-500">{{ r.mrn }}</span>
                            <span class="flex-1 text-sm font-semibold text-ink-800">{{ r.name }}</span>
                            <span v-if="r.consultant" class="hidden text-xs text-ink-400 sm:inline">{{ r.consultant }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                :class="r.status === 'active' ? 'bg-success-100 text-success-600'
                                    : r.status === 'unassigned' ? 'bg-accent-300/40 text-accent-600'
                                    : 'bg-ink-100 text-ink-500'">{{ r.status }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="q.trim().length >= 2 && !busy" class="px-4 py-6 text-center text-sm text-ink-400">No patients found.</p>
                <p v-else-if="q.trim().length < 2" class="px-4 py-4 text-center text-xs text-ink-300">Type at least 2 characters</p>
            </div>
        </div>
    </div>
</template>
