<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ results: Object, filters: Object, outcomes: Array, total: Number });

const form = ref({
    search: props.filters.search || '', from: props.filters.from || '', to: props.filters.to || '',
    outcome: props.filters.outcome || '', location: props.filters.location || '',
});
const apply = () => router.get('/registry', { ...form.value }, { preserveState: true, preserveScroll: true });
const reset = () => { form.value = { search: '', from: '', to: '', outcome: '', location: '' }; apply(); };
const exportUrl = computed(() => '/registry/export?' + new URLSearchParams(Object.fromEntries(Object.entries(form.value).filter(([, v]) => v))).toString());

const field = 'rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
const outcomeTone = (o) => o === 'Dead' ? 'bg-danger-100 text-danger-600' : o === 'Alive' ? 'bg-success-100 text-success-600' : 'bg-ink-100 text-ink-500';
</script>

<template>
    <Head title="Registry" />
    <AppLayout title="Admissions Registry">
        <!-- filters -->
        <div class="mb-5 rounded-2xl bg-white p-4 shadow-card ring-1 ring-ink-100/60">
            <div class="flex flex-wrap items-end gap-3">
                <div class="grow">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">Search</label>
                    <input v-model="form.search" @keyup.enter="apply" :class="[field, 'w-full']" placeholder="Patient name or MRN" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">Admitted from</label>
                    <input v-model="form.from" type="date" :class="field" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">to</label>
                    <input v-model="form.to" type="date" :class="field" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">Outcome</label>
                    <select v-model="form.outcome" :class="field"><option value="">Any</option><option v-for="o in outcomes" :key="o">{{ o }}</option></select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-ink-400">Location</label>
                    <select v-model="form.location" :class="field"><option value="">Any</option><option>Ward</option><option>ICU</option><option>ER</option></select>
                </div>
                <button @click="apply" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">Search</button>
                <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
                <a :href="exportUrl" class="ml-auto inline-flex items-center gap-2 rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-success-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                    Export CSV
                </a>
            </div>
        </div>

        <div class="mb-2 text-sm text-ink-400"><span class="nums font-semibold text-ink-600">{{ total }}</span> matching admission episodes</div>

        <div class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th class="px-5 py-3">Patient</th><th class="px-3 py-3">Age/Sex</th><th class="px-3 py-3">Location</th>
                        <th class="px-3 py-3">Consultant</th><th class="px-3 py-3">Admitted</th><th class="px-3 py-3">Discharged</th>
                        <th class="px-3 py-3">LOS</th><th class="px-3 py-3">Outcome</th><th class="px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="r in results.data" :key="r.id" class="transition hover:bg-brand-50/40">
                        <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ r.name }}</div><div class="nums text-xs text-ink-400">MRN {{ r.mrn }}</div></td>
                        <td class="nums px-3 py-3 text-ink-600">{{ r.age ?? '—' }} · {{ (r.gender||'—').slice(0,1) }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ r.location || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ r.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ r.admit_date || '—' }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ r.discharge_date || '—' }}</td>
                        <td class="nums px-3 py-3 text-ink-600">{{ r.los !== null ? r.los + 'd' : '—' }}</td>
                        <td class="px-3 py-3"><span v-if="r.outcome" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="outcomeTone(r.outcome)">{{ r.outcome }}</span><span v-else class="text-ink-300">—</span></td>
                        <td class="px-5 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="r.status === 'Active' ? 'bg-brand-100 text-brand-700' : 'bg-ink-100 text-ink-500'">{{ r.status }}</span></td>
                    </tr>
                    <tr v-if="!results.data.length"><td colspan="9" class="px-5 py-10 text-center text-ink-400">No admissions match your search.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="results.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ results.from }}–{{ results.to }} of {{ results.total }}</span>
            <div class="flex gap-1">
                <component :is="l.url ? Link : 'span'" v-for="l in results.links" :key="l.label" :href="l.url || undefined" preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition"
                    :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-white text-ink-600 ring-1 ring-ink-100 hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" />
            </div>
        </div>
    </AppLayout>
</template>
