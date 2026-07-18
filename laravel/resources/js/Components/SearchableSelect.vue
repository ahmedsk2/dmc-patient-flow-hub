<script setup>
import { computed, ref } from 'vue';
import { FIELD } from '@/lib/ui.js';

/**
 * SearchableSelect — one picker for option lists too long to scan.
 *
 * Self-adapting: at or below `searchableFrom` options it renders a plain native <select> (on a
 * phone the OS picker beats a filter box for a handful of items); above it, an accessible combobox
 * filtering by case-insensitive SUBSTRING — "ali" finds "Khalid Alizadeh" — not prefix or exact.
 *
 * CLIENT-side only: the arrays it filters (consultants, specialties) already ship with the page.
 * IcdTypeahead.vue stays the SERVER-backed picker for large reference data (~72k ICD-10 rows).
 * Keyboard + ARIA mirror IcdTypeahead so both pickers behave identically, including the
 * first-Esc-closes-the-dropdown-not-the-modal rule.
 */
const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    options: { type: Array, default: () => [] },     // [{ id, name }] — extra keys ignored
    placeholder: { type: String, default: 'Select…' },
    disabled: { type: Boolean, default: false },
    searchableFrom: { type: Number, default: 8 },
    inputClass: { type: String, default: FIELD },
    // Explicit id/aria-describedby props (rather than relying on attribute fallthrough): the
    // component has TWO possible root elements (a <select> or a wrapper <div>), and fallthrough
    // always lands on whichever is the actual root — which is wrong on the combobox branch,
    // where the real control (the <input role="combobox">) is a CHILD of the root <div>. Owning
    // these as props lets us route them onto the real control in both branches explicitly.
    id: { type: String, default: undefined },
    ariaDescribedby: { type: String, default: undefined },
});
defineOptions({ inheritAttrs: false });
const emit = defineEmits(['update:modelValue']);

const searchable = computed(() => props.options.length > props.searchableFrom);
const label = (o) => o?.name ?? '';
const selected = computed(() => props.options.find((o) => String(o.id) === String(props.modelValue)) || null);

const query = ref('');
const open = ref(false);
const hi = ref(-1);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q ? props.options.filter((o) => label(o).toLowerCase().includes(q)) : props.options;
});

const openList = () => { if (props.disabled) return; open.value = true; query.value = ''; hi.value = props.options.length ? 0 : -1; };
const close = () => { open.value = false; hi.value = -1; };
const choose = (o) => { emit('update:modelValue', o.id); close(); };
const onInput = (e) => { query.value = e.target.value; open.value = true; hi.value = filtered.value.length ? 0 : -1; };
const onKeydown = (e) => {
    if (e.key === 'ArrowDown' && !open.value) { e.preventDefault(); openList(); return; }
    if (!open.value || !filtered.value.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); hi.value = Math.min(hi.value + 1, filtered.value.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi.value = Math.max(hi.value - 1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); if (hi.value >= 0) choose(filtered.value[hi.value]); }
    else if (e.key === 'Escape') { e.stopPropagation(); close(); }   // first Esc: dropdown only
};
</script>

<template>
    <!-- short list: the native control is genuinely better (OS picker on mobile) -->
    <select v-if="!searchable" v-bind="$attrs" :id="id" :aria-describedby="ariaDescribedby" :value="modelValue" :disabled="disabled" :class="inputClass"
            @change="emit('update:modelValue', $event.target.value)">
        <option value="">{{ placeholder }}</option>
        <option v-for="o in options" :key="o.id" :value="o.id">{{ label(o) }}</option>
    </select>

    <div v-else class="relative">
        <input v-bind="$attrs" :id="id" :aria-describedby="ariaDescribedby" :value="open ? query : (selected ? label(selected) : '')" :class="inputClass" :placeholder="placeholder"
               :disabled="disabled" role="combobox" :aria-expanded="open" aria-autocomplete="list"
               @input="onInput" @focus="openList" @keydown="onKeydown" @blur="close" />
        <ul v-if="open && filtered.length" role="listbox"
            class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-line bg-card py-1 shadow-lg">
            <!-- leading "clear" row — only while the query is empty, so it never pollutes search
                 results. The native <select> branch always offers a blank "no selection" option;
                 this is the combobox branch's equivalent (e.g. Registry's consultant filter needs an
                 "all consultants" state, and once a value was picked there was no way back to none). -->
            <li v-if="!query.trim()" role="option" :aria-selected="false"
                @mousedown.prevent="choose({ id: '' })"
                class="cursor-pointer px-3 py-1.5 text-sm text-ink-400" :class="hi === -1 ? 'bg-brand-50' : ''">{{ placeholder }}</li>
            <li v-for="(o, i) in filtered" :key="o.id" role="option" :aria-selected="i === hi"
                @mousedown.prevent="choose(o)" @mouseenter="hi = i"
                class="cursor-pointer px-3 py-1.5 text-sm" :class="i === hi ? 'bg-brand-50' : ''">{{ label(o) }}</li>
        </ul>
        <p v-else-if="open" class="absolute z-10 mt-1 w-full rounded-xl border border-line bg-card px-3 py-2 text-sm text-ink-400 shadow-lg">No matches</p>
    </div>
</template>
