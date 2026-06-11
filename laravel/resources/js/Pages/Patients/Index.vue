<script setup>
import { ref, watch, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ groups: Array, filters: Object, stats: Object, consultants: Array, specialties: Array, externalServices: Array, readmitWindow: Number });

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
const tForm = useForm({ mode: 'location', target: 'ICU', specialty_id: '', consultant_id: '', service: '' });
const openModal = (mode, row) => {
    modal.value = { mode, row };
    if (mode === 'assign') aForm.consultant_id = row.consultant_id || '';
    if (mode === 'transfer') { tForm.reset(); tForm.target = row.location === 'ICU' ? 'Ward' : 'ICU'; }
};
// internal-specialty handover: consultants offered are those of the chosen specialty
const specConsultants = computed(() => props.consultants.filter((c) => c.specialty_id === tForm.specialty_id));
watch(() => tForm.specialty_id, () => (tForm.consultant_id = ''));
const transferReady = computed(() =>
    tForm.mode === 'location' ? !!tForm.target
    : tForm.mode === 'specialty' ? !!(tForm.specialty_id && tForm.consultant_id)
    : !!tForm.service);
const closeModal = () => (modal.value = null);
const opts = { preserveScroll: true, onSuccess: closeModal };
const submitAssign = () => aForm.post(`/admissions/${modal.value.row.id}/assign`, opts);
const submitMedical = () => mdForm.post(`/admissions/${modal.value.row.id}/medical-discharge`, opts);
const submitComplete = () => cdForm.post(`/admissions/${modal.value.row.id}/complete-discharge`, opts);
const submitIcu = () => icuForm.post(`/admissions/${modal.value.row.id}/icu-discharge`, opts);
const submitTransfer = () => tForm.post(`/admissions/${modal.value.row.id}/transfer`, opts);
const longterm = (row) => router.post(`/admissions/${row.id}/longterm`, {}, { preserveScroll: true });
// the board shows active patients only, so the undo here is the phase-1 (medical) one;
// reversing a COMPLETED discharge lives on the admin Recent registry
const undoMedical = (row) => { if (confirm('Undo the medical discharge and return the patient to active?')) router.post(`/admissions/${row.id}/undo-medical-discharge`, {}, { preserveScroll: true }); };

