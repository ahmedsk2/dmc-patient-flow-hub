<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { xsrf } from '@/lib/ui.js';

/**
 * Phase 4 — Item 9: admin patient-merge / MRN-dedup tooling. A two-panel interface: pick a SOURCE
 * patient (the duplicate to retire) and a TARGET (the canonical record), preview the history that
 * will move, optionally choose which demographic fields are canonical, then confirm. The confirm
 * POST is step-up gated server-side; an out-of-window admin is bounced to /stepup, re-auths, and
 * comes back to retry. The "Possible duplicates" worklist pre-fills source/target on click.
 */
const props = defineProps({
    possibleDuplicates: { type: Array, default: () => [] },
});

// ---- patient typeahead (one per panel) --------------------------------------------------------
const source = ref(null);
const target = ref(null);

const preview = ref(null);       // { source: {...}, target: {...} } from /admin/patient-merge/search
const previewError = ref('');

// canonical demographic overrides: which side wins per field. Default = target (canonical).
const demoChoice = ref({ name: 'target', gender: 'target', age: 'target', nationality: 'target' });
const demoFields = [
    { key: 'name', label: 'Name' },
    { key: 'gender', label: 'Gender' },
    { key: 'age', label: 'Age' },
    { key: 'nationality', label: 'Nationality' },
];

const form = useForm({
    source_id: null,
    target_id: null,
    canonical_demographics: {},
});

const pickSource = (p) => { source.value = p; preview.value = null; };
const pickTarget = (p) => { target.value = p; preview.value = null; };

const sameSelected = computed(() => source.value && target.value && source.value.id === target.value.id);
const bothOpen = computed(() =>
    preview.value && preview.value.source.has_open_admission && preview.value.target.has_open_admission);

const loadPreview = async () => {
    previewError.value = '';
    preview.value = null;
    if (!source.value || !target.value) { previewError.value = 'Choose a source and a target patient first.'; return; }
    if (sameSelected.value) { previewError.value = 'Source and target must be different patients.'; return; }
    const res = await fetch('/admin/patient-merge/search', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify({ source_id: source.value.id, target_id: target.value.id }),
    });
    if (!res.ok) { previewError.value = 'Could not load the merge preview.'; return; }
    preview.value = await res.json();
    // reset overrides to keep-target for every field
    demoChoice.value = { name: 'target', gender: 'target', age: 'target', nationality: 'target' };
};

// build canonical_demographics: only fields where the admin picked SOURCE become overrides
const buildOverrides = () => {
    const out = {};
    if (!preview.value) return out;
    for (const f of demoFields) {
        if (demoChoice.value[f.key] === 'source') {
            const v = preview.value.source[f.key];
            if (v !== null && v !== '' && v !== undefined) out[f.key] = v;
        }
    }
    return out;
};

const confirmMerge = () => {
    if (!preview.value) return;
    const msg = `Merge patient #${preview.value.source.id} (${preview.value.source.mrn}) into `
        + `#${preview.value.target.id} (${preview.value.target.mrn})?\n\n`
        + `${preview.value.source.admissions} admission(s) and ${preview.value.source.consultations} consultation(s) `
        + `will move to the target, and the source will be retired (recoverable from Recently Deleted).`;
    if (!window.confirm(msg)) return;

    form.source_id = preview.value.source.id;
    form.target_id = preview.value.target.id;
    form.canonical_demographics = buildOverrides();
    form.post('/admin/patient-merge', {
        preserveScroll: true,
        onSuccess: () => { source.value = null; target.value = null; preview.value = null; },
    });
};

// pre-fill both sides from a duplicate-finder row, then load the preview
const useDuplicate = (d) => {
    source.value = { id: d.id1, mrn: d.mrn1, name: d.name1 };
    target.value = { id: d.id2, mrn: d.mrn2, name: d.name2 };
    loadPreview();
    if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'smooth' });
};

const card = 'overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line';
const th = 'px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-400';
const td = 'px-5 py-3 text-sm text-ink-700';
const btn = 'rounded-xl px-4 py-2 text-sm font-semibold transition disabled:opacity-40 disabled:cursor-not-allowed';
</script>

