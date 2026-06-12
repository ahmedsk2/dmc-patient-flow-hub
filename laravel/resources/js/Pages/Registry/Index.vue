<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import IcdTypeahead from '@/Components/IcdTypeahead.vue';

const props = defineProps({ mode: String, results: Object, filters: Object, options: Object });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canModify = computed(() => me.value.is_admin || me.value.can.modify);

const f = reactive({
    search: '', from: '', to: '', outcome: '', location: '', gender: '', nationality: '',
    age_from: '', age_to: '', admitted_from: '', discharged_to: '', delay: '', consultant_id: '', longterm: false, discharged: false, tb: false,
    readmit72: false, dx: [], dx_match: 'or', keyword: '', indication: [], ind_match: 'or', consultation_from: '', to_service: '', signed_only: false,
    ...props.filters,
});
// normalise booleans/arrays coming back as strings
f.longterm = !!f.longterm; f.discharged = !!f.discharged; f.tb = !!f.tb; f.readmit72 = !!f.readmit72; f.signed_only = !!f.signed_only;
f.dx = Array.isArray(f.dx) ? f.dx : (f.dx ? [f.dx] : []);
f.indication = Array.isArray(f.indication) ? f.indication.map(Number) : (f.indication ? [Number(f.indication)] : []);

const setMode = (m) => router.get('/registry', { mode: m }, { preserveState: false });
const apply = () => router.get('/registry', { mode: props.mode, ...f }, { preserveState: true, preserveScroll: true });
const reset = () => router.get('/registry', { mode: props.mode }, { preserveState: false });

const qs = computed(() => new URLSearchParams(
    Object.entries({ mode: props.mode, ...f }).flatMap(([k, v]) =>
        Array.isArray(v) ? v.map((x) => [`${k}[]`, x]) : (v === '' || v === false ? [] : [[k, v]]))
).toString());

// diagnosis ICD picker (admissions mode)
const selectedDx = ref([]);
const addDx = (d) => { if (!selectedDx.value.find((x) => x.code === d.code)) { selectedDx.value.push(d); f.dx.push(d.code); } };
const removeDx = (code) => { selectedDx.value = selectedDx.value.filter((x) => x.code !== code); f.dx = f.dx.filter((c) => c !== code); };
const toggleInd = (id) => { f.indication.includes(id) ? (f.indication = f.indication.filter((x) => x !== id)) : f.indication.push(id); };

