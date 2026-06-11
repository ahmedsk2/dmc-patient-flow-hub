<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ columns: Array, preview: Object, rows: String });

const form = useForm({ rows: props.rows || '' });
const doPreview = () => form.post('/import/preview', { preserveScroll: true });

// editable preview: a local copy of the sample rows (full previews only — editing a TRUNCATED
// sample and re-submitting it would silently drop rows beyond the 200 shown)
const editable = computed(() => !!props.preview && !props.preview.truncated);
const editRows = ref([]);
watch(() => props.preview, (p) => {
    editRows.value = p && !p.truncated
        ? p.sample.map((r) => ({ ...r, diagnoses_text: (r.diagnoses || []).join('|') }))
        : [];
}, { immediate: true });

// serialize the EDITED rows back to CSV — store() re-parses and re-validates everything
// server-side, so rows that are still invalid stay excluded
const csvCell = (v) => {
    const s = v === null || v === undefined ? '' : String(v);
    return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
};
const serialize = () => [
    'MRN,Name,Age,Gender,Nationality,AdmitDate,DischargeDate,Outcome,Location,Diagnoses,Consultant,DischargedTo,ClinicalDischargeDate',
    ...editRows.value.map((r) => [r.mrn, r.name, r.age, r.gender, r.nationality, r.admit_date, r.discharge_date,
        r.outcome, r.location, r.diagnoses_text, r.consultant_name, r.discharged_to, r.medical_discharge_date]
        .map(csvCell).join(',')),
].join('\n');

const doImport = () => {
    if (editable.value) form.rows = serialize();
    form.post('/import', { preserveScroll: true, onSuccess: () => form.reset('rows') });
};

const example = 'MRN,Name,Age,Gender,Nationality,AdmitDate,DischargeDate,Outcome,Location,Diagnoses,Consultant,DischargedTo,ClinicalDischargeDate\n3001234,Ahmed Ali,54,M,Saudi,2024-02-01,2024-02-09,Alive,Ward,J18.9|E11.9,Dr Mohammed Hassan,Home,2024-02-07\n3005678,Sara N,33,F,Egypt,2024-03-10,,Alive,ICU\nABC,Bad Row,,,,,,,';
const cell = 'w-full min-w-16 rounded-lg border border-ink-200 bg-white px-1.5 py-1 text-xs outline-none focus:border-brand-500';
</script>

