<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ mode: String, results: Object, filters: Object, options: Object });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canModify = computed(() => me.value.is_admin || me.value.can.modify);

const f = reactive({
    search: '', from: '', to: '', outcome: '', location: '', gender: '', nationality: '',
    age_from: '', age_to: '', admitted_from: '', consultant_id: '', longterm: false, discharged: false,
    readmit72: false, dx: [], dx_match: 'or', keyword: '', indication: [], consultation_from: '', to_service: '', signed_only: false,
    ...props.filters,
});
// normalise booleans/arrays coming back as strings
f.longterm = !!f.longterm; f.discharged = !!f.discharged; f.readmit72 = !!f.readmit72; f.signed_only = !!f.signed_only;
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
const dxQuery = ref(''); const dxResults = ref([]); const selectedDx = ref([]); let dxTimer = null;
watch(dxQuery, (q) => { clearTimeout(dxTimer); if (q.trim().length < 2) { dxResults.value = []; return; } dxTimer = setTimeout(async () => { dxResults.value = await (await fetch(`/api/icd10?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' } })).json(); }, 250); });
const addDx = (d) => { if (!selectedDx.value.find((x) => x.code === d.code)) { selectedDx.value.push(d); f.dx.push(d.code); } dxQuery.value = ''; dxResults.value = []; };
const removeDx = (code) => { selectedDx.value = selectedDx.value.filter((x) => x.code !== code); f.dx = f.dx.filter((c) => c !== code); };
const toggleInd = (id) => { f.indication.includes(id) ? (f.indication = f.indication.filter((x) => x !== id)) : f.indication.push(id); };

// edit-from-registry (reuse Modify)
const editing = ref(null);
const mForm = useForm({ mrn: '', name: '', age: '', gender: '', nationality: '', bed: '', diagnoses: [] });
const mDx = ref([]); const mQuery = ref(''); const mResults = ref([]); let mTimer = null;
const openEdit = async (id) => {
    const d = await (await fetch(`/admissions/${id}/edit`, { headers: { Accept: 'application/json' } })).json();
    editing.value = { id }; mForm.mrn = d.mrn || ''; mForm.name = d.name || ''; mForm.age = d.age ?? ''; mForm.gender = d.gender || '';
    mForm.nationality = d.nationality || ''; mForm.bed = d.bed || ''; mDx.value = d.diagnoses || []; mForm.diagnoses = mDx.value.map((x) => x.code);
};
watch(mQuery, (q) => { clearTimeout(mTimer); if (q.trim().length < 2) { mResults.value = []; return; } mTimer = setTimeout(async () => { mResults.value = await (await fetch(`/api/icd10?q=${encodeURIComponent(q.trim())}`, { headers: { Accept: 'application/json' } })).json(); }, 250); });
const mAdd = (d) => { if (!mDx.value.find((x) => x.code === d.code)) { mDx.value.push(d); mForm.diagnoses.push(d.code); } mQuery.value = ''; mResults.value = []; };
const mRemove = (code) => { mDx.value = mDx.value.filter((x) => x.code !== code); mForm.diagnoses = mForm.diagnoses.filter((c) => c !== code); };
const submitEdit = () => mForm.post(`/admissions/${editing.value.id}/modify`, { preserveScroll: true, onSuccess: () => (editing.value = null) });

const fld = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
const outcomeTone = (o) => o === 'Dead' ? 'bg-danger-100 text-danger-600' : o === 'Alive' ? 'bg-success-100 text-success-600' : 'bg-ink-100 text-ink-500';
const modes = [['admissions', 'Admissions'], ['consultations', 'Consultations'], ['diagnosis', 'Diagnosis (free text)']];
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
                    <div class="flex gap-2"><input v-model="f.age_from" :class="fld" placeholder="Age ≥" inputmode="numeric" /><input v-model="f.age_to" :class="fld" placeholder="Age ≤" inputmode="numeric" /></div>
                    <div><label class="text-xs text-ink-400">Admitted from</label><input v-model="f.from" type="date" :class="fld" /></div>
                    <div><label class="text-xs text-ink-400">to</label><input v-model="f.to" type="date" :class="fld" /></div>
                </div>
                <div class="relative">
                    <input v-model="dxQuery" :class="fld" placeholder="Add diagnosis filter (ICD-10)…" />
                    <ul v-if="dxResults.length" class="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded-xl border border-ink-100 bg-white py-1 shadow-lg"><li v-for="d in dxResults" :key="d.code" @click="addDx(d)" class="cursor-pointer px-3 py-1.5 text-sm hover:bg-brand-50"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> · {{ d.name }}</li></ul>
                </div>
                <div v-if="selectedDx.length" class="flex flex-wrap items-center gap-2">
                    <span v-for="d in selectedDx" :key="d.code" class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700"><span class="nums">{{ d.code }}</span> <button type="button" @click="removeDx(d.code)" class="text-brand-500 hover:text-danger-600">✕</button></span>
                    <label class="ml-2 flex items-center gap-1 text-xs text-ink-500"><input type="radio" value="or" v-model="f.dx_match" /> any</label>
                    <label class="flex items-center gap-1 text-xs text-ink-500"><input type="radio" value="and" v-model="f.dx_match" /> all</label>
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.longterm" class="rounded text-brand-600" /> Long-term</label>
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.discharged" class="rounded text-brand-600" /> Discharged only</label>
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.readmit72" class="rounded text-brand-600" /> 72h readmissions</label>
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
                <div class="flex flex-wrap gap-2">
                    <label v-for="r in options.reasons" :key="r.id" class="cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition" :class="f.indication.includes(r.id) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="checkbox" class="hidden" :checked="f.indication.includes(r.id)" @change="toggleInd(r.id)" /> {{ r.name }}</label>
                </div>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="f.signed_only" class="rounded text-brand-600" /> Signed off only</label>
                    <button @click="apply" class="ml-auto rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                    <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
                </div>
            </div>
            <!-- DIAGNOSIS -->
            <div v-else class="flex flex-wrap items-end gap-3">
                <div class="grow"><label class="text-xs text-ink-400">Diagnosis keyword</label><input v-model="f.keyword" @keyup.enter="apply" :class="[fld, 'w-full']" placeholder="e.g. pneumonia, sepsis…" /></div>
                <div><label class="text-xs text-ink-400">Admitted from</label><input v-model="f.from" type="date" :class="fld" /></div>
                <div><label class="text-xs text-ink-400">to</label><input v-model="f.to" type="date" :class="fld" /></div>
                <button @click="apply" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
            </div>
        </div>

        <div class="mb-2 text-sm text-ink-400"><span class="nums font-semibold text-ink-600">{{ results.total }}</span> result(s)</div>

        <!-- results -->
        <div class="overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table v-if="mode === 'consultations'" class="w-full text-sm">
                <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400"><th class="px-5 py-3">Patient</th><th class="px-3 py-3">Location</th><th class="px-3 py-3">From → To</th><th class="px-3 py-3">Indication</th><th class="px-3 py-3">Consultant</th><th class="px-3 py-3">Date</th><th class="px-5 py-3">Status</th></tr></thead>
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
                <thead><tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400"><th class="px-5 py-3">Patient</th><th class="px-3 py-3">Age/Sex</th><th class="px-3 py-3">Location</th><th class="px-3 py-3">Consultant</th><th class="px-3 py-3">Admitted</th><th class="px-3 py-3">Discharged</th><th class="px-3 py-3">LOS</th><th class="px-3 py-3">Outcome</th><th class="px-5 py-3 text-right">Edit</th></tr></thead>
                <tbody class="divide-y divide-ink-50">
                    <tr v-for="r in results.data" :key="r.id" class="hover:bg-brand-50/40">
                        <td class="px-5 py-3"><div class="font-semibold text-ink-800">{{ r.name }}</div><div class="nums text-xs text-ink-400">MRN {{ r.mrn }}</div></td>
                        <td class="nums px-3 py-3 text-ink-600">{{ r.age ?? '—' }} · {{ (r.gender||'—').slice(0,1) }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ r.location || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ r.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ r.admit_date || '—' }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ r.discharge_date || '—' }}</td>
                        <td class="nums px-3 py-3 text-ink-600">{{ r.los !== null ? r.los + 'd' : '—' }}</td>
                        <td class="px-3 py-3"><span v-if="r.outcome" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="outcomeTone(r.outcome)">{{ r.outcome }}</span><span v-else class="text-ink-300">—</span></td>
                        <td class="px-5 py-3 text-right"><button v-if="canModify" @click="openEdit(r.id)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">Edit</button></td>
                    </tr>
                    <tr v-if="!results.data.length"><td colspan="9" class="px-5 py-10 text-center text-ink-400">No admissions match.</td></tr>
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
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Bed</label><input v-model="mForm.bed" :class="fld" /></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Name</label><input v-model="mForm.name" :class="fld" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Age</label><input v-model="mForm.age" inputmode="numeric" :class="fld" /></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Gender</label><select v-model="mForm.gender" :class="fld"><option value="">—</option><option>Male</option><option>Female</option></select></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Nationality</label><input v-model="mForm.nationality" :class="fld" /></div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">Diagnoses</label>
                        <div class="relative"><input v-model="mQuery" :class="fld" placeholder="Search ICD-10…" /><ul v-if="mResults.length" class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-ink-100 bg-white py-1 shadow-lg"><li v-for="d in mResults" :key="d.code" @click="mAdd(d)" class="cursor-pointer px-3 py-1.5 text-sm hover:bg-brand-50"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> · {{ d.name }}</li></ul></div>
                        <div v-if="mDx.length" class="mt-2 flex flex-wrap gap-1.5"><span v-for="d in mDx" :key="d.code" class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700"><span class="nums">{{ d.code }}</span> {{ d.name }} <button type="button" @click="mRemove(d.code)" class="text-brand-500 hover:text-danger-600">✕</button></span></div>
                    </div>
                    <div class="flex justify-end gap-2 pt-1"><button type="button" @click="editing = null" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save changes</button></div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
