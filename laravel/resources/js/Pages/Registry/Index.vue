<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import IcdTypeahead from '@/Components/IcdTypeahead.vue';
import ActivityPanel from '@/Components/ActivityPanel.vue';
import SortableTh from '@/Components/SortableTh.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useServerTable } from '@/composables/useServerTable';
import BaseModal from '@/Components/BaseModal.vue';
import PatientForm from '@/Components/PatientForm.vue';
import { usePatientEdit } from '@/composables/usePatientEdit';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { localToday, vFocus, xsrf } from '@/lib/ui.js';

const { ask } = useConfirm();

const props = defineProps({
    mode: String, results: Object, filters: Object, options: Object,
    // Wave 2, Item 6: the sort ACTUALLY applied server-side ({sort: key|null, dir: 'asc'|'desc'|null})
    // — seeds useServerTable so SortableTh's aria-sort is correct on first load / reload / bookmark.
    sort: { type: Object, default: () => ({ sort: null, dir: null }) },
});

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

// ---- SPC-TM-011 (Wave 1): the free-text term is PHI (name/MRN, or the diagnosis keyword the
// audit trail already redacts). It travels in a POST body ONLY. Non-PII filters stay in the query
// string so filtered views remain shareable. GET-with-term is legacy-accepted server-side but
// redirects term-less.
const term = computed(() => (props.mode === 'diagnosis' ? String(f.keyword || '') : String(f.search || '')).trim());
const hasTerm = computed(() => term.value !== '');
const termBody = () => (props.mode === 'diagnosis' ? { keyword: f.keyword } : { search: f.search });
// the shareable (term-less) filter payload for GET visits — sort/dir ride alongside the other
// non-PII filters (Wave 2, Item 6) so a bookmark/reload/pagination link keeps the active sort.
const publicFilters = () => {
    const { search, keyword, ...rest } = { ...f };
    return { mode: props.mode, ...rest, sort: sortState.state.dir ? sortState.state.key : '', dir: sortState.state.dir || '' };
};

const setMode = (m) => router.get('/registry', { mode: m }, { preserveState: false });
const apply = () => hasTerm.value
    ? router.post(`/registry?${qs.value}`, termBody(), { preserveState: true, preserveScroll: true })
    : router.get('/registry', publicFilters(), { preserveState: true, preserveScroll: true });
const reset = () => router.get('/registry', { mode: props.mode }, { preserveState: false });

// ---- Wave 2, Item 6: server-side sort ------------------------------------------------------
// useServerTable owns the {key, dir} cycle (mirrors SortableTh.vue's own cycle); the actual
// visit is delegated to `apply()` — the SAME POST-vs-GET decision the Search button and every
// other filter change already goes through, so a sort click can NEVER leak the search term into
// the URL (SPC-TM-011). `apply()` reads `qs`/`publicFilters()` below, which read `sortState.state`
// directly — by the time `apply()` runs, toggle() has already updated the state synchronously.
const sortState = useServerTable({ key: props.sort?.sort ?? null, dir: props.sort?.dir ?? null, navigate: () => apply() });
const onSort = ({ key }) => sortState.toggle(key);

// paginator links are plain GET URLs carrying only the non-PII query; with a term active the page
// change must re-send the term in a POST body (the paginator reads `page` from the URL either way)
const goPage = (url) => {
    if (!url) return;
    if (hasTerm.value) router.post(url, termBody(), { preserveState: true, preserveScroll: true });
    else router.visit(url, { preserveScroll: true });
};

// term-LESS by construction: search/keyword never enter a URL (SPC-TM-011). sort/dir included so
// pagination + exports + the match-count probe all respect the active sort.
const qs = computed(() => new URLSearchParams(
    Object.entries({ mode: props.mode, ...f, sort: sortState.state.dir ? sortState.state.key : '', dir: sortState.state.dir || '' }).flatMap(([k, v]) =>
        k === 'search' || k === 'keyword' ? [] :
        Array.isArray(v) ? v.map((x) => [`${k}[]`, x]) : (v === '' || v === false ? [] : [[k, v]]))
).toString());

