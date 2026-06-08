<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ admissions: Object, filters: Object, stats: Object });

const search = ref(props.filters.search || '');
const location = ref(props.filters.location || '');
let timer = null;

const apply = () => router.get('/patients', { search: search.value || undefined, location: location.value || undefined },
    { preserveState: true, replace: true, preserveScroll: true });

watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
const setLocation = (loc) => { location.value = location.value === loc ? '' : loc; apply(); };

const locTone = (l) => l === 'ICU' ? 'bg-danger-100 text-danger-600' : l === 'ER' ? 'bg-warning-100 text-warning-500' : 'bg-brand-100 text-brand-700';
const losTone = (b) => b === 'short' ? 'bg-success-100 text-success-600' : b === 'long' ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-500';
</script>

<template>
    <Head title="Patients" />
    <AppLayout title="Active Patients">
        <!-- toolbar -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="flex gap-2">
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Census <span class="nums ml-1 text-brand-700">{{ stats.total }}</span></span>
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Ward <span class="nums ml-1 text-brand-700">{{ stats.ward }}</span></span>
                <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">ICU <span class="nums ml-1 text-danger-600">{{ stats.icu }}</span></span>
            </div>
            <div class="relative ml-auto">
                <svg class="pointer-events-none absolute left-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" placeholder="Search name or MRN…" class="w-64 rounded-xl border border-ink-200 bg-white py-2 pl-10 pr-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <div class="flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100">
                <button v-for="l in ['Ward','ICU','ER']" :key="l" @click="setLocation(l)"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                    :class="location === l ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ l }}</button>
            </div>
        </div>

        <!-- table -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th class="px-5 py-3">Patient</th>
                        <th class="px-3 py-3">Age / Sex</th>
                        <th class="px-3 py-3">Bed</th>
                        <th class="px-3 py-3">Location</th>
                        <th class="px-3 py-3">Consultant</th>
                        <th class="px-3 py-3">Admitted</th>
                        <th class="px-3 py-3">LOS</th>
                        <th class="px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="a in admissions.data" :key="a.id" class="transition hover:bg-brand-50/40">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-ink-50 text-xs font-bold text-ink-500">{{ (a.name||'?').slice(0,2).toUpperCase() }}</div>
                                <div>
                                    <div class="font-semibold text-ink-800">{{ a.name }}</div>
                                    <div class="nums text-xs text-ink-400">MRN {{ a.mrn }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="nums px-3 py-3 text-ink-600">{{ a.age ?? '—' }} · {{ (a.gender||'—').slice(0,1) }}</td>
                        <td class="nums px-3 py-3 text-ink-600">{{ a.bed || '—' }}</td>
                        <td class="px-3 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="locTone(a.location)">{{ a.location || '—' }}</span></td>
                        <td class="px-3 py-3 text-ink-600">{{ a.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ a.admit_date || '—' }}</td>
                        <td class="px-3 py-3">
                            <span v-if="a.los !== null" class="nums rounded-full px-2.5 py-0.5 text-xs font-bold" :class="losTone(a.los_band)">{{ a.los }}d</span>
                            <span v-else class="text-ink-400">—</span>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span v-if="a.is_new" class="rounded-full bg-info-100 px-2 py-0.5 text-[11px] font-semibold text-info-500">New</span>
                                <span v-if="a.is_longterm" class="rounded-full bg-accent-300/40 px-2 py-0.5 text-[11px] font-semibold text-accent-600">Long-term</span>
                                <span v-if="a.medically_discharged" class="rounded-full bg-warning-100 px-2 py-0.5 text-[11px] font-semibold text-warning-500">Med. discharged</span>
                                <span v-if="a.dx_count" class="rounded-full bg-ink-50 px-2 py-0.5 text-[11px] font-semibold text-ink-500">{{ a.dx_count }} dx</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!admissions.data.length"><td colspan="8" class="px-5 py-10 text-center text-ink-400">No patients match your filters.</td></tr>
                </tbody>
            </table>
        </div>

        <!-- pagination -->
        <div v-if="admissions.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ admissions.from }}–{{ admissions.to }} of {{ admissions.total }}</span>
            <div class="flex gap-1">
                <component :is="l.url ? Link : 'span'" v-for="l in admissions.links" :key="l.label" :href="l.url || undefined" preserve-scroll
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition"
                    :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-white text-ink-600 ring-1 ring-ink-100 hover:bg-ink-50' : 'text-ink-300')"
                    v-html="l.label" />
            </div>
        </div>
    </AppLayout>
</template>
