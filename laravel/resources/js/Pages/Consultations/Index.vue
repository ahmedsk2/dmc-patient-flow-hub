<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ consultations: Object, filters: Object, stats: Object });
const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'active');
let timer = null;

const apply = () => router.get('/consultations', { search: search.value || undefined, status: status.value },
    { preserveState: true, replace: true, preserveScroll: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
const setStatus = (s) => { status.value = s; apply(); };
</script>

<template>
    <Head title="Consultations" />
    <AppLayout title="Consultations">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex gap-2">
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Active <span class="nums ml-1 text-accent-600">{{ stats.active }}</span></span>
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Total <span class="nums ml-1 text-ink-600">{{ stats.total }}</span></span>
            </div>
            <div class="relative ml-auto">
                <svg class="pointer-events-none absolute left-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" placeholder="Search name or MRN…" class="w-64 rounded-xl border border-ink-200 bg-white py-2 pl-10 pr-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <div class="flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100">
                <button v-for="s in [['active','Active'],['signed','Signed off'],['all','All']]" :key="s[0]" @click="setStatus(s[0])"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition" :class="status === s[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ s[1] }}</button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th class="px-5 py-3">Patient</th><th class="px-3 py-3">Location</th>
                        <th class="px-3 py-3">From → To</th><th class="px-3 py-3">Indication</th>
                        <th class="px-3 py-3">Consultant</th><th class="px-3 py-3">Date</th><th class="px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="c in consultations.data" :key="c.id" class="transition hover:bg-brand-50/40">
                        <td class="px-5 py-3">
                            <div class="font-semibold text-ink-800">{{ c.name }}</div>
                            <div class="nums text-xs text-ink-400">MRN {{ c.mrn }} · {{ c.age ?? '—' }}y · Bed {{ c.bed || '—' }}</div>
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ c.location || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ c.from || '—' }} <span class="text-ink-300">→</span> {{ c.to || '—' }}</td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span v-for="r in c.reasons" :key="r" class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700">{{ r }}</span>
                                <span v-if="c.other" class="rounded-full bg-ink-50 px-2 py-0.5 text-[11px] text-ink-500">{{ c.other }}</span>
                                <span v-if="!c.reasons.length && !c.other" class="text-ink-300">—</span>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ c.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ c.date || '—' }}</td>
                        <td class="px-5 py-3">
                            <span v-if="c.signoff" class="rounded-full bg-success-100 px-2.5 py-0.5 text-xs font-semibold text-success-600">Signed {{ c.signoff }}</span>
                            <span v-else class="rounded-full bg-accent-300/40 px-2.5 py-0.5 text-xs font-semibold text-accent-600">Active</span>
                        </td>
                    </tr>
                    <tr v-if="!consultations.data.length"><td colspan="7" class="px-5 py-10 text-center text-ink-400">No consultations match your filters.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="consultations.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ consultations.from }}–{{ consultations.to }} of {{ consultations.total }}</span>
            <div class="flex gap-1">
                <component :is="l.url ? Link : 'span'" v-for="l in consultations.links" :key="l.label" :href="l.url || undefined" preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition"
                    :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-white text-ink-600 ring-1 ring-ink-100 hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" />
            </div>
        </div>
    </AppLayout>
</template>