// §3.6: surface the matched row count on-demand (hover/focus the export buttons) so the user can
// see how large the export is before starting it; advise a slower path above ~20k rows.
// POSTed so a term-filtered count carries its term in the body.
const matchCount = ref(null);
const loadMatchCount = async () => {
    if (matchCount.value !== null) return;
    try {
        const r = await fetch('/registry/count?' + qs.value, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify(termBody()),
        });
        matchCount.value = (await r.json()).count;
    } catch { /* advisory only — never blocks the export */ }
};
// invalidate the cached count whenever the filter set (or the term) changes
watch([qs, term], () => { matchCount.value = null; });

// term-aware exports: with a term active, the download is a self-submitting POST form (the term
// rides the body; the browser still treats the attachment response as a normal download). The
// term-less path keeps the plain link so copy/middle-click behave exactly as before.
const postExport = (path) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `${path}?${qs.value}`;
    const add = (name, value) => {
        const el = document.createElement('input');
        el.type = 'hidden'; el.name = name; el.value = value;
        form.appendChild(el);
    };
    add('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    Object.entries(termBody()).forEach(([k, v]) => { if (v) add(k, v); });
    document.body.appendChild(form);
    form.submit();
    form.remove();
};

// §3.7: distinguish "first page load" from "searched and got nothing" so the empty state only
// shows after the user actually applied a filter (any non-default value)
const hasSearched = computed(() => Object.values(f).some(
    (v) => v !== '' && v !== false && !(Array.isArray(v) && v.length === 0) && v !== 'or'));

// diagnosis ICD picker (admissions mode). Seed the chips from the ACTIVE dx filter (resolved
// server-side to {code,name}) so they reappear — and stay removable — after a paginated/reloaded
// visit; without this the filter stayed active on results + export but the chips vanished from view (N1-2).
const selectedDx = ref((props.options?.dxNames ?? []).map((d) => ({ code: d.code, name: d.name })));
const addDx = (d) => { if (!selectedDx.value.find((x) => x.code === d.code)) { selectedDx.value.push(d); f.dx.push(d.code); } };
const removeDx = (code) => { selectedDx.value = selectedDx.value.filter((x) => x.code !== code); f.dx = f.dx.filter((c) => c !== code); };
const toggleInd = (id) => { f.indication.includes(id) ? (f.indication = f.indication.filter((x) => x !== id)) : f.indication.push(id); };

// edit-from-registry (reuse the canonical Modify form) — the payload carries admit_date +
// current_location (ModifyAdmissionRequest requires them). The Registry's old free-text nationality
// drift is resolved here: PatientForm renders the canonical select-with-legacy-option (Item 3).
const today = localToday();
const { form: mForm, editing, selectedDx: mDx, activity: mActivity, open: openEditForm, addDx: mAdd, removeDx: mRemove, submit: submitEdit, isDirty: mIsDirty } =
    usePatientEdit({ ask, onSuccess: () => (editing.value = null) });
const openEdit = (id) => openEditForm({ id });
// Wave 3, Item 1: unsaved-edit guard shared by @close (Esc/backdrop/X) and the footer Cancel.
const { guardedClose: guardEdit } = useUnsavedGuard(mIsDirty, ask);
const closeEdit = () => guardEdit(() => { editing.value = null; });

const fld = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
const outcomeTone = (o) => o === 'Dead' ? 'bg-tint-danger text-on-danger' : o === 'Alive' ? 'bg-tint-success text-on-success' : 'bg-ink-100 text-ink-500';
// same short/long LOS band colors as the board cards (J2-7)
const losTone = (b) => b === 'short' ? 'bg-tint-success text-on-success' : b === 'long' ? 'bg-tint-danger text-on-danger' : 'bg-tint-warning text-on-warning';
const modes = [['admissions', 'Admissions'], ['consultations', 'Consultations'], ['diagnosis', 'Diagnosis (free text)']];

