<script setup>
import { ref } from 'vue';
import { xsrf } from '@/lib/ui.js';

/**
 * Patient search/pick control used by the two source/target panels on Admin → Patient Merge.
 * Moved out of PatientMerge.vue's inline options-API `template:` string (2026-09-23): the
 * production bundle ships Vue's runtime-only build (no template compiler, and CSP forbids
 * 'unsafe-eval' anyway), so that string rendered as a bare comment in prod while Vitest — whose
 * Vue build DOES include the compiler — never noticed. A real .vue SFC compiles ahead of time,
 * so this works under both. Behaviour is unchanged from the original inline component.
 */
defineProps({
    label: { type: String, required: true },
    tone: { type: String, default: 'brand' },
    picked: { type: Object, default: null },
});
const emit = defineEmits(['pick']);

const query = ref('');
const results = ref([]);
let timer = null;

const onInput = () => {
    clearTimeout(timer);
    const q = query.value.trim();
    if (q.length < 2) { results.value = []; return; }
    timer = setTimeout(async () => {
        // SPC-TM-011 (Wave 1): the name/MRN term rides the POST body, never a URL
        const res = await fetch('/api/patients/search', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ q }),
        });
        results.value = res.ok ? await res.json() : [];
    }, 250);
};

const choose = (p) => { emit('pick', p); query.value = ''; results.value = []; };
</script>

<template>
    <section class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line p-5">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide"
           :class="tone === 'danger' ? 'text-on-danger' : 'text-brand-700'">{{ label }}</p>
        <div class="relative">
            <input v-model="query" @input="onInput" role="combobox" aria-autocomplete="list"
                :aria-expanded="results.length > 0" autocomplete="off"
                class="w-full rounded-xl border border-ink-200 bg-card px-3.5 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                placeholder="Search MRN or name (≥2 chars)…" />
            <ul v-if="results.length" role="listbox"
                class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-line bg-card py-1 shadow-lg">
                <li v-for="p in results" :key="p.id" role="option"
                    @mousedown.prevent="choose(p)"
                    class="flex cursor-pointer items-center justify-between px-3 py-1.5 text-sm hover:bg-brand-50">
                    <span><span class="nums font-semibold text-brand-700">{{ p.mrn }}</span> · {{ p.name || '—' }}</span>
                    <span v-if="p.open_admissions_count > 0" class="ml-2 rounded-full bg-tint-warning px-2 py-0.5 text-xs font-semibold text-on-warning">open</span>
                </li>
            </ul>
        </div>
        <div v-if="picked" class="mt-3 rounded-xl bg-ink-50 px-3.5 py-2.5 text-sm">
            Selected: <span class="nums font-semibold">{{ picked.mrn }}</span> · {{ picked.name || '—' }}
            <span class="text-ink-400">#{{ picked.id }}</span>
        </div>
    </section>
</template>
