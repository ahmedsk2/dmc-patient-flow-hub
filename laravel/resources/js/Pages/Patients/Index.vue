<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ groups: Array, filters: Object, stats: Object, consultants: Array });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canAssign = computed(() => me.value.is_admin || me.value.can.assign);
const canReassign = computed(() => me.value.is_admin || me.value.can.assign || me.value.can.manage);
const isObserver = computed(() => me.value.role === 5);
const canManage = (row) => me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id;

const search = ref(props.filters.search || '');
const location = ref(props.filters.location || '');
const view = ref(props.filters.view || '');
let timer = null;
const apply = () => router.get('/patients', { search: search.value || undefined, location: location.value || undefined, view: view.value || undefined },
    { preserveState: true, replace: true, preserveScroll: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
const setLocation = (l) => { location.value = location.value === l ? '' : l; apply(); };
const setView = (v) => { view.value = view.value === v ? '' : v; apply(); };

// collapsible consultant sections — expanded when a filter is active, else collapsed
const filtering = computed(() => !!(props.filters.search || props.filters.view || props.filters.location));
const open = ref(new Set(filtering.value ? props.groups.map((g) => g.id) : []));
const toggle = (id) => { open.value.has(id) ? open.value.delete(id) : open.value.add(id); open.value = new Set(open.value); };
const allOpen = () => (open.value = new Set(props.groups.map((g) => g.id)));
const allClosed = () => (open.value = new Set());

// summary table buckets
const bucket = (g) => g.on_service && g.specialty_id === 1 ? 'hosp' : g.on_service ? 'subs' : 'off';
const sections = computed(() => [
    { key: 'hosp', label: 'On-service · Hospitalists', rows: props.groups.filter((g) => bucket(g) === 'hosp') },
    { key: 'subs', label: 'On-service · Subspecialists', rows: props.groups.filter((g) => bucket(g) === 'subs') },
    { key: 'off', label: 'Off-service', rows: props.groups.filter((g) => bucket(g) === 'off') },
]);

// shuffle + reassign + action modal
const shuffle = () => { if (confirm('Auto-assign all unassigned patients across on-service consultants?')) router.post('/admissions/shuffle', {}, { preserveScroll: true }); };
const reassign = ref(false);
const rForm = useForm({ from_consultant_id: '', to_consultant_id: '' });
const submitReassign = () => rForm.post('/admissions/reassign', { preserveScroll: true, onSuccess: () => { reassign.value = false; rForm.reset(); } });

const modal = ref(null);
const today = new Date().toISOString().slice(0, 10);
const aForm = useForm({ consultant_id: '' });
const mdForm = useForm({ outcome: 'Alive', medical_discharge_date: today, discharge_to: '', delay_reason: '' });
const cdForm = useForm({ discharge_date: today });
const icuForm = useForm({ outcome: 'Alive', discharge_date: today });
const tForm = useForm({ target: 'ICU' });
const openModal = (mode, row) => {
    modal.value = { mode, row };
    if (mode === 'assign') aForm.consultant_id = row.consultant_id || '';
    if (mode === 'transfer') tForm.target = row.location === 'ICU' ? 'Ward' : 'ICU';
};
const closeModal = () => (modal.value = null);
const opts = { preserveScroll: true, onSuccess: closeModal };
const submitAssign = () => aForm.post(`/admissions/${modal.value.row.id}/assign`, opts);
const submitMedical = () => mdForm.post(`/admissions/${modal.value.row.id}/medical-discharge`, opts);
const submitComplete = () => cdForm.post(`/admissions/${modal.value.row.id}/complete-discharge`, opts);
const submitIcu = () => icuForm.post(`/admissions/${modal.value.row.id}/icu-discharge`, opts);
const submitTransfer = () => tForm.post(`/admissions/${modal.value.row.id}/transfer`, opts);
const longterm = (row) => router.post(`/admissions/${row.id}/longterm`, {}, { preserveScroll: true });
const reverse = (row) => { if (confirm('Reverse this discharge and return the patient to active?')) router.post(`/admissions/${row.id}/reverse-discharge`, {}, { preserveScroll: true }); };

// modify (full edit) — fetches detail, then edits demographics + diagnoses
const canModify = computed(() => me.value.is_admin || me.value.can.modify);
const editing = ref(null);
const mForm = useForm({ mrn: '', name: '', age: '', gender: '', nationality: '', bed: '', diagnoses: [] });
const selectedDx = ref([]);
const dxQuery = ref('');
const dxResults = ref([]);
let dxTimer = null;
const openModify = async (p) => {
    const d = await (await fetch(`/admissions/${p.id}/edit`, { headers: { Accept: 'application/json' } })).json();
    editing.value = { id: p.id };
    mForm.mrn = d.mrn || ''; mForm.name = d.name || ''; mForm.age = d.age ?? ''; mForm.gender = d.gender || '';
    mForm.nationality = d.nationality || ''; mForm.bed = d.bed || '';
    selectedDx.value = d.diagnoses || [];
    mForm.diagnoses = selectedDx.value.map((x) => x.code);
};
watch(dxQuery, (q) => {
    clearTimeout(dxTimer);
    if (q.trim().length < 2) { dxResults.value = []; return; }
    dxTimer = setTimeout(async () => { dxResults.value = await (await fetch(`/api/icd10?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' } })).json(); }, 250);
});
const addDx = (d) => { if (!selectedDx.value.find((x) => x.code === d.code)) { selectedDx.value.push(d); mForm.diagnoses.push(d.code); } dxQuery.value = ''; dxResults.value = []; };
const removeDx = (code) => { selectedDx.value = selectedDx.value.filter((x) => x.code !== code); mForm.diagnoses = mForm.diagnoses.filter((c) => c !== code); };
const submitModify = () => mForm.post(`/admissions/${editing.value.id}/modify`, { preserveScroll: true, onSuccess: () => (editing.value = null) });

const locTone = (l) => l === 'ICU' ? 'bg-danger-100 text-danger-600' : l === 'ER' ? 'bg-warning-100 text-warning-500' : 'bg-brand-100 text-brand-700';
const losTone = (b) => b === 'short' ? 'bg-success-100 text-success-600' : b === 'long' ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-500';
</script>

<template>
    <Head title="Active Patients" />
    <AppLayout title="Active Patients">
        <!-- toolbar -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Census <span class="nums ml-1 text-brand-700">{{ stats.total }}</span></span>
            <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">ICU <span class="nums ml-1 text-danger-600">{{ stats.icu }}</span></span>
            <Link v-if="stats.unassigned" href="/admissions" class="inline-flex items-center gap-1.5 rounded-xl bg-accent-300/30 px-3 py-2 text-sm font-semibold text-accent-600 ring-1 ring-accent-300/50 transition hover:bg-accent-300/50">
                {{ stats.unassigned }} awaiting assignment →
            </Link>

            <div class="relative ml-auto">
                <svg class="pointer-events-none absolute left-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" placeholder="Search name or MRN…" class="w-56 rounded-xl border border-ink-200 bg-white py-2 pl-10 pr-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <div class="flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100">
                <button v-for="l in ['Ward','ICU','ER']" :key="l" @click="setLocation(l)" class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="location === l ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ l }}</button>
                <button v-for="v in [['longterm','Long-term'],['tb','TB']]" :key="v[0]" @click="setView(v[0])" class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="view === v[0] ? 'bg-accent-500 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ v[1] }}</button>
            </div>
            <button v-if="canAssign" @click="shuffle" title="Auto-assign unassigned" class="grid h-9 w-9 place-items-center rounded-xl bg-white text-ink-500 shadow-sm ring-1 ring-ink-100 transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
            </button>
            <button v-if="canReassign" @click="reassign = true" title="Bulk reassign" class="grid h-9 w-9 place-items-center rounded-xl bg-white text-ink-500 shadow-sm ring-1 ring-ink-100 transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
            </button>
        </div>

        <!-- summary: patients per consultant -->
        <div class="mb-5 overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th class="px-5 py-2.5">Consultant</th><th class="px-3 py-2.5 text-center">New</th><th class="px-3 py-2.5 text-center">Active</th>
                        <th class="px-3 py-2.5 text-center">Ward</th><th class="px-3 py-2.5 text-center">ICU</th><th class="px-3 py-2.5 text-center">TB</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-50">
                    <template v-for="sec in sections" :key="sec.key">
                        <tr v-if="sec.rows.length" class="bg-surface/70"><td colspan="6" class="px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-ink-500">{{ sec.label }}</td></tr>
                        <tr v-for="g in sec.rows" :key="g.id" class="cursor-pointer transition hover:bg-brand-50/40" @click="toggle(g.id)">
                            <td class="px-5 py-2 font-semibold text-ink-700">
                                <span class="mr-1.5 text-ink-300">{{ open.has(g.id) ? '−' : '+' }}</span> Dr. {{ g.name }}
                            </td>
                            <td class="nums px-3 py-2 text-center text-info-500">{{ g.counts.new || '' }}</td>
                            <td class="nums px-3 py-2 text-center font-semibold text-brand-700">{{ g.counts.active }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-600">{{ g.counts.ward }}</td>
                            <td class="nums px-3 py-2 text-center text-danger-600">{{ g.counts.icu || '' }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-500">{{ g.counts.tb || '' }}</td>
                        </tr>
                    </template>
                    <tr v-if="!groups.length"><td colspan="6" class="px-5 py-8 text-center text-ink-400">No assigned patients match your filters.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="groups.length" class="mb-3 flex gap-3 text-xs font-semibold text-brand-600">
            <button @click="allOpen" class="hover:underline">Expand all</button>
            <button @click="allClosed" class="hover:underline">Collapse all</button>
        </div>

        <!-- per-consultant patient cards -->
        <div v-for="g in groups" :key="g.id" v-show="open.has(g.id)" class="mb-4 overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <div class="flex items-center justify-between border-b border-ink-100 px-5 py-3">
                <h3 class="font-bold text-ink-800">Dr. {{ g.name }} <span class="ml-1 text-sm font-normal text-ink-400">· {{ g.counts.total }} patient(s)</span></h3>
                <button @click="toggle(g.id)" class="text-ink-400 hover:text-ink-700">✕</button>
            </div>
            <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div v-for="p in g.patients" :key="p.id" class="rounded-xl ring-1 ring-ink-100">
                    <div class="flex items-center justify-between rounded-t-xl bg-surface/60 px-3 py-2">
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="locTone(p.location)">{{ p.location || '—' }} · {{ p.bed || '—' }}</span>
                        <span v-if="p.los !== null" class="nums rounded-full px-2 py-0.5 text-[11px] font-bold" :class="losTone(p.los_band)">{{ p.los }}d</span>
                    </div>
                    <div class="px-3 py-2">
                        <div class="font-semibold text-ink-800">{{ p.name }}</div>
                        <div class="nums text-xs text-ink-400">MRN {{ p.mrn }} · {{ p.age ?? '—' }}y · {{ (p.gender||'—').slice(0,1) }}</div>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <span v-if="p.is_new" class="rounded-full bg-info-100 px-1.5 py-0.5 text-[10px] font-semibold text-info-500">New</span>
                            <span v-if="p.is_readmission" class="rounded-full bg-warning-100 px-1.5 py-0.5 text-[10px] font-semibold text-warning-500">Readmit &lt;72h</span>
                            <span v-if="p.is_longterm" class="rounded-full bg-accent-300/40 px-1.5 py-0.5 text-[10px] font-semibold text-accent-600">Long-term</span>
                            <span v-if="p.is_tb" class="rounded-full bg-success-100 px-1.5 py-0.5 text-[10px] font-semibold text-success-600">TB</span>
                            <span v-if="p.medically_discharged" class="rounded-full bg-warning-100 px-1.5 py-0.5 text-[10px] font-semibold text-warning-500">Disch. still in</span>
                            <span v-if="p.dx_count" class="rounded-full bg-ink-50 px-1.5 py-0.5 text-[10px] font-semibold text-ink-500">{{ p.dx_count }} dx</span>
                        </div>
                    </div>
                    <div v-if="!isObserver" class="flex gap-1 border-t border-ink-50 px-2 py-1.5">
                        <button v-if="canAssign" @click="openModal('assign', p)" title="Reassign consultant" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-info-100 hover:text-info-500"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v6m3-3h-6m-3.75-1.875a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg></button>
                        <button v-if="canModify" @click="openModify(p)" title="Modify details" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg></button>
                        <button @click="longterm(p)" :title="p.is_longterm ? 'Remove long-term' : 'Mark long-term'" class="grid h-7 w-7 place-items-center rounded-lg hover:bg-accent-300/40" :class="p.is_longterm ? 'text-accent-600' : 'text-ink-400 hover:text-accent-600'"><svg class="h-4 w-4" :fill="p.is_longterm ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.5a.562.562 0 0 1 1.04 0l2.125 5.11a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.884a.563.563 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" /></svg></button>
                        <button v-if="canManage(p)" @click="openModal('transfer', p)" title="Transfer ward/ICU" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg></button>
                        <template v-if="canManage(p)">
                            <button v-if="p.location === 'ICU'" @click="openModal('icu', p)" title="ICU discharge" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></button>
                            <template v-else-if="p.medically_discharged">
                                <button @click="openModal('complete', p)" title="Complete discharge" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-success-600 hover:bg-success-100"><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" /></svg></button>
                                <button v-if="me.is_admin" @click="reverse(p)" title="Reverse discharge" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg></button>
                            </template>
                            <button v-else @click="openModal('medical', p)" title="Discharge" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- action modal (assign / discharge / transfer) -->
        <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="closeModal">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-start justify-between">
                    <div><h3 class="text-lg font-bold text-ink-900">{{ ({ assign: 'Reassign consultant', medical: 'Discharge', complete: 'Complete discharge', icu: 'ICU discharge', transfer: 'Transfer' })[modal.mode] }}</h3><p class="text-sm text-ink-400">{{ modal.row.name }} · MRN {{ modal.row.mrn }}</p></div>
                    <button @click="closeModal" class="text-ink-400 hover:text-ink-700">✕</button>
                </div>
                <form v-if="modal.mode === 'assign'" @submit.prevent="submitAssign" class="space-y-4">
                    <select v-model="aForm.consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select consultant…</option><option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="aForm.processing || !aForm.consultant_id" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Assign</button></div>
                </form>
                <form v-else-if="modal.mode === 'medical'" @submit.prevent="submitMedical" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Outcome</label><select v-model="mdForm.outcome" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option>Alive</option><option>Dead</option><option>LAMA</option><option>DAMA</option><option>Transferred</option></select></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Medical discharge date</label><input v-model="mdForm.medical_discharge_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="mdForm.errors.medical_discharge_date" class="mt-1 text-xs text-danger-600">{{ mdForm.errors.medical_discharge_date }}</p></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge to</label><input v-model="mdForm.discharge_to" placeholder="Home, facility…" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Delay reason</label><input v-model="mdForm.delay_reason" placeholder="if bed not freed" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /></div>
                    </div>
                    <p class="text-xs text-ink-400">Marks the patient clinically discharged but still in a bed. Use <strong>Complete discharge</strong> when they physically leave.</p>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mdForm.processing" class="rounded-xl bg-warning-500 px-5 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">Medical discharge</button></div>
                </form>
                <form v-else-if="modal.mode === 'complete'" @submit.prevent="submitComplete" class="space-y-4">
                    <p class="text-sm text-ink-600">Close the file and free the bed.</p>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge date</label><input v-model="cdForm.discharge_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="cdForm.errors.discharge_date" class="mt-1 text-xs text-danger-600">{{ cdForm.errors.discharge_date }}</p></div>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="cdForm.processing" class="rounded-xl bg-success-600 px-5 py-2 text-sm font-semibold text-white hover:bg-success-700 disabled:opacity-50">Complete discharge</button></div>
                </form>
                <form v-else-if="modal.mode === 'icu'" @submit.prevent="submitIcu" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Outcome</label><select v-model="icuForm.outcome" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option>Alive</option><option>Dead</option><option>LAMA</option><option>DAMA</option><option>Transferred</option></select></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge date</label><input v-model="icuForm.discharge_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="icuForm.errors.discharge_date" class="mt-1 text-xs text-danger-600">{{ icuForm.errors.discharge_date }}</p></div>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="icuForm.processing" class="rounded-xl bg-success-600 px-5 py-2 text-sm font-semibold text-white hover:bg-success-700 disabled:opacity-50">ICU discharge</button></div>
                </form>
                <form v-else @submit.prevent="submitTransfer" class="space-y-4">
                    <p class="text-sm text-ink-600">Currently in <span class="font-semibold">{{ modal.row.location || '—' }}</span>. Transfer to:</p>
                    <div class="flex gap-2"><label v-for="loc in ['Ward','ICU']" :key="loc" class="flex-1 cursor-pointer rounded-xl border-2 px-4 py-3 text-center text-sm font-semibold transition" :class="tForm.target === loc ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="radio" v-model="tForm.target" :value="loc" class="hidden" /> {{ loc }}</label></div>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="tForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Transfer</button></div>
                </form>
            </div>
        </div>

        <!-- bulk reassign modal -->
        <div v-if="reassign" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="reassign = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink-900">Reassign a consultant's patients</h3>
                <p class="mb-4 text-sm text-ink-400">Moves every active patient from one consultant to another.</p>
                <form @submit.prevent="submitReassign" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">From</label><select v-model="rForm.from_consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select…</option><option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">To</label><select v-model="rForm.to_consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select…</option><option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                    <div class="flex justify-end gap-2"><button type="button" @click="reassign = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="rForm.processing || !rForm.from_consultant_id || !rForm.to_consultant_id" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Reassign all</button></div>
                </form>
            </div>
        </div>

        <!-- modify modal -->
        <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="editing = null">
            <div class="max-h-[90vh] w-full max-w-lg overflow-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-bold text-ink-900">Modify patient</h3><button @click="editing = null" class="text-ink-400 hover:text-ink-700">✕</button></div>
                <form @submit.prevent="submitModify" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">MRN</label><input v-model="mForm.mrn" inputmode="numeric" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" :class="{ 'border-danger-500': mForm.errors.mrn }" /><p v-if="mForm.errors.mrn" class="mt-1 text-xs text-danger-600">{{ mForm.errors.mrn }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Bed</label><input v-model="mForm.bed" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" /></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Name</label><input v-model="mForm.name" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" :class="{ 'border-danger-500': mForm.errors.name }" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Age</label><input v-model="mForm.age" inputmode="numeric" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Gender</label><select v-model="mForm.gender" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"><option value="">—</option><option>Male</option><option>Female</option></select></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Nationality</label><input v-model="mForm.nationality" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" /></div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">Diagnoses</label>
                        <div class="relative">
                            <input v-model="dxQuery" placeholder="Search ICD-10 (≥2 chars)…" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" />
                            <ul v-if="dxResults.length" class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-ink-100 bg-white py-1 shadow-lg">
                                <li v-for="d in dxResults" :key="d.code" @click="addDx(d)" class="cursor-pointer px-3 py-1.5 text-sm hover:bg-brand-50"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> · {{ d.name }}</li>
                            </ul>
                        </div>
                        <div v-if="selectedDx.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="d in selectedDx" :key="d.code" class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700"><span class="nums">{{ d.code }}</span> {{ d.name }} <button type="button" @click="removeDx(d.code)" class="text-brand-500 hover:text-danger-600">✕</button></span>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-1"><button type="button" @click="editing = null" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save changes</button></div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