// Wave 2, Item 5: status-rail rows — the app's "Census Board" fingerprint (app.css's
// status-rail/rail-* utilities), reusing the SAME outcome vocabulary as outcomeTone above so the
// rail colour and the Outcome badge never disagree. An open/undischarged row gets rail-neutral.
const outcomeRail = (o) => o === 'Dead' ? 'rail-danger' : o === 'Alive' ? 'rail-success' : 'rail-neutral';
const consultRail = (signoff) => signoff ? 'rail-success' : 'rail-info';

// Density toggle — Comfortable/Compact row padding (app.css's density-comfortable/-compact +
// row-pad, the SAME mechanism the board's own density toggle is slated to migrate onto). Persisted
// per-browser under its OWN key so Registry's preference doesn't collide with the board's.
const density = ref('comfortable');
const setDensity = (d) => { density.value = d; localStorage.setItem('dmc-registry-density', d); };
onMounted(() => {
    const d = localStorage.getItem('dmc-registry-density');
    if (d === 'compact' || d === 'comfortable') density.value = d;
});

// expandable row detail (admissions mode) — fresh Set per toggle so Vue picks up the change
const expanded = ref(new Set());
const toggleExpand = (id) => {
    const s = new Set(expanded.value);
    s.has(id) ? s.delete(id) : s.add(id);
    expanded.value = s;
};
</script>