// edit-from-registry (reuse Modify) — the payload must carry admit_date + current_location
// (ModifyAdmissionRequest requires them; without them every save silently 422'd)
const today = new Date().toISOString().slice(0, 10);
const admitFromOptions = ['ER', 'Clinic', 'OPD', 'OR', 'ICU', 'Referral', 'Transfer', 'Direct', 'Other service'];
const editing = ref(null);
const mForm = useForm({ mrn: '', name: '', age: '', gender: '', nationality: '', bed: '', admit_date: '', admitted_from: '', current_location: 'Ward', consultant_id: '', diagnoses: [] });
const mDx = ref([]);
const openEdit = async (id) => {
    const d = await (await fetch(`/admissions/${id}/edit`, { headers: { Accept: 'application/json' } })).json();
    // keep the LOADED identity so a changed MRN/name is confirmed before posting (K1-3)
    editing.value = { id, mrn: d.mrn || '', name: d.name || '' }; mForm.clearErrors();
    mForm.mrn = d.mrn || ''; mForm.name = d.name || ''; mForm.age = d.age ?? ''; mForm.gender = d.gender || '';
    mForm.nationality = d.nationality || ''; mForm.bed = d.bed || '';
    mForm.admit_date = d.admit_date || ''; mForm.admitted_from = d.admitted_from || ''; mForm.current_location = d.current_location || 'Ward';
    mForm.consultant_id = d.consultant_id || '';   // QUIET reassignment, legacy Modify semantics (J2-13)
    mDx.value = d.diagnoses || []; mForm.diagnoses = mDx.value.map((x) => x.code);
};
// on-service consultants for the edit select; the current (possibly historical) assignee stays selectable
const modifyConsultants = computed(() => props.options.consultants.filter((c) => c.on_service || c.id === mForm.consultant_id));
const mAdd = (d) => { if (!mDx.value.find((x) => x.code === d.code)) { mDx.value.push(d); mForm.diagnoses.push(d.code); } };
const mRemove = (code) => { mDx.value = mDx.value.filter((x) => x.code !== code); mForm.diagnoses = mForm.diagnoses.filter((c) => c !== code); };
// identity confirm (K1-3): an MRN/name edit re-points or renames the patient — make it deliberate
const submitEdit = () => {
    if ((String(mForm.mrn) !== String(editing.value.mrn) || String(mForm.name) !== String(editing.value.name))
        && !confirm(`Change patient identity from ${editing.value.name} (MRN ${editing.value.mrn}) to ${mForm.name} (MRN ${mForm.mrn})?`)) return;   // declined — no post
    mForm.post(`/admissions/${editing.value.id}/modify`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
};

// Esc closes the edit modal (the ICD typeahead swallows the first Esc while its dropdown is open)
const onEsc = (e) => { if (e.key === 'Escape') editing.value = null; };
onMounted(() => window.addEventListener('keydown', onEsc));
onUnmounted(() => window.removeEventListener('keydown', onEsc));

const fld = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
const outcomeTone = (o) => o === 'Dead' ? 'bg-danger-100 text-danger-600' : o === 'Alive' ? 'bg-success-100 text-success-600' : 'bg-ink-100 text-ink-500';
// same short/long LOS band colors as the board cards (J2-7)
const losTone = (b) => b === 'short' ? 'bg-success-100 text-success-600' : b === 'long' ? 'bg-danger-100 text-danger-600' : 'bg-warning-100 text-warning-500';
const modes = [['admissions', 'Admissions'], ['consultations', 'Consultations'], ['diagnosis', 'Diagnosis (free text)']];

// expandable row detail (admissions mode) — fresh Set per toggle so Vue picks up the change
const expanded = ref(new Set());
const toggleExpand = (id) => {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
};
</script>

<template>
    <Head title="Registry" />
    <AppLayout title="Registry Search">
        <div class="mb-4 flex gap-1 rounded-xl bg-white p-1 shadow-sm ring-1 ring-ink-100 w-fit">
            <button v-for="m in modes" :key="m[0]" @click="setMode(m[0])" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="mode === m[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ m[1] }}</button>
        </div>

        <!-- filters -->
        <div class="mb-4 rounded-2xl bg-white p-4 shadow-card ring-1 ring-ink-100/60">
            <!-- ADMISSIONS -->
            <div v-if="mode === 'admissions'" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <input v-model="f.search" @keyup.enter="apply" :class="fld" placeholder="Name or MRN" />
                    <select v-model="f.consultant_id" :class="fld"><option value="">Any consultant</option><option v-for="c in options.consultants" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    <select v-model="f.gender" :class="fld"><option value="">Any gender</option><option>Male</option><option>Female</option></select>
                    <input v-model="f.nationality" :class="fld" list="natlist" placeholder="Nationality" /><datalist id="natlist"><option v-for="c in options.countries" :key="c" :value="c" /></datalist>
                    <select v-model="f.location" :class="fld"><option value="">Any location</option><option v-for="l in options.locations" :key="l">{{ l }}</option></select>
                    <select v-model="f.outcome" :class="fld"><option value="">Any outcome</option><option v-for="o in options.outcomes" :key="o">{{ o }}</option></select>
                    <select v-model="f.admitted_from" :class="fld"><option value="">Any source</option><option v-for="a in options.admittedFrom" :key="a">{{ a }}</option></select>
                    <select v-model="f.discharged_to" :class="fld"><option value="">Any discharged-to</option><option v-for="d in options.dischargedTo" :key="d">{{ d }}</option></select>
                    <select v-model="f.delay" :class="fld"><option value="">Any delay reason</option><option v-for="d in options.delays" :key="d">{{ d }}</option></select>
                    <div class="flex gap-2"><input v-model="f.age_from" :class="fld" placeholder="Age ≥" inputmode="numeric" /><input v-model="f.age_to" :class="fld" placeholder="Age ≤" inputmode="numeric" /></div>
                    <div><label class="text-xs text-ink-400">Admitted from</label><input v-model="f.from" type="date" :class="fld" /></div>
                    <div><label class="text-xs text-ink-400">to</label><input v-model="f.to" type="date" :class="fld" /></div>
                </div>
                <IcdTypeahead :input-class="fld" placeholder="Add diagnosis filter (ICD-10)…" @select="addDx" />
                <div v-if="selectedDx.length" class="flex flex-wrap items-center gap-2">
                    <span v-for="d in selectedDx" :key="d.code" class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700"><span class="nums">{{ d.code }}</span> <button type="button" @click="removeDx(d.code)" class="text-brand-500 hover:text-danger-600">✕</button></span>
                    <label class="ml-2 flex items-center gap-1 text-xs text-ink-500"><input type="radio" value="or" v-model="f.dx_match" /> any</label>
                    <label class="flex items-center gap-1 text-xs text-ink-500"><input type="radio" value="and" v-model="f.dx_match" /> all</label>
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.longterm" class="rounded text-brand-600" /> Long-term</label>
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.discharged" class="rounded text-brand-600" /> Discharged only</label>
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.tb" class="rounded text-brand-600" /> TB</label>
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.readmit72" class="rounded text-brand-600" /> {{ options.readmitWindow ?? 3 }}-day readmissions</label>
                    <button @click="apply" class="ml-auto rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                    <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
                    <a :href="`/registry/export-xlsx?${qs}`" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</a>
                    <a :href="`/registry/export?${qs}`" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</a>
                </div>
            </div>
            <!-- CONSULTATIONS -->
            <div v-else-if="mode === 'consultations'" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <input v-model="f.search" @keyup.enter="apply" :class="fld" placeholder="Name or MRN" />
                    <select v-model="f.consultant_id" :class="fld"><option value="">Any consultant</option><option v-for="c in options.consultants" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    <input v-model="f.consultation_from" :class="fld" placeholder="From service" />
                    <input v-model="f.to_service" :class="fld" placeholder="To service" />
                    <div class="flex gap-2"><input v-model="f.age_from" :class="fld" placeholder="Age ≥" inputmode="numeric" /><input v-model="f.age_to" :class="fld" placeholder="Age ≤" inputmode="numeric" /></div>
                    <div><label class="text-xs text-ink-400">From</label><input v-model="f.from" type="date" :class="fld" /></div>
                    <div><label class="text-xs text-ink-400">to</label><input v-model="f.to" type="date" :class="fld" /></div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label v-for="r in options.reasons" :key="r.id" class="cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition" :class="f.indication.includes(r.id) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="checkbox" class="hidden" :checked="f.indication.includes(r.id)" @change="toggleInd(r.id)" /> {{ r.name }}</label>
                    <template v-if="f.indication.length">
                        <label class="ml-2 flex items-center gap-1 text-xs text-ink-500"><input type="radio" value="or" v-model="f.ind_match" /> any</label>
                        <label class="flex items-center gap-1 text-xs text-ink-500"><input type="radio" value="and" v-model="f.ind_match" /> all</label>
                    </template>
                </div>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.signed_only" class="rounded text-brand-600" /> Signed off only</label>
                    <button @click="apply" class="ml-auto rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                    <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
                    <a :href="`/registry/export-xlsx?${qs}`" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</a>
                    <a :href="`/registry/export?${qs}`" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</a>
                </div>
            </div>
            <!-- DIAGNOSIS -->
            <div v-else class="flex flex-wrap items-end gap-3">
                <div class="grow"><label class="text-xs text-ink-400">Diagnosis keyword</label><input v-model="f.keyword" @keyup.enter="apply" :class="[fld, 'w-full']" placeholder="e.g. pneumonia, sepsis…" /></div>
                <div><label class="text-xs text-ink-400">Admitted from</label><input v-model="f.from" type="date" :class="fld" /></div>
                <div><label class="text-xs text-ink-400">to</label><input v-model="f.to" type="date" :class="fld" /></div>
                <button @click="apply" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
                <a :href="`/registry/export-xlsx?${qs}`" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</a>
                <a :href="`/registry/export?${qs}`" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</a>
            </div>
        </div>

        <div class="mb-2 text-sm text-ink-400"><span class="nums font-semibold text-ink-600">{{ results.total }}</span> result(s)</div>

        <!-- results -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table v-if="mode === 'consultations'" class="w-full text-sm">
                <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400"><th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3">Location</th><th scope="col" class="px-3 py-3">From → To</th><th scope="col" class="px-3 py-3">Indication</th><th scope="col" class="px-3 py-3">Consultant</th><th scope="col" class="px-3 py-3">Date</th><th scope="col" class="px-5 py-3">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="c in results.data" :key="c.id" class="hover:bg-brand-50/40">
                        <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ c.name }}</div><div class="nums text-xs text-ink-400">MRN {{ c.mrn }} · {{ c.age ?? '—' }}y</div></td>
                        <td class="px-3 py-3 text-ink-600">{{ c.location || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ c.from || '—' }} <span class="text-ink-300">→</span> {{ c.to || '—' }}</td>
                        <td class="px-3 py-3"><span v-for="r in c.reasons" :key="r" class="mr-1 inline-block rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700">{{ r }}</span></td>
                        <td class="px-3 py-3 text-ink-600">{{ c.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ c.date || '—' }}</td>
                        <td class="px-5 py-3"><span v-if="c.signoff" class="rounded-full bg-success-100 px-2.5 py-0.5 text-xs font-semibold text-success-600">Signed {{ c.signoff }}</span><span v-else class="rounded-full bg-accent-300/40 px-2.5 py-0.5 text-xs font-semibold text-accent-600">Active</span></td>
                    </tr>
                    <tr v-if="!results.data.length"><td colspan="7" class="px-5 py-10 text-center text-ink-400">No consultations match.</td></tr>
                </tbody>
            </table>
            <table v-else class="w-full text-sm">
                <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400"><th v-if="mode === 'admissions'" scope="col" class="w-10 px-2 py-3"><span class="sr-only">Details</span></th><th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3">Age/Sex</th><th scope="col" class="px-3 py-3">Location</th><th scope="col" class="px-3 py-3">Consultant</th><th scope="col" class="px-3 py-3">Admitted</th><th scope="col" class="px-3 py-3">Discharged</th><th scope="col" class="px-3 py-3">LOS</th><th scope="col" class="px-3 py-3">Outcome</th><th scope="col" class="px-5 py-3 text-right">Edit</th></tr></thead>
                <tbody class="divide-y divide-ink-50">
                    <template v-for="r in results.data" :key="r.id">
                        <tr class="hover:bg-brand-50/40">
                            <td v-if="mode === 'admissions'" class="px-2 py-3">
                                <button @click="toggleExpand(r.id)" :aria-expanded="expanded.has(r.id) ? 'true' : 'false'" :aria-label="`Toggle details for ${r.name}`" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 ring-1 ring-ink-100 transition hover:bg-brand-50 hover:text-brand-700">
                                    <svg class="h-4 w-4 transition-transform" :class="expanded.has(r.id) && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </button>
                            </td>
                            <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ r.name }}</div><div class="nums text-xs text-ink-400">MRN {{ r.mrn }}</div></td>
                            <td class="nums px-3 py-3 text-ink-600">{{ r.age ?? '—' }} · {{ (r.gender||'—').slice(0,1) }}</td>
                            <td class="px-3 py-3 text-ink-600">{{ r.location || '—' }}</td>
                            <td class="px-3 py-3 text-ink-600">{{ r.consultant }}</td>
                            <td class="nums px-3 py-3 text-ink-500">{{ r.admit_date || '—' }}</td>
                            <td class="nums px-3 py-3 text-ink-500">{{ r.discharge_date || '—' }}</td>
                            <td class="nums px-3 py-3"><span v-if="r.los !== null" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="losTone(r.los_band)">{{ r.los }}d</span><span v-else class="text-ink-300">—</span></td>
                            <td class="px-3 py-3"><span v-if="r.outcome" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="outcomeTone(r.outcome)">{{ r.outcome }}</span><span v-else class="text-ink-300">—</span></td>
                            <td class="px-5 py-3 text-right"><button v-if="canModify" @click="openEdit(r.id)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">Edit</button></td>
                        </tr>
                        <!-- expandable detail panel (admissions mode) -->
                        <tr v-if="mode === 'admissions' && expanded.has(r.id)" class="bg-surface/60">
                            <td></td>
                            <td colspan="9" class="px-5 py-4">
                                <div v-if="r.is_tb || r.is_readmission || r.is_longterm || r.disch_still_in" class="mb-3 flex flex-wrap gap-1.5">
                                    <span v-if="r.is_tb" class="rounded-full bg-danger-100 px-2.5 py-0.5 text-xs font-semibold text-danger-600">TB</span>
                                    <span v-if="r.is_readmission" class="rounded-full bg-warning-100 px-2.5 py-0.5 text-xs font-semibold text-warning-500">≤{{ options.readmitWindow ?? 3 }}d readmit</span>
                                    <span v-if="r.is_longterm" class="rounded-full bg-accent-300/40 px-2.5 py-0.5 text-xs font-semibold text-accent-600">Long-term</span>
                                    <span v-if="r.disch_still_in" class="rounded-full bg-warning-100 px-2.5 py-0.5 text-xs font-semibold text-warning-500">Disch. still in</span>
                                </div>
                                <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="sm:col-span-2 lg:col-span-4">
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Diagnoses</dt>
                                        <dd class="mt-1">
                                            <ul v-if="r.diagnoses?.length" class="space-y-0.5">
                                                <li v-for="d in r.diagnoses" :key="d.code" class="text-ink-700"><span class="nums mr-2 font-semibold text-brand-700">{{ d.code }}</span>{{ d.name }}</li>
                                            </ul>
                                            <span v-else class="text-ink-300">—</span>
                                        </dd>
                                    </div>
                                    <!-- K1-12: nationality is in the payload — surface it in the detail panel -->
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Nationality</dt><dd class="mt-0.5 text-ink-700">{{ r.nationality || '—' }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Admitted by</dt><dd class="mt-0.5 text-ink-700">{{ r.admitted_by || '—' }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Discharged by</dt><dd class="mt-0.5 text-ink-700">{{ r.discharged_by || '—' }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Admitted from</dt><dd class="mt-0.5 text-ink-700">{{ r.admitted_from || '—' }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Discharged to</dt><dd class="mt-0.5 text-ink-700">{{ r.discharge_to || '—' }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Clinical discharge</dt><dd class="nums mt-0.5 text-ink-700">{{ r.medical_discharge_date || '—' }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Physical discharge</dt><dd class="nums mt-0.5 text-ink-700">{{ r.discharge_date || '—' }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Delay reason</dt><dd class="mt-0.5 text-ink-700">{{ r.delay_reason || '—' }}</dd></div>
                                    <div v-if="r.transfer_label"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Transfer</dt><dd class="mt-0.5"><span class="rounded-full bg-info-500/10 px-2.5 py-0.5 text-xs font-semibold text-info-500">{{ r.transfer_label }}</span></dd></div>
                                </dl>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!results.data.length"><td :colspan="mode === 'admissions' ? 10 : 9" class="px-5 py-10 text-center text-ink-400">No admissions match.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="results.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ results.from }}–{{ results.to }} of {{ results.total }}</span>
            <div class="flex gap-1"><component :is="l.url ? Link : 'span'" v-for="l in results.links" :key="l.label" :href="l.url || undefined" preserve-scroll class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition" :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-white text-ink-600 ring-1 ring-ink-100 hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" /></div>
        </div>

        <!-- edit modal -->
        <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="editing = null">
            <div class="max-h-[90vh] w-full max-w-lg overflow-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-bold text-ink-900">Modify patient</h3><button @click="editing = null" class="text-ink-400 hover:text-ink-700">✕</button></div>
                <form @submit.prevent="submitEdit" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">MRN</label><input v-model="mForm.mrn" :class="[fld, mForm.errors.mrn && 'border-danger-500']" /><p v-if="mForm.errors.mrn" class="mt-1 text-xs text-danger-600">{{ mForm.errors.mrn }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Bed</label><input v-model="mForm.bed" :class="fld" /><p v-if="mForm.errors.bed" class="mt-1 text-xs text-danger-600">{{ mForm.errors.bed }}</p></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Name</label><input v-model="mForm.name" :class="[fld, mForm.errors.name && 'border-danger-500']" /><p v-if="mForm.errors.name" class="mt-1 text-xs text-danger-600">{{ mForm.errors.name }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Age</label><input v-model="mForm.age" inputmode="numeric" :class="fld" /><p v-if="mForm.errors.age" class="mt-1 text-xs text-danger-600">{{ mForm.errors.age }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Gender</label><select v-model="mForm.gender" :class="fld"><option value="">—</option><option>Male</option><option>Female</option></select><p v-if="mForm.errors.gender" class="mt-1 text-xs text-danger-600">{{ mForm.errors.gender }}</p></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Nationality</label><input v-model="mForm.nationality" :class="fld" /><p v-if="mForm.errors.nationality" class="mt-1 text-xs text-danger-600">{{ mForm.errors.nationality }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Admit date</label><input v-model="mForm.admit_date" type="date" :max="today" :class="[fld, mForm.errors.admit_date && 'border-danger-500']" /><p v-if="mForm.errors.admit_date" class="mt-1 text-xs text-danger-600">{{ mForm.errors.admit_date }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select v-model="mForm.current_location" :class="fld"><option>ER</option><option>Ward</option><option>ICU</option></select><p v-if="mForm.errors.current_location" class="mt-1 text-xs text-danger-600">{{ mForm.errors.current_location }}</p></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Admitted from</label><input v-model="mForm.admitted_from" list="reg-admit-from-options" placeholder="ER, Clinic, Referral…" :class="fld" /><datalist id="reg-admit-from-options"><option v-for="o in admitFromOptions" :key="o" :value="o" /></datalist><p v-if="mForm.errors.admitted_from" class="mt-1 text-xs text-danger-600">{{ mForm.errors.admitted_from }}</p></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Consultant <span class="font-normal text-ink-400">(quiet change — no “New” badge)</span></label>
                            <select v-model="mForm.consultant_id" title="On-service consultants only" :class="fld">
                                <option value="">— no change —</option>
                                <option v-for="c in modifyConsultants" :key="c.id" :value="c.id">{{ c.name }}{{ !c.on_service ? ' (off service)' : '' }}</option>
                            </select>
                            <p v-if="mForm.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ mForm.errors.consultant_id }}</p></div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">Diagnoses</label>
                        <IcdTypeahead :input-class="fld" placeholder="Search ICD-10…" @select="mAdd" />
                        <div v-if="mDx.length" class="mt-2 flex flex-wrap gap-1.5"><span v-for="d in mDx" :key="d.code" class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700"><span class="nums">{{ d.code }}</span> {{ d.name }} <button type="button" @click="mRemove(d.code)" class="text-brand-500 hover:text-danger-600">✕</button></span></div>
                    </div>
                    <div class="flex justify-end gap-2 pt-1"><button type="button" @click="editing = null" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save changes</button></div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
