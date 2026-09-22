<script setup>
import { ref, watch } from 'vue';
import { FIELD } from '@/lib/ui.js';

/**
 * ICD-10 async typeahead — one debounced /api/icd10 lookup shared by every diagnosis picker.
 * Keyboard: ArrowUp/ArrowDown move the highlight, Enter selects it, Esc closes the dropdown
 * (and stops there — a second Esc bubbles up to close the surrounding modal).
 */
defineProps({
    placeholder: { type: String, default: 'Search ICD-10 (≥2 chars)…' },
    inputClass: { type: String, default: FIELD },
});
const emit = defineEmits(['select']);

const query = ref('');
const results = ref([]);
const hi = ref(-1);
let timer = null;

// RES-01: a lookup that never answers (a stalled network, a hung web worker behind the per-request
// MAX_EXECUTION_TIME cap) must not leave the field waiting forever. AbortSignal.timeout() cancels
// the fetch after 10s; the abort surfaces as a rejected promise, which the existing catch below
// already treats exactly like any other failed lookup — no dropdown, no throw, current state kept.
const FETCH_TIMEOUT_MS = 10000;

// Lookups race, and a stale answer here is a clinical hazard, not a cosmetic one: an earlier, slower
// lookup would overwrite newer results, and an answer arriving after a pick would reopen the list
// under a clinician's reflexive second Enter — adding a diagnosis nobody chose. Every keystroke,
// pick, clear and loss of focus bumps `generation`; a lookup applies its answer only if nothing has happened
// since it was sent. (IcdTypeahead.spec.js "stale answers are never shown" pins each case.)
let generation = 0;

watch(query, (q) => {
    clearTimeout(timer);
    const mine = ++generation;
    const term = q.trim();
    if (term.length < 2) { results.value = []; hi.value = -1; return; }
    timer = setTimeout(async () => {
        let rows = [];
        try {
            const res = await fetch(`/api/icd10?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(FETCH_TIMEOUT_MS) });
            // a 419 (expired session) or 500 carries an error body, not a list of diagnoses
            if (res.ok) rows = await res.json();
        } catch (e) {
            console.warn('[icd10] lookup failed', e);   // offline, aborted: show nothing rather than throw
        }
        if (mine !== generation) return;
        results.value = Array.isArray(rows) ? rows : [];
        hi.value = results.value.length ? 0 : -1;
    }, 250);
});

// Closing also cancels a lookup that has not been sent yet and orphans one already in flight.
const close = () => { clearTimeout(timer); generation++; results.value = []; hi.value = -1; };
const choose = (d) => { emit('select', d); query.value = ''; close(); };
const onKeydown = (e) => {
    if (!results.value.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); hi.value = Math.min(hi.value + 1, results.value.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi.value = Math.max(hi.value - 1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); if (hi.value >= 0) choose(results.value[hi.value]); }
    else if (e.key === 'Escape') { e.stopPropagation(); close(); }   // first Esc: dropdown only
};
</script>

<template>
    <div class="relative">
        <input v-model="query" :class="inputClass" :placeholder="placeholder" role="combobox"
            :aria-expanded="results.length > 0" aria-autocomplete="list" @keydown="onKeydown" @blur="close" />
        <ul v-if="results.length" role="listbox" class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-line bg-card py-1 shadow-lg">
            <li v-for="(d, i) in results" :key="d.code" role="option" :aria-selected="i === hi"
                @mousedown.prevent="choose(d)" @mouseenter="hi = i"
                class="cursor-pointer px-3 py-1.5 text-sm" :class="i === hi ? 'bg-brand-50' : ''">
                <span class="nums font-semibold text-brand-700">{{ d.code }}</span> · {{ d.name }}
            </li>
        </ul>
    </div>
</template>
