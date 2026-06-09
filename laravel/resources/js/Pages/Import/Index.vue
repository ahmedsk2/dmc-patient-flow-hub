<script setup>
import { useForm } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ columns: Array, preview: Object, rows: String });

const form = useForm({ rows: props.rows || '' });
const doPreview = () => form.post('/import/preview', { preserveScroll: true });
const doImport = () => form.post('/import', { preserveScroll: true, onSuccess: () => form.reset('rows') });

const example = 'MRN,Name,Age,Gender,Nationality,AdmitDate,DischargeDate,Outcome,Location\n3001234,Ahmed Ali,54,M,Saudi,2024-02-01,2024-02-09,Alive,Ward\n3005678,Sara N,33,F,Egypt,2024-03-10,,Alive,ICU\nABC,Bad Row,,,,,,,';
</script>

<template>
    <Head title="Bulk Import" />
    <AppLayout title="Bulk Import — historical admissions">
        <div class="mx-auto max-w-4xl space-y-5">
            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <h2 class="font-bold text-ink-800">Paste CSV rows</h2>
                <p class="mt-1 text-sm text-ink-500">One admission per line; header row optional. MRN required (digits ≤11) and a valid admit date; blank discharge date = still active. Columns, in order:</p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <span v-for="(c, i) in columns" :key="c" class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">{{ i + 1 }}. {{ c }}</span>
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
                            <tr><th class="px-3 py-2">#</th><th class="px-3 py-2">MRN</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">Admit</th><th class="px-3 py-2">Discharge</th><th class="px-3 py-2">Outcome</th><th class="px-3 py-2">Loc</th><th class="px-3 py-2">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-ink-50">
                            <tr v-for="r in preview.sample" :key="r.line" :class="r.ok ? '' : 'bg-danger-100/40'">
                                <td class="nums px-3 py-1.5 text-ink-400">{{ r.line }}</td>
                                <td class="nums px-3 py-1.5">{{ r.mrn || '—' }}</td>
                                <td class="px-3 py-1.5">{{ r.name || '—' }}</td>
                                <td class="nums px-3 py-1.5">{{ r.admit_date || '—' }}</td>
                                <td class="nums px-3 py-1.5">{{ r.discharge_date || '—' }}</td>
                                <td class="px-3 py-1.5">{{ r.outcome || '—' }}</td>
                                <td class="px-3 py-1.5">{{ r.location }}</td>
                                <td class="px-3 py-1.5">
                                    <span v-if="r.ok" class="font-semibold text-success-600">OK</span>
                                    <span v-else class="font-semibold text-danger-600">{{ r.error }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="preview.truncated" class="mt-2 text-xs text-ink-400">Showing the first 200 rows; counts above cover all rows.</p>
                <div class="mt-4 flex items-center justify-end gap-3">
                    <span class="mr-auto text-xs text-ink-400">Invalid rows are skipped automatically.</span>
                    <button @click="doImport" :disabled="form.processing || !preview.valid" class="rounded-xl bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-brand-700 disabled:opacity-50">
                        {{ form.processing ? 'Importing…' : `Confirm import (${preview.valid})` }}
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