<template>
    <Head title="Bulk Import" />
    <AppLayout title="Bulk Import — historical admissions">
        <div class="mx-auto max-w-4xl space-y-5">
            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <h2 class="font-bold text-ink-800">Paste CSV rows</h2>
                <p class="mt-1 text-sm text-ink-500">One admission per line; header row optional. MRN required (digits ≤11) and a valid admit date; blank discharge date = still active. Columns 10–13 are <strong>optional</strong>: Diagnoses = pipe-separated ICD-10 codes (e.g. <code class="rounded bg-surface px-1">J18.9|E11.9</code>); Consultant is matched by name/username (unmatched imports unassigned, with a warning); ClinicalDischargeDate = Y-m-d. Columns, in order:</p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <span v-for="(c, i) in columns" :key="c" class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="i >= 9 ? 'bg-ink-50 text-ink-500' : 'bg-brand-50 text-brand-700'">{{ i + 1 }}. {{ c }}{{ i >= 9 ? ' (opt.)' : '' }}</span>
                </div>
                <pre class="mt-3 overflow-auto rounded-xl bg-surface p-3 text-xs text-ink-600">{{ example }}</pre>
            </section>

            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <textarea v-model="form.rows" rows="10" placeholder="Paste rows here…"
                    class="w-full rounded-xl border border-ink-200 p-3 font-mono text-xs outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                    :class="{ 'border-danger-500': form.errors.rows }"></textarea>
                <p v-if="form.errors.rows" class="mt-1 text-xs text-danger-600">{{ form.errors.rows }}</p>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <span class="mr-auto text-xs text-ink-400">Preview validates every row first; nothing is written until you confirm.</span>
                    <button @click="doPreview" :disabled="form.processing || !form.rows.trim()" class="rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-ink-700 shadow ring-1 ring-ink-200 transition hover:bg-ink-50 disabled:opacity-50">Preview</button>
                </div>
            </section>

            <!-- preview -->
            <section v-if="preview" class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-bold text-ink-800">Preview</h2>
                    <div class="flex gap-2 text-sm font-semibold">
                        <span class="rounded-full bg-success-100 px-3 py-1 text-success-600">{{ preview.valid }} valid</span>
                        <span v-if="preview.invalid" class="rounded-full bg-danger-100 px-3 py-1 text-danger-600">{{ preview.invalid }} invalid</span>
                    </div>
                </div>
                <div class="max-h-96 overflow-auto rounded-xl ring-1 ring-ink-100">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-ink-50 text-left font-semibold uppercase tracking-wide text-ink-400">
                            <tr><th scope="col" class="px-3 py-2">#</th><th scope="col" class="px-3 py-2">MRN</th><th scope="col" class="px-3 py-2">Name</th><th scope="col" class="px-3 py-2">Admit</th><th scope="col" class="px-3 py-2">Discharge</th><th scope="col" class="px-3 py-2">Outcome</th><th scope="col" class="px-3 py-2">Loc</th><th scope="col" class="px-3 py-2">Diagnoses</th><th scope="col" class="px-3 py-2">Consultant</th><th scope="col" class="px-3 py-2">Disch. to</th><th scope="col" class="px-3 py-2">Clin. disch.</th><th scope="col" class="px-3 py-2">Status</th></tr>
                        </thead>
                        <!-- editable preview (full previews): fix typos in place, then confirm — every
                             row is re-validated server-side on import -->
                        <tbody v-if="editable" class="divide-y divide-ink-50">
                            <tr v-for="r in editRows" :key="r.line" :class="r.ok ? (r.warning ? 'bg-accent-300/15' : '') : 'bg-danger-100/40'">
                                <td class="nums px-2 py-1 text-ink-400">{{ r.line }}</td>
                                <td class="px-1 py-1"><input v-model="r.mrn" inputmode="numeric" aria-label="MRN" :class="cell" /></td>
                                <td class="px-1 py-1"><input v-model="r.name" aria-label="Name" :class="[cell, 'min-w-28']" /></td>
                                <td class="px-1 py-1"><input v-model="r.admit_date" placeholder="Y-m-d" aria-label="Admit date" :class="[cell, 'min-w-24']" /></td>
                                <td class="px-1 py-1"><input v-model="r.discharge_date" placeholder="Y-m-d" aria-label="Discharge date" :class="[cell, 'min-w-24']" /></td>
                                <td class="px-1 py-1"><input v-model="r.outcome" aria-label="Outcome" :class="cell" /></td>
                                <td class="px-1 py-1"><input v-model="r.location" aria-label="Location" :class="cell" /></td>
                                <td class="px-1 py-1"><input v-model="r.diagnoses_text" placeholder="A00.1|B00.2" aria-label="Diagnoses (pipe-separated ICD-10 codes)" :class="[cell, 'min-w-24']" /></td>
                                <td class="px-1 py-1"><input v-model="r.consultant_name" aria-label="Consultant" :class="[cell, 'min-w-24', r.consultant_name && !r.consultant_id && 'border-accent-500']" /></td>
                                <td class="px-1 py-1"><input v-model="r.discharged_to" aria-label="Discharged to" :class="cell" /></td>
                                <td class="px-1 py-1"><input v-model="r.medical_discharge_date" placeholder="Y-m-d" aria-label="Clinical discharge date" :class="[cell, 'min-w-24']" /></td>
                                <td class="px-3 py-1">
                                    <span v-if="!r.ok" class="font-semibold text-danger-600">{{ r.error }}</span>
                                    <span v-else-if="r.warning" class="font-semibold text-accent-600">{{ r.warning }}</span>
                                    <span v-else class="font-semibold text-success-600">OK</span>
                                </td>
                            </tr>
                        </tbody>
                        <!-- read-only fallback for truncated (>200-row) previews -->
                        <tbody v-else class="divide-y divide-ink-50">
                            <tr v-for="r in preview.sample" :key="r.line" :class="r.ok ? (r.warning ? 'bg-accent-300/15' : '') : 'bg-danger-100/40'">
                                <td class="nums px-3 py-1.5 text-ink-400">{{ r.line }}</td>
                                <td class="nums px-3 py-1.5">{{ r.mrn || '—' }}</td>
                                <td class="px-3 py-1.5">{{ r.name || '—' }}</td>
                                <td class="nums px-3 py-1.5">{{ r.admit_date || '—' }}</td>
                                <td class="nums px-3 py-1.5">{{ r.discharge_date || '—' }}</td>
                                <td class="px-3 py-1.5">{{ r.outcome || '—' }}</td>
                                <td class="px-3 py-1.5">{{ r.location }}</td>
                                <td class="nums px-3 py-1.5">{{ (r.diagnoses || []).join(' | ') || '—' }}</td>
                                <td class="px-3 py-1.5">
                                    <span v-if="r.consultant_id" class="text-success-600">{{ r.consultant_name }}</span>
                                    <span v-else-if="r.consultant_name" class="text-accent-600">{{ r.consultant_name }}?</span>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-3 py-1.5">{{ r.discharged_to || '—' }}</td>
                                <td class="nums px-3 py-1.5">{{ r.medical_discharge_date || '—' }}</td>
                                <td class="px-3 py-1.5">
                                    <span v-if="!r.ok" class="font-semibold text-danger-600">{{ r.error }}</span>
                                    <span v-else-if="r.warning" class="font-semibold text-accent-600">{{ r.warning }}</span>
                                    <span v-else class="font-semibold text-success-600">OK</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="preview.truncated" class="mt-2 text-xs text-ink-400">Showing the first 200 of all rows (counts above cover everything). Inline editing is disabled for pastes this large — fix the source text instead, so no unseen rows are lost.</p>
                <p v-else class="mt-2 text-xs text-ink-400">Cells are editable — fix typos here, then confirm. Every row is re-validated on import; statuses shown reflect the last preview.</p>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <span class="mr-auto text-xs text-ink-400">Invalid rows are skipped automatically.</span>
                    <button @click="doImport" :disabled="form.processing || (editable ? !editRows.length : !preview.valid)" class="rounded-xl bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-brand-700 disabled:opacity-50">
                        {{ form.processing ? 'Importing…' : (editable ? `Confirm import (${editRows.length} row${editRows.length === 1 ? '' : 's'})` : `Confirm import (${preview.valid})`) }}
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
