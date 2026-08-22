<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// W4 — the physician-scoped consultation dashboard. Every figure on this page was already scoped
// server-side by Consultation::scopeVisibleTo, so this page renders exactly what it is handed and
// never re-derives a permission. Admins and coordinators get the specialty picker (canPick).
defineProps({
    canPick: Boolean,
    filters: Object,
    specialties: Array,
    scopeLabel: String,
    openCounts: Object,
    ageing: Object,
    generatedAt: String,
});

const pick = (event) => {
    const value = event.target.value;
    router.get('/consultations/dashboard', value ? { specialty_id: value } : {}, {
        preserveState: true, preserveScroll: true, replace: true,
    });
};

const buckets = [
    { key: 'b0_2', label: '0-2 days', tone: 'bg-tint-success text-on-success' },
    { key: 'b3_7', label: '3-7 days', tone: 'bg-tint-warning text-on-warning' },
    { key: 'b8_plus', label: 'Over 7 days', tone: 'bg-tint-danger text-on-danger' },
    { key: 'unknown', label: 'No request date', tone: 'bg-brand-100 text-brand-700' },
];
</script>

<template>
    <AppLayout title="Consultation Dashboard">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-ink-800">Consultation dashboard</h1>
                <p class="text-sm text-ink-600">{{ scopeLabel }} &middot; as of {{ generatedAt }}</p>
            </div>
            <label v-if="canPick" class="inline-flex items-center gap-2 text-sm font-semibold text-ink-600">
                Specialty
                <select :value="filters.specialty_id ?? ''" @change="pick" aria-label="Narrow the dashboard to one specialty"
                    class="rounded-xl border border-ink-200 bg-card px-3 py-2 text-sm font-normal text-ink-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20">
                    <option value="">All specialties</option>
                    <option v-for="s in specialties" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </label>
        </div>

        <section aria-labelledby="open-heading" class="mb-6">
            <h2 id="open-heading" class="mb-2 text-sm font-semibold text-ink-700">Open consultations</h2>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="k in ['new', 'active', 'ongoing', 'total']" :key="k" class="rounded-xl border border-ink-200 bg-card p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-600">{{ k }}</dt>
                    <dd class="text-2xl font-semibold text-ink-800">{{ openCounts[k] }}</dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="ageing-heading">
            <h2 id="ageing-heading" class="mb-2 text-sm font-semibold text-ink-700">How long they have been open</h2>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="b in buckets" :key="b.key" class="rounded-xl border border-ink-200 bg-card p-4">
                    <dt class="inline-flex rounded-lg px-2 py-1 text-xs font-semibold" :class="b.tone">{{ b.label }}</dt>
                    <dd class="mt-2 text-2xl font-semibold text-ink-800">{{ ageing[b.key] }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-ink-600">
                Age counts from the recorded request time where there is one, otherwise from the consultation date.
                Rows with neither are reported separately rather than being given a date they never had.
            </p>
        </section>
    </AppLayout>
</template>