<template>
    <AppLayout title="Patient Merge / MRN Dedup" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Data Management' },
        { label: 'Patient Merge' },
    ]">
        <p class="mb-5 max-w-3xl text-sm text-ink-400">
            Merge a duplicate patient record into a canonical one. All of the <strong>source</strong> patient's
            admissions and consultations are re-pointed onto the <strong>target</strong> in a single transaction,
            then the source is retired (recoverable from Recently Deleted). This is a high-risk, identity-changing
            action and requires a recent re-authentication.
        </p>

        <!-- two-panel pickers -->
        <div class="grid gap-5 lg:grid-cols-2">
            <PatientPicker label="Source (will be retired)" tone="danger" :picked="source" @pick="pickSource" />
            <PatientPicker label="Target (canonical record)" tone="brand" :picked="target" @pick="pickTarget" />
        </div>

        <div class="mt-4 flex items-center gap-3">
            <button :class="[btn, 'bg-brand-600 text-white hover:bg-brand-700']"
                :disabled="!source || !target || sameSelected" @click="loadPreview">Preview merge</button>
            <span v-if="sameSelected" class="text-sm text-on-danger">Source and target must be different patients.</span>
            <span v-if="previewError" class="text-sm text-on-danger">{{ previewError }}</span>
            <span v-if="form.errors.source_id" class="text-sm text-on-danger">{{ form.errors.source_id }}</span>
            <span v-if="form.errors.target_id" class="text-sm text-on-danger">{{ form.errors.target_id }}</span>
        </div>

        <!-- preview / confirm card -->
        <section v-if="preview" :class="[card, 'mt-5 p-6']">
            <h2 class="mb-4 text-sm font-bold uppercase tracking-wide text-ink-700">Merge preview</h2>
            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase text-on-danger">Source · will be retired</p>
                    <p class="nums mt-1 text-lg font-bold text-ink-800">#{{ preview.source.id }} · {{ preview.source.mrn }}</p>
                    <p class="text-sm text-ink-600">{{ preview.source.name || '—' }}</p>
                    <p class="mt-2 nums text-sm text-ink-700">{{ preview.source.admissions }} admission(s) · {{ preview.source.consultations }} consultation(s) will move</p>
                    <p v-if="preview.source.has_open_admission" class="mt-1 text-xs font-semibold text-on-warning">Has an open admission</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-brand-700">Target · canonical</p>
                    <p class="nums mt-1 text-lg font-bold text-ink-800">#{{ preview.target.id }} · {{ preview.target.mrn }}</p>
                    <p class="text-sm text-ink-600">{{ preview.target.name || '—' }}</p>
                    <p class="mt-2 nums text-sm text-ink-700">currently {{ preview.target.admissions }} admission(s) · {{ preview.target.consultations }} consultation(s)</p>
                    <p v-if="preview.target.has_open_admission" class="mt-1 text-xs font-semibold text-on-warning">Has an open admission</p>
                </div>
            </div>

            <!-- W0-T3e. danger-50 is an undeclared step → the callout shipped with NO fill, and
                 danger-600 as text on the bare card is 2.97:1 in dark mode. `bg-tint-danger` +
                 `text-on-danger` is 5.47:1 / 8.08:1 and theme-aware. -->
            <p v-if="bothOpen" class="mt-4 rounded-xl bg-tint-danger px-4 py-3 text-sm font-semibold text-on-danger">
                Both patients have an open admission — merging would leave two simultaneously-open episodes.
                Discharge or transfer one of them before merging.
            </p>

            <!-- canonical demographics: per-field source/target choice -->
            <div class="mt-6">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-400">Canonical demographics</p>
                <div class="overflow-hidden rounded-xl ring-1 ring-line">
                    <table class="w-full">
                        <thead><tr class="border-b border-line bg-ink-50">
                            <th :class="th">Field</th><th :class="th">Source</th><th :class="th">Target</th><th :class="th">Keep</th>
                        </tr></thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="f in demoFields" :key="f.key">
                                <td :class="[td, 'font-semibold']">{{ f.label }}</td>
                                <td :class="td">{{ preview.source[f.key] ?? '—' }}</td>
                                <td :class="td">{{ preview.target[f.key] ?? '—' }}</td>
                                <td :class="td">
                                    <label class="mr-3 inline-flex items-center gap-1 text-xs">
                                        <input type="radio" :value="'target'" v-model="demoChoice[f.key]" /> Target
                                    </label>
                                    <label class="inline-flex items-center gap-1 text-xs">
                                        <input type="radio" :value="'source'" v-model="demoChoice[f.key]" /> Source
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button :class="[btn, 'bg-danger-600 text-white hover:bg-danger-700']"
                    :disabled="bothOpen || form.processing" @click="confirmMerge">Confirm merge</button>
                <span class="text-xs text-ink-400">A re-authentication prompt may appear before the merge runs.</span>
            </div>
        </section>

        <!-- possible duplicates worklist -->
        <section :class="[card, 'mt-8']">
            <div class="border-b border-line px-5 py-3">
                <h2 class="text-sm font-bold uppercase tracking-wide text-ink-700">Possible duplicates</h2>
                <p class="mt-0.5 text-xs text-ink-400">Same normalized MRN (leading-zero / noise) or a NOMRN placeholder matching a real patient's name.</p>
            </div>
            <table class="w-full">
                <thead><tr class="border-b border-line">
                    <th :class="th" scope="col">Patient A</th>
                    <th :class="th" scope="col">Patient B</th>
                    <th :class="th" scope="col">Why</th>
                    <th :class="th" scope="col"></th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(d, i) in possibleDuplicates" :key="i">
                        <td :class="td"><span class="nums font-semibold">{{ d.mrn1 }}</span> · {{ d.name1 || '—' }} <span class="text-ink-400">#{{ d.id1 }}</span></td>
                        <td :class="td"><span class="nums font-semibold">{{ d.mrn2 }}</span> · {{ d.name2 || '—' }} <span class="text-ink-400">#{{ d.id2 }}</span></td>
                        <td :class="[td, 'text-ink-400']">{{ d.reason === 'nomrn-name-match' ? 'NOMRN ~ name' : 'normalized MRN' }}</td>
                        <td :class="td">
                            <button :class="[btn, 'bg-brand-50 text-brand-700 hover:bg-brand-100']" @click="useDuplicate(d)">Merge…</button>
                        </td>
                    </tr>
                    <tr v-if="!possibleDuplicates.length"><td :class="[td, 'text-on-success']" colspan="4">No obvious duplicate patients found.</td></tr>
                </tbody>
            </table>
        </section>
    </AppLayout>
</template>

<script>
/**
 * Inline source/target picker — a debounced /api/patients/search typeahead. Defined as a local
 * component (options API export) so the page stays one file; the merge page is the only consumer.
 */
export default {
    components: {
        PatientPicker: {
            props: {
                label: { type: String, required: true },
                tone: { type: String, default: 'brand' },
                picked: { type: Object, default: null },
            },
            emits: ['pick'],
            data: () => ({ query: '', results: [], timer: null }),
            methods: {
                onInput() {
                    clearTimeout(this.timer);
                    const q = this.query.trim();
                    if (q.length < 2) { this.results = []; return; }
                    this.timer = setTimeout(async () => {
                        const res = await fetch(`/api/patients/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                        this.results = res.ok ? await res.json() : [];
                    }, 250);
                },
                choose(p) { this.$emit('pick', p); this.query = ''; this.results = []; },
            },
            template: `
                <section class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line p-5">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide"
                       :class="tone === 'danger' ? 'text-on-danger' : 'text-brand-700'">{{ label }}</p>
                    <div class="relative">
                        <input v-model="query" @input="onInput" role="combobox" aria-autocomplete="list"
                            :aria-expanded="results.length > 0"
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
            `,
        },
    },
};
</script>
