<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ columns: Array });
const form = useForm({ rows: '' });
const submit = () => form.post('/import', { preserveScroll: true, onSuccess: () => form.reset('rows') });
const example = 'MRN,Name,Age,Gender,Nationality,AdmitDate,DischargeDate,Outcome,Location\n3001234,Ahmed Ali,54,M,Saudi,2024-02-01,2024-02-09,Alive,Ward\n3005678,Sara N,33,F,Egypt,2024-03-10,,Alive,ICU';
</script>

<template>
    <Head title="Bulk Import" />
    <AppLayout title="Bulk Import — historical admissions">
        <div class="mx-auto max-w-3xl space-y-5">
            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <h2 class="font-bold text-ink-800">Paste CSV rows</h2>
                <p class="mt-1 text-sm text-ink-500">One admission episode per line. A header row is optional. MRN is required (digits, ≤11); blank discharge date = still active. Columns, in order:</p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <span v-for="(c, i) in columns" :key="c" class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">{{ i + 1 }}. {{ c }}</span>
                </div>
                <pre class="mt-3 overflow-auto rounded-xl bg-surface p-3 text-xs text-ink-600">{{ example }}</pre>
            </section>

            <section class="rounded-2xl bg-white p-6 shadow-card ring-1 ring-ink-100/60">
                <form @submit.prevent="submit">
                    <textarea v-model="form.rows" rows="12" placeholder="Paste rows here…"
                        class="w-full rounded-xl border border-ink-200 p-3 font-mono text-xs outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        :class="{ 'border-danger-500': form.errors.rows }"></textarea>
                    <p v-if="form.errors.rows" class="mt-1 text-xs text-danger-600">{{ form.errors.rows }}</p>
                    <div class="mt-4 flex items-center justify-end gap-3">
                        <span class="mr-auto text-xs text-ink-400">Imports run in a single transaction; invalid rows are skipped and reported.</span>
                        <button type="submit" :disabled="form.processing || !form.rows.trim()" class="rounded-xl bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-brand-700 disabled:opacity-50">
                            {{ form.processing ? 'Importing…' : 'Import rows' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
