<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ queue: Array, consultants: Array });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canAssign = computed(() => me.value.is_admin || me.value.can.assign);
const isConsultant = computed(() => me.value.is_admin || me.value.role === 3);

// group the queue by admission date
const byDate = computed(() => {
    const groups = {};
    for (const p of props.queue) {
        (groups[p.admit_date || 'Undated'] ||= []).push(p);
    }
    return Object.entries(groups);
});
const dayName = (d) => d && d !== 'Undated' ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long' }) : '';

const shuffle = () => { if (confirm('Auto-assign all unassigned patients across on-service consultants?')) router.post('/admissions/shuffle', {}, { preserveScroll: true }); };

// assign-to-primary modal
const assigning = ref(null);
const aForm = useForm({ consultant_id: '' });
const openAssign = (p) => { assigning.value = p; aForm.consultant_id = ''; };
const submitAssign = () => aForm.post(`/admissions/${assigning.value.id}/assign`, { preserveScroll: true, onSuccess: () => (assigning.value = null) });
const assignToMe = (p) => router.post(`/admissions/${p.id}/assign-to-me`, {}, { preserveScroll: true });

const locTone = (l) => l === 'ICU' ? 'bg-danger-100 text-danger-600' : l === 'ER' ? 'bg-warning-100 text-warning-500' : 'bg-brand-100 text-brand-700';
</script>

<template>
    <Head title="New Admissions" />
    <AppLayout title="New Admissions">
        <!-- toolbar -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">
                Awaiting assignment <span class="nums ml-1 text-accent-600">{{ queue.length }}</span>
            </span>
            <div class="ml-auto flex gap-2">
                <button v-if="canAssign && queue.length" @click="shuffle" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-ink-600 shadow ring-1 ring-ink-200 transition hover:bg-ink-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                    Shuffle / auto-assign
                </button>
                <Link href="/admissions/create" class="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Admit patient
                </Link>
            </div>
        </div>

        <!-- empty -->
        <div v-if="!queue.length" class="rounded-2xl bg-white p-12 text-center shadow-card ring-1 ring-ink-100/60">
            <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-success-100 text-success-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <p class="font-semibold text-ink-700">No unassigned admissions.</p>
            <p class="mt-1 text-sm text-ink-400">New patients you admit appear here until they're assigned to a consultant (or shuffled).</p>
        </div>

        <!-- queue grouped by admit date -->
        <div v-for="[date, patients] in byDate" :key="date" class="mb-5">
            <h3 class="mb-2 text-sm font-semibold text-ink-500">{{ dayName(date) }} <span class="text-ink-400">· {{ date }}</span> <span class="text-ink-300">({{ patients.length }})</span></h3>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div v-for="p in patients" :key="p.id" class="rounded-2xl bg-white p-4 shadow-card ring-1 ring-ink-100/60">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-ink-800">{{ p.name }}</div>
                            <div class="nums text-xs text-ink-400">MRN {{ p.mrn }}</div>
                        </div>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="locTone(p.location)">{{ p.location || '—' }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-500">
                        <span class="nums">{{ p.age ?? '—' }}y · {{ (p.gender || '—').slice(0,1) }}</span>
                        <span>Bed {{ p.bed || '—' }}</span>
                        <span>from {{ p.admitted_from || '—' }}</span>
                        <span v-if="p.dx_count" class="rounded-full bg-ink-50 px-2 py-0.5 font-semibold">{{ p.dx_count }} dx</span>
                    </div>
                    <div class="mt-3 flex gap-2 border-t border-ink-50 pt-3">
                        <button v-if="canAssign" @click="openAssign(p)" class="flex-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Assign to primary</button>
                        <button v-if="isConsultant" @click="assignToMe(p)" class="flex-1 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 hover:bg-brand-50">Assign to me</button>
                        <span v-if="!canAssign && !isConsultant" class="text-xs text-ink-300">awaiting assignment</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- assign modal -->
        <div v-if="assigning" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="assigning = null">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink-900">Assign to primary</h3>
                <p class="mb-4 text-sm text-ink-400">{{ assigning.name }} · MRN {{ assigning.mrn }}</p>
                <form @submit.prevent="submitAssign" class="space-y-4">
                    <select v-model="aForm.consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500">
                        <option value="">Select consultant…</option>
                        <option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="assigning = null" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="submit" :disabled="aForm.processing || !aForm.consultant_id" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
