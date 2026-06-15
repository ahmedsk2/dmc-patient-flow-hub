<script setup>
import { ref, watch } from 'vue';
import { FIELD } from '@/lib/ui.js';

/**
 * ICD-10 async typeahead — one debounced /api/icd10 lookup shared by every diagnosis picker.
 * Keyboard: ArrowUp/ArrowDown move the highlight, Enter selects it, Esc closes the dropdown
 * (and stops there — a second Esc bubbles up to close the surrounding modal).
 */
const props = defineProps({
    placeholder: { type: String, default: 'Search ICD-10 (≥2 chars)…' },
    inputClass: { type: String, default: FIELD },
});
const emit = defineEmits(['select']);

const query = ref('');
const results = ref([]);
const hi = ref(-1);
let timer = null;

watch(query, (q) => {
    clearTimeout(timer);
    if (q.trim().length < 2) { results.value = []; hi.value = -1; return; }
    timer = setTimeout(async () => {
        results.value = await (await fetch(`/api/icd10?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' } })).json();
        hi.value = results.value.length ? 0 : -1;
    }, 250);
});

const close = () => { results.value = []; hi.value = -1; };
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