<template>
    <AppLayout title="Registry Search" :breadcrumbs="[
        { label: 'Administration' },
        { label: 'Analytics & Reports' },
        { label: 'Registry' },
    ]">
        <div class="mb-4 flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line w-fit">
            <button v-for="m in modes" :key="m[0]" @click="setMode(m[0])" class="rounded-lg px-4 py-2 text-sm font-semibold transition" :class="mode === m[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ m[1] }}</button>
        </div>

        <!-- filters -->
        <div class="mb-4 rounded-2xl bg-card p-4 shadow-card ring-1 ring-line">
            <!-- ADMISSIONS -->
            <div v-if="mode === 'admissions'" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <input v-model="f.search" v-focus @keyup.enter="apply" :class="fld" aria-label="Search admissions by name or MRN" placeholder="Name or MRN" autocomplete="off" />
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
                    <span v-for="d in selectedDx" :key="d.code" class="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700"><span class="nums">{{ d.code }}</span> <button type="button" @click="removeDx(d.code)" class="text-brand-700 hover:text-on-danger">✕</button></span>
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
                    <template v-if="hasTerm">
                        <button type="button" @click="postExport('/registry/export-xlsx')" @mouseenter="loadMatchCount" @focus="loadMatchCount" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</button>
                        <button type="button" @click="postExport('/registry/export')" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</button>
                    </template>
                    <template v-else>
                        <a :href="`/registry/export-xlsx?${qs}`" @mouseenter="loadMatchCount" @focus="loadMatchCount" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</a>
                        <a :href="`/registry/export?${qs}`" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</a>
                    </template>
                </div>
            </div>
            <!-- CONSULTATIONS -->
            <div v-else-if="mode === 'consultations'" class="space-y-3">
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <input v-model="f.search" v-focus @keyup.enter="apply" :class="fld" aria-label="Search consultations by name or MRN" placeholder="Name or MRN" autocomplete="off" />
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
                    <template v-if="hasTerm">
                        <button type="button" @click="postExport('/registry/export-xlsx')" @mouseenter="loadMatchCount" @focus="loadMatchCount" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</button>
                        <button type="button" @click="postExport('/registry/export')" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</button>
                    </template>
                    <template v-else>
                        <a :href="`/registry/export-xlsx?${qs}`" @mouseenter="loadMatchCount" @focus="loadMatchCount" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</a>
                        <a :href="`/registry/export?${qs}`" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</a>
                    </template>
                </div>
            </div>
            <!-- DIAGNOSIS -->
            <div v-else class="flex flex-wrap items-end gap-3">
                <div class="grow"><label class="text-xs text-ink-400">Diagnosis keyword</label><input v-model="f.keyword" v-focus @keyup.enter="apply" :class="[fld, 'w-full']" aria-label="Diagnosis keyword search" placeholder="e.g. pneumonia, sepsis…" autocomplete="off" /></div>
                <div><label class="text-xs text-ink-400">Admitted from</label><input v-model="f.from" type="date" :class="fld" /></div>
                <div><label class="text-xs text-ink-400">to</label><input v-model="f.to" type="date" :class="fld" /></div>
                <button @click="apply" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">Search</button>
                <button @click="reset" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-500 hover:text-ink-700">Reset</button>
                <template v-if="hasTerm">
                    <button type="button" @click="postExport('/registry/export-xlsx')" @mouseenter="loadMatchCount" @focus="loadMatchCount" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</button>
                    <button type="button" @click="postExport('/registry/export')" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</button>
                </template>
                <template v-else>
                    <a :href="`/registry/export-xlsx?${qs}`" @mouseenter="loadMatchCount" @focus="loadMatchCount" class="rounded-xl bg-success-600 px-4 py-2 text-sm font-semibold text-white hover:bg-success-700">Excel</a>
                    <a :href="`/registry/export?${qs}`" class="rounded-xl px-3 py-2 text-sm font-semibold text-ink-600 ring-1 ring-ink-200 hover:bg-ink-50">CSV</a>
                </template>
            </div>
        </div>

        <div class="mb-2 flex flex-wrap items-center gap-2 text-sm text-ink-400">
            <span><span class="nums font-semibold text-ink-600">{{ results.total }}</span> result(s)</span>
            <!-- §3.6: pre-export matched-row count (loaded on hover/focus of the export buttons) -->
            <span v-if="matchCount !== null" class="nums text-xs">· export contains {{ matchCount.toLocaleString() }} rows</span>
            <!-- Wave 2, Item 5: density toggle (Comfortable/Compact row padding), persisted per-browser
                 under its OWN key so it never collides with the board's density preference. -->
            <button type="button" @click="setDensity(density === 'compact' ? 'comfortable' : 'compact')"
                :aria-pressed="density === 'compact'" title="Toggle compact row spacing"
                class="ml-auto rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-line transition hover:bg-ink-50"
                :class="density === 'compact' ? 'bg-brand-600 text-white ring-brand-600' : 'bg-card text-ink-500'">
                Compact rows
            </button>
        </div>
        <!-- §3.6: advisory banner for very large exports -->
        <div v-if="matchCount !== null && matchCount > 20000" class="mb-3 flex items-start gap-2 rounded-xl bg-tint-warning px-4 py-3 text-sm font-medium text-on-warning ring-1 ring-warning-500/20" role="alert">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <span>This export contains {{ matchCount.toLocaleString() }} rows and may take several minutes. Consider narrowing the filters.</span>
        </div>

        <!-- results. NOTE: no `overflow-hidden` here (Wave 2, Item 5) — a clipping ancestor between a
             `position: sticky` header and the viewport defeats the stickiness (the sticky element's
             scrolling context becomes this box instead of the page), so the rounded-2xl top corners
             are a deliberate, minor trade-off for a working sticky thead. -->
        <div class="rounded-2xl bg-card shadow-card ring-1 ring-line" :class="density === 'compact' ? 'density-compact' : 'density-comfortable'">
            <table v-if="mode === 'consultations'" class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <SortableTh label="Patient" sort-key="name" :current="sortState.state" class="sticky top-16 z-10 bg-card px-5 py-3" @sort="onSort" />
                        <th scope="col" class="sticky top-16 z-10 bg-card px-3 py-3">Location</th>
                        <th scope="col" class="sticky top-16 z-10 bg-card px-3 py-3">From → To</th>
                        <th scope="col" class="sticky top-16 z-10 bg-card px-3 py-3">Indication</th>
                        <th scope="col" class="sticky top-16 z-10 bg-card px-3 py-3">Consultant</th>
                        <SortableTh label="Date" sort-key="date" :current="sortState.state" class="sticky top-16 z-10 bg-card px-3 py-3" @sort="onSort" />
                        <SortableTh label="Status" sort-key="status" :current="sortState.state" class="sticky top-16 z-10 bg-card px-5 py-3" @sort="onSort" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="c in results.data" :key="c.id" class="transition-row hover:bg-tint-accent">
                        <td class="status-rail row-pad px-5" :class="consultRail(c.signoff)"><div class="font-semibold text-ink-800">{{ c.name }}</div><div class="nums text-xs text-ink-400">MRN {{ c.mrn }} · {{ c.age ?? '—' }}y</div></td>
                        <td class="row-pad px-3 text-ink-600">{{ c.location || '—' }}</td>
                        <td class="row-pad px-3 text-ink-600">{{ c.from || '—' }} <span class="text-ink-300">→</span> {{ c.to || '—' }}</td>
                        <td class="row-pad px-3"><span v-for="r in c.reasons" :key="r" class="mr-1 inline-block rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700">{{ r }}</span></td>
                        <td class="row-pad px-3 text-ink-600">{{ c.consultant }}</td>
                        <td class="nums row-pad px-3 text-ink-500">{{ c.date || '—' }}</td>
                        <td class="row-pad px-5"><span v-if="c.signoff" class="rounded-full bg-tint-success px-2.5 py-0.5 text-xs font-semibold text-on-success">Signed {{ c.signoff }}</span><span v-else class="rounded-full bg-tint-accent px-2.5 py-0.5 text-xs font-semibold text-on-accent">Active</span></td>
                    </tr>
                    <tr v-if="!results.data.length"><td colspan="7" class="px-5 py-10 text-center text-ink-400">{{ hasSearched ? 'No consultations match the current filters.' : 'No consultations match.' }}</td></tr>
                </tbody>
            </table>
            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th v-if="mode === 'admissions'" scope="col" class="sticky top-16 z-10 w-10 bg-card px-2 py-3"><span class="sr-only">Details</span></th>
                        <SortableTh label="Patient" sort-key="name" :current="sortState.state" class="sticky top-16 z-10 bg-card px-5 py-3" @sort="onSort" />
                        <th scope="col" class="sticky top-16 z-10 bg-card px-3 py-3">Age/Sex</th>
                        <th scope="col" class="sticky top-16 z-10 bg-card px-3 py-3">Location</th>
                        <th scope="col" class="sticky top-16 z-10 bg-card px-3 py-3">Consultant</th>
                        <SortableTh label="Admitted" sort-key="admit_date" :current="sortState.state" class="sticky top-16 z-10 bg-card px-3 py-3" @sort="onSort" />
                        <SortableTh label="Discharged" sort-key="discharge_date" :current="sortState.state" class="sticky top-16 z-10 bg-card px-3 py-3" @sort="onSort" />
                        <SortableTh label="LOS" sort-key="los" :current="sortState.state" class="sticky top-16 z-10 bg-card px-3 py-3" @sort="onSort" />
                        <SortableTh label="Outcome" sort-key="outcome" :current="sortState.state" class="sticky top-16 z-10 bg-card px-3 py-3" @sort="onSort" />
                        <th scope="col" class="sticky top-16 z-10 bg-card px-5 py-3 text-right">Edit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template v-for="r in results.data" :key="r.id">
                        <tr class="transition-row hover:bg-tint-accent">
                            <td v-if="mode === 'admissions'" class="status-rail row-pad px-2" :class="outcomeRail(r.outcome)">
                                <button @click="toggleExpand(r.id)" :aria-expanded="expanded.has(r.id) ? 'true' : 'false'" :aria-label="`Toggle details for ${r.name}`" class="grid h-7 w-7 place-items-center rounded-lg text-ink-400 ring-1 ring-line transition hover:bg-brand-50 hover:text-brand-700">
                                    <svg class="h-4 w-4 transition-transform" :class="expanded.has(r.id) && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </button>
                            </td>
                            <td class="row-pad px-5" :class="mode !== 'admissions' ? ['status-rail', outcomeRail(r.outcome)] : ''"><div class="font-semibold text-ink-800">{{ r.name }}</div><div class="nums text-xs text-ink-400">MRN {{ r.mrn }}</div></td>
                            <td class="nums row-pad px-3 text-ink-600">{{ r.age ?? '—' }} · {{ (r.gender||'—').slice(0,1) }}</td>
                            <td class="row-pad px-3 text-ink-600">{{ r.location || '—' }}</td>
                            <td class="row-pad px-3 text-ink-600">{{ r.consultant }}</td>
                            <td class="nums row-pad px-3 text-ink-500">{{ r.admit_date || '—' }}</td>
                            <td class="nums row-pad px-3 text-ink-500">{{ r.discharge_date || '—' }}</td>
                            <td class="nums row-pad px-3"><span v-if="r.los !== null" class="rounded-full px-2 py-0.5 text-xs font-bold" :class="losTone(r.los_band)">{{ r.los }}d</span><span v-else class="text-ink-300">—</span></td>
                            <td class="row-pad px-3"><span v-if="r.outcome" class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="outcomeTone(r.outcome)">{{ r.outcome }}</span><span v-else class="text-ink-300">—</span></td>
                            <td class="row-pad px-5 text-right"><button v-if="canModify" @click="openEdit(r.id)" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">Edit</button></td>
                        </tr>
                        <!-- expandable detail panel (admissions mode) -->
                        <tr v-if="mode === 'admissions' && expanded.has(r.id)" class="bg-app/60">
                            <td></td>
                            <td colspan="9" class="px-5 py-4">
                                <div v-if="r.is_tb || r.is_readmission || r.is_longterm || r.disch_still_in" class="mb-3 flex flex-wrap gap-1.5">
                                    <span v-if="r.is_tb" class="rounded-full bg-tint-danger px-2.5 py-0.5 text-xs font-semibold text-on-danger">TB</span>
                                    <span v-if="r.is_readmission" class="rounded-full bg-tint-warning px-2.5 py-0.5 text-xs font-semibold text-on-warning">≤{{ options.readmitWindow ?? 3 }}d readmit</span>
                                    <span v-if="r.is_longterm" class="rounded-full bg-tint-accent px-2.5 py-0.5 text-xs font-semibold text-on-accent">Long-term</span>
                                    <span v-if="r.disch_still_in" class="rounded-full bg-tint-warning px-2.5 py-0.5 text-xs font-semibold text-on-warning">Disch. still in</span>
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
                                    <div v-if="r.transfer_label"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-400">Transfer</dt><dd class="mt-0.5"><span class="rounded-full bg-tint-info px-2.5 py-0.5 text-xs font-semibold text-on-info">{{ r.transfer_label }}</span></dd></div>
                                </dl>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!results.data.length"><td :colspan="mode === 'admissions' ? 10 : 9" class="px-5 py-10 text-center text-ink-400">{{ hasSearched ? 'No admissions match the current filters.' : 'No admissions match.' }}</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="results.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ results.from }}–{{ results.to }} of {{ results.total }}</span>
            <!-- SPC-TM-011: page changes route through goPage() — with a term active the POST body
                 re-carries it (the links themselves are term-less GET URLs from the paginator) -->
            <div class="flex gap-1"><component :is="l.url ? 'button' : 'span'" v-for="l in results.links" :key="l.label" :type="l.url ? 'button' : undefined" @click="l.url && goPage(l.url)" class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition" :class="l.active ? 'bg-brand-600 text-white' : (l.url ? 'bg-card text-ink-600 ring-1 ring-line hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" /></div>
        </div>

        <!-- edit modal (canonical PatientForm — resolves the old free-text-nationality drift) -->
        <BaseModal :open="!!editing" title="Modify patient" size="lg" tall @close="closeEdit">
                <form @submit.prevent="submitEdit" class="space-y-3">
                    <PatientForm :form="mForm" :selected-dx="mDx" :countries="options.countries" :consultants="options.consultants" :today="today" :field-class="fld" @add-dx="mAdd" @remove-dx="mRemove" />
                    <!-- per-patient activity trail (Phase 2 — Item 2) -->
                    <details class="rounded-xl ring-1 ring-line">
                        <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold text-ink-700">Activity <span class="nums font-normal text-ink-400">({{ mActivity.length }})</span></summary>
                        <div class="px-3 pb-3"><ActivityPanel :items="mActivity" /></div>
                    </details>
                    <div class="flex justify-end gap-2 pt-1"><button type="button" @click="closeEdit" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save changes</button></div>
                </form>
        </BaseModal>
    </AppLayout>
</template>