// modify (full edit) — fetches detail, then edits demographics + admission facts + diagnoses
const canModify = computed(() => me.value.is_admin || me.value.can.modify);
const admitFromOptions = ['ER', 'Clinic', 'Referral', 'Transfer', 'Direct', 'ICU', 'OPD', 'OR'];
const editing = ref(null);
const mForm = useForm({ mrn: '', name: '', age: '', gender: '', nationality: '', bed: '', admit_date: '', admitted_from: '', current_location: 'Ward', diagnoses: [] });
const selectedDx = ref([]);
const dxQuery = ref('');
const dxResults = ref([]);
let dxTimer = null;
const openModify = async (p) => {
    const d = await (await fetch(`/admissions/${p.id}/edit`, { headers: { Accept: 'application/json' } })).json();
    editing.value = { id: p.id };
    mForm.mrn = d.mrn || ''; mForm.name = d.name || ''; mForm.age = d.age ?? ''; mForm.gender = d.gender || '';
    mForm.nationality = d.nationality || ''; mForm.bed = d.bed || '';
    mForm.admit_date = d.admit_date || ''; mForm.admitted_from = d.admitted_from || ''; mForm.current_location = d.current_location || 'Ward';
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

// hard delete (admin only — server re-checks)
const destroyAdmission = (row) => {
    if (confirm(`Delete the admission for ${row.name} (MRN ${row.mrn})? This permanently removes the episode and its diagnoses.`))
        router.delete(`/admissions/${row.id}`, { preserveScroll: true });
};

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
            <button v-if="canAssign" @click="shuffle" title="Auto-assign unassigned" aria-label="Auto-assign unassigned" class="grid h-9 w-9 place-items-center rounded-xl bg-white text-ink-500 shadow-sm ring-1 ring-ink-100 transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
            </button>
            <button v-if="canReassign" @click="reassign = true" title="Bulk reassign" aria-label="Bulk reassign" class="grid h-9 w-9 place-items-center rounded-xl bg-white text-ink-500 shadow-sm ring-1 ring-ink-100 transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
            </button>
        </div>

        <!-- summary: patients per consultant -->
        <div class="mb-5 overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-2.5">Consultant</th><th scope="col" class="px-3 py-2.5 text-center">New</th><th scope="col" class="px-3 py-2.5 text-center">Active</th>
                        <th scope="col" class="px-3 py-2.5 text-center">Ward</th><th scope="col" class="px-3 py-2.5 text-center">ICU</th><th scope="col" class="px-3 py-2.5 text-center">TB</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-50">
                    <template v-for="sec in sections" :key="sec.key">
                        <tr v-if="sec.rows.length" class="bg-surface/70"><td colspan="6" class="px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-ink-500">{{ sec.label }}</td></tr>
                        <tr v-for="g in sec.rows" :key="g.id" class="cursor-pointer transition hover:bg-brand-50/40" @click="toggle(g.id)">
                            <td class="px-5 py-2 font-semibold text-ink-700">
                                <svg class="mr-1.5 inline h-4 w-4 text-ink-300" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="open.has(g.id) ? 'm4.5 15.75 7.5-7.5 7.5 7.5' : 'm19.5 8.25-7.5 7.5-7.5-7.5'" /></svg> Dr. {{ g.name }}
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
                <button @click="toggle(g.id)" title="Collapse" aria-label="Collapse" class="text-ink-400 hover:text-ink-700"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg></button>
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
                            <span v-if="p.is_readmission" class="rounded-full bg-warning-100 px-1.5 py-0.5 text-[10px] font-semibold text-warning-500">Readmit ≤{{ readmitWindow ?? 3 }}d</span>
                            <span v-if="p.is_longterm" class="rounded-full bg-accent-300/40 px-1.5 py-0.5 text-[10px] font-semibold text-accent-600">Long-term</span>
                            <span v-if="p.is_tb" class="rounded-full bg-danger-100 px-1.5 py-0.5 text-[10px] font-semibold text-danger-600">TB</span>
                            <span v-if="p.medically_discharged" class="rounded-full bg-warning-100 px-1.5 py-0.5 text-[10px] font-semibold text-warning-500">Disch. still in</span>
                            <span v-if="p.dx_count" class="rounded-full bg-ink-50 px-1.5 py-0.5 text-[10px] font-semibold text-ink-500">{{ p.dx_count }} dx</span>
                        </div>
                    </div>
                    <div v-if="!isObserver" class="flex gap-1 border-t border-ink-50 px-2 py-1.5">
                        <button v-if="canAssign" @click="openModal('assign', p)" title="Reassign consultant" aria-label="Reassign consultant" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-info-100 hover:text-info-500"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v6m3-3h-6m-3.75-1.875a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg></button>
                        <button v-if="canModify" @click="openModify(p)" title="Modify details" aria-label="Modify details" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg></button>
                        <button @click="longterm(p)" :title="p.is_longterm ? 'Remove long-term' : 'Mark long-term'" :aria-label="p.is_longterm ? 'Remove long-term' : 'Mark long-term'" class="grid h-7 w-7 place-items-center rounded-lg hover:bg-accent-300/40" :class="p.is_longterm ? 'text-accent-600' : 'text-ink-400 hover:text-accent-600'"><svg class="h-4 w-4" :fill="p.is_longterm ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" /></svg></button>
                        <button v-if="canManage(p)" @click="openModal('transfer', p)" title="Transfer" aria-label="Transfer" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg></button>
                        <template v-if="canManage(p)">
                            <button v-if="p.location === 'ICU'" @click="openModal('icu', p)" title="ICU discharge" aria-label="ICU discharge" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></button>
                            <template v-else-if="p.medically_discharged">
                                <button @click="openModal('complete', p)" title="Complete discharge" aria-label="Complete discharge" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-success-600 hover:bg-success-100"><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" /></svg></button>
                                <button @click="undoMedical(p)" title="Undo medical discharge" aria-label="Undo medical discharge" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg></button>
                            </template>
                            <button v-else @click="openModal('medical', p)" title="Discharge" aria-label="Discharge" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75" /></svg></button>
                        </template>
                        <button v-if="me.is_admin" @click="destroyAdmission(p)" title="Delete admission" aria-label="Delete admission" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg></button>
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
                    <div class="flex gap-1 rounded-xl bg-surface p-1 text-sm font-semibold">
                        <button v-for="m in [['location','Ward / ICU'],['specialty','Internal specialty'],['external','External service']]" :key="m[0]" type="button" @click="tForm.mode = m[0]"
                            class="flex-1 rounded-lg px-2 py-1.5 transition" :class="tForm.mode === m[0] ? 'bg-white text-brand-700 shadow-sm ring-1 ring-ink-100' : 'text-ink-500 hover:text-ink-700'">{{ m[1] }}</button>
                    </div>
                    <template v-if="tForm.mode === 'location'">
                        <p class="text-sm text-ink-600">Currently in <span class="font-semibold">{{ modal.row.location || '—' }}</span>. Transfer to:</p>
                        <div class="flex gap-2"><label v-for="loc in ['Ward','ICU']" :key="loc" class="flex-1 cursor-pointer rounded-xl border-2 px-4 py-3 text-center text-sm font-semibold transition" :class="tForm.target === loc ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="radio" v-model="tForm.target" :value="loc" class="hidden" /> {{ loc }}</label></div>
                        <p class="text-xs text-ink-400">Keeps the same consultant; opens a new episode in the receiving location.</p>
                    </template>
                    <template v-else-if="tForm.mode === 'specialty'">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Receiving specialty</label>
                            <select v-model="tForm.specialty_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select specialty…</option><option v-for="s in specialties" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                            <p v-if="tForm.errors.specialty_id" class="mt-1 text-xs text-danger-600">{{ tForm.errors.specialty_id }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Receiving consultant</label>
                            <select v-model="tForm.consultant_id" :disabled="!tForm.specialty_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">Select consultant…</option><option v-for="c in specConsultants" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                            <p v-if="tForm.specialty_id && !specConsultants.length" class="mt-1 text-xs text-warning-500">No consultants registered under this specialty.</p>
                            <p v-if="tForm.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ tForm.errors.consultant_id }}</p></div>
                        <p class="text-xs text-ink-400">Closes this episode as a specialty handover and opens a new one under the chosen consultant.</p>
                    </template>
                    <template v-else>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">External / allied service</label>
                            <select v-model="tForm.service" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select service…</option><option v-for="s in externalServices" :key="s" :value="s">{{ s }}</option></select>
                            <p v-if="tForm.errors.service" class="mt-1 text-xs text-danger-600">{{ tForm.errors.service }}</p></div>
                        <p class="text-xs text-ink-400">Closes this episode — the patient leaves the department (no new episode).</p>
                    </template>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="tForm.processing || !transferReady" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Transfer</button></div>
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
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Admit date</label><input v-model="mForm.admit_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" :class="{ 'border-danger-500': mForm.errors.admit_date }" /><p v-if="mForm.errors.admit_date" class="mt-1 text-xs text-danger-600">{{ mForm.errors.admit_date }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select v-model="mForm.current_location" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"><option>ER</option><option>Ward</option><option>ICU</option></select></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Admitted from</label><input v-model="mForm.admitted_from" list="admit-from-options" placeholder="ER, Clinic, Referral…" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" /><datalist id="admit-from-options"><option v-for="o in admitFromOptions" :key="o" :value="o" /></datalist></div>
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
