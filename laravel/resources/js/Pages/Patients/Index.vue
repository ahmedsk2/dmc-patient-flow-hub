<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import IcdTypeahead from '@/Components/IcdTypeahead.vue';

const props = defineProps({ groups: Array, filters: Object, stats: Object, consultants: Array, specialties: Array, externalServices: Array, readmitWindow: Number, countries: Array });

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

// diagnosis list expand — clicking the "N dx" badge reveals the names (read-only, all roles)
const dxOpen = ref(null);
const toggleDx = (id) => (dxOpen.value = dxOpen.value === id ? null : id);

// inline bed edit — ANY clinical role, matching the J1-opened /bed endpoint (K1-2; was
// canManage-only affordance); observers stay read-only. Saves on blur/Enter, Esc cancels.
const vFocus = { mounted: (el) => el.focus() };
const bedEdit = ref(null);
const startBed = (p) => { if (!isObserver.value && !p.discharged) bedEdit.value = { id: p.id, value: p.bed || '' }; };
const cancelBed = () => (bedEdit.value = null);
const saveBed = (p) => {
    if (!bedEdit.value || bedEdit.value.id !== p.id) return;
    const value = bedEdit.value.value.trim();
    bedEdit.value = null;
    if (value === (p.bed || '')) return;   // unchanged — no request
    router.post(`/admissions/${p.id}/bed`, { bed: value || null }, { preserveScroll: true });
};

// shuffle + reassign + action modal
const shuffle = () => { if (confirm('Auto-assign all unassigned patients across on-service consultants?')) router.post('/admissions/shuffle', {}, { preserveScroll: true }); };
const reassign = ref(false);
const rForm = useForm({ from_consultant_id: '', to_consultant_id: '', mark_new: true, admission_ids: [] });
// group-header 'Change consultant' opens the bulk-reassign modal PRE-FILLED with from=this
// consultant (J2-11); the toolbar button opens it blank
const openReassign = (fromId = '') => { rForm.from_consultant_id = fromId; rForm.to_consultant_id = ''; reassign.value = true; };
const submitReassign = () => {
    rForm.admission_ids = [...selectedIds.value];   // SUBSET move: only the checked patients travel
    rForm.post('/admissions/reassign', { preserveScroll: true, onSuccess: () => { reassign.value = false; rForm.reset(); selectedIds.value = new Set(); } });
};

// ---- handover: board icon + modal + transfer-gate editors -------------------------------------
const fmtAt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const handoverTone = (p) => !p.handover ? 'text-ink-300 hover:text-ink-500' : p.handover.today ? 'text-brand-600 hover:text-brand-700' : 'text-warning-500 hover:text-warning-600';
const handoverTitle = (p) => p.handover ? `Handover — last updated ${p.handover.updated_by || '—'} ${fmtAt(p.handover.updated_at)}` : 'No handover yet';

// handover modal — read-only body + meta for all roles; Edit+Save for canManage; History collapsible
const hModal = ref(null);   // { row, data|null }
const hForm = useForm({ body: '' });
const hEditing = ref(false);
const histOpen = ref(false);
const openHandover = async (p) => {
    hModal.value = { row: p, data: null };
    hEditing.value = false; histOpen.value = false;
    hForm.reset(); hForm.clearErrors();
    const d = await (await fetch(`/admissions/${p.id}/handover`, { headers: { Accept: 'application/json' } })).json();
    if (hModal.value && hModal.value.row.id === p.id) { hModal.value.data = d; hForm.body = d.body || ''; }
};
const submitHandover = () => hForm.post(`/admissions/${hModal.value.row.id}/handover`, {
    preserveScroll: true, preserveState: true, onSuccess: () => (hModal.value = null),
});

// inline gate editor (assign + specialty-transfer modals): when the server rejects with the
// handover error, write today's handover right there, save, then retry the original submit
const gateBody = ref('');
const gateBusy = ref(false);
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const saveHandoverInline = async (admissionId, body) => {
    const res = await fetch(`/admissions/${admissionId}/handover`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify({ body }),
    });
    return res.ok;
};
const saveGateThen = async (retry) => {
    const body = gateBody.value.trim();
    if (!body || !modal.value) return;
    gateBusy.value = true;
    try { if (await saveHandoverInline(modal.value.row.id, body)) { gateBody.value = ''; retry(); } } finally { gateBusy.value = false; }
};

// bulk-reassign preflight: lists the consultant's patients with per-patient CHECKBOXES (all
// checked by default — uncheck to leave someone behind). Only SELECTED stale handovers need
// today's text; Confirm unlocks once every selected handover is current and ≥1 is selected.
const preflight = ref(null);   // null | { loading, rows: [{id,name,mrn,handover_today,body}] }
const preflightBodies = ref({});
const selectedIds = ref(new Set());
const toggleSelected = (id) => { selectedIds.value.has(id) ? selectedIds.value.delete(id) : selectedIds.value.add(id); selectedIds.value = new Set(selectedIds.value); };
const loadPreflight = async (id) => {
    preflight.value = { loading: true, rows: [] };
    const rows = await (await fetch(`/handovers/preflight?from_consultant_id=${id}`, { headers: { Accept: 'application/json' } })).json();
    preflightBodies.value = Object.fromEntries(rows.filter((r) => !r.handover_today).map((r) => [r.id, r.body || '']));
    selectedIds.value = new Set(rows.map((r) => r.id));   // all checked by default (legacy move-everything)
    preflight.value = { loading: false, rows };
};
watch(() => rForm.from_consultant_id, (id) => { preflight.value = null; selectedIds.value = new Set(); if (id) loadPreflight(id); });
const staleRows = computed(() => (preflight.value?.rows || []).filter((r) => !r.handover_today && selectedIds.value.has(r.id)));
const preflightReady = computed(() => !!preflight.value && !preflight.value.loading && selectedIds.value.size > 0 && staleRows.value.length === 0);
const allStaleFilled = computed(() => staleRows.value.every((r) => (preflightBodies.value[r.id] || '').trim().length > 0));
const savingAll = ref(false);
const saveAllStale = async () => {
    if (!allStaleFilled.value) return;
    savingAll.value = true;
    try {
        const keep = new Set(selectedIds.value);
        for (const r of staleRows.value) await saveHandoverInline(r.id, preflightBodies.value[r.id].trim());
        await loadPreflight(rForm.from_consultant_id);   // re-check — flips handover_today, unlocks Confirm
        selectedIds.value = keep;                        // reload defaults to all — restore the user's picks
    } finally { savingAll.value = false; }
};

const modal = ref(null);
const today = new Date().toISOString().slice(0, 10);
const aForm = useForm({ consultant_id: '', mark_new: true });
const mdForm = useForm({ outcome: 'Alive', medical_discharge_date: today, discharge_to: '', delay_reason: '', complete: false });
const cdForm = useForm({ discharge_date: today, outcome: '', discharge_to: '' });
const icuForm = useForm({ outcome: 'Alive', discharge_date: today, discharge_to: '' });
const tForm = useForm({ mode: 'location', target: 'ICU', specialty_id: '', consultant_id: '', service: '' });
const openModal = (mode, row) => {
    modal.value = { mode, row };
    gateBody.value = '';   // fresh inline-handover editor per patient
    if (mode === 'assign') { aForm.consultant_id = row.consultant_id || ''; aForm.mark_new = true; aForm.clearErrors(); }
    if (mode === 'medical') mdForm.reset();   // never carry a previous patient's type/destination over
    if (mode === 'complete') { cdForm.reset(); cdForm.outcome = row.outcome || ''; cdForm.discharge_to = row.discharge_to || ''; }   // prefill the optional override from phase-1
    if (mode === 'icu') icuForm.reset();
    if (mode === 'transfer') { tForm.reset(); tForm.target = row.location === 'ICU' ? 'Ward' : 'ICU'; }
};
// controlled destination vocabulary (legacy "Discharged to" list); a death locks to Mortuary.
// Status (outcome) is strictly Alive/Dead — LAMA/Absconded are DESTINATIONS, not outcomes.
const destinations = ['Home', 'Other Facility', 'LAMA', 'Absconded', 'Mortuary'];
const statuses = ['Alive', 'Dead'];
watch(() => mdForm.outcome, (o) => { if (o === 'Dead') mdForm.discharge_to = 'Mortuary'; else if (mdForm.discharge_to === 'Mortuary') mdForm.discharge_to = ''; });
watch(() => cdForm.outcome, (o) => { if (o === 'Dead') cdForm.discharge_to = 'Mortuary'; else if (cdForm.discharge_to === 'Mortuary') cdForm.discharge_to = ''; });
watch(() => icuForm.outcome, (o) => { if (o === 'Dead') icuForm.discharge_to = 'Mortuary'; else if (icuForm.discharge_to === 'Mortuary') icuForm.discharge_to = ''; });
// a closed file has no "still-in" delay; medical-only locks the status to Alive (legacy phase-1 —
// the status + destination are asked at the COMPLETE step instead)
watch(() => mdForm.complete, (c) => { if (c) { mdForm.delay_reason = ''; } else { mdForm.outcome = 'Alive'; mdForm.discharge_to = ''; } });
// internal-specialty handover: consultants offered are the ON-SERVICE ones of the chosen specialty
const specConsultants = computed(() => props.consultants.filter((c) => c.specialty_id === tForm.specialty_id && c.on_service));
// bulk reassign: the RECEIVING consultant must be on service ('from' stays unfiltered — patients
// may be moved away from someone who just went off service)
const onServiceConsultants = computed(() => props.consultants.filter((c) => c.on_service));
// single-assign modal: on-service only too, but keep the CURRENT assignee selectable even when
// they just went off service (so the prefilled selection isn't silently dropped) — J1-15a
const assignConsultants = computed(() => props.consultants.filter((c) =>
    c.on_service || (modal.value && c.id === modal.value.row.consultant_id)));
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
const admitFromOptions = ['ER', 'Clinic', 'OPD', 'OR', 'ICU', 'Referral', 'Transfer', 'Direct', 'Other service'];
const editing = ref(null);
const mForm = useForm({ mrn: '', name: '', age: '', gender: '', nationality: '', bed: '', admit_date: '', admitted_from: '', current_location: 'Ward', consultant_id: '', diagnoses: [] });
const selectedDx = ref([]);
const openModify = async (p) => {
    const d = await (await fetch(`/admissions/${p.id}/edit`, { headers: { Accept: 'application/json' } })).json();
    // keep the LOADED identity so a changed MRN/name is confirmed before posting (K1-3)
    editing.value = { id: p.id, mrn: d.mrn || '', name: d.name || '' };
    mForm.mrn = d.mrn || ''; mForm.name = d.name || ''; mForm.age = d.age ?? ''; mForm.gender = d.gender || '';
    mForm.nationality = d.nationality || ''; mForm.bed = d.bed || '';
    mForm.admit_date = d.admit_date || ''; mForm.admitted_from = d.admitted_from || ''; mForm.current_location = d.current_location || 'Ward';
    mForm.consultant_id = d.consultant_id || '';   // QUIET reassignment, legacy Modify semantics (J2-13)
    selectedDx.value = d.diagnoses || [];
    mForm.diagnoses = selectedDx.value.map((x) => x.code);
};
// on-service consultants for the Modify select; the current assignee stays selectable
const modifyConsultants = computed(() => props.consultants.filter((c) => c.on_service || c.id === mForm.consultant_id));
const addDx = (d) => { if (!selectedDx.value.find((x) => x.code === d.code)) { selectedDx.value.push(d); mForm.diagnoses.push(d.code); } };
const removeDx = (code) => { selectedDx.value = selectedDx.value.filter((x) => x.code !== code); mForm.diagnoses = mForm.diagnoses.filter((c) => c !== code); };
// identity confirm (K1-3): an MRN/name edit re-points or renames the patient — make it deliberate
const confirmIdentity = (loaded, mrn, name) =>
    (String(mrn) === String(loaded.mrn) && String(name) === String(loaded.name))
    || confirm(`Change patient identity from ${loaded.name} (MRN ${loaded.mrn}) to ${name} (MRN ${mrn})?`);
const submitModify = () => {
    if (!confirmIdentity(editing.value, mForm.mrn, mForm.name)) return;   // declined — no post
    mForm.post(`/admissions/${editing.value.id}/modify`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
};

// Esc closes whichever modal is open (the ICD typeahead swallows the first Esc while its
// dropdown is showing, so a second press closes the modal)
const onEsc = (e) => { if (e.key === 'Escape') { modal.value = null; reassign.value = false; hModal.value = null; editing.value = null; } };
onMounted(() => window.addEventListener('keydown', onEsc));
onUnmounted(() => window.removeEventListener('keydown', onEsc));

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
            <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">Ward (non-ICU) <span class="nums ml-1 text-brand-700">{{ stats.ward }}</span></span>
            <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-ink-100">ICU <span class="nums ml-1 text-danger-600">{{ stats.icu }}</span></span>
            <!-- observers don't get the queue link — the page behind it is clinical-role only (J2-12) -->
            <Link v-if="stats.unassigned && !isObserver" href="/admissions" class="inline-flex items-center gap-1.5 rounded-xl bg-accent-300/30 px-3 py-2 text-sm font-semibold text-accent-600 ring-1 ring-accent-300/50 transition hover:bg-accent-300/50">
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
            <a href="/active-list" target="_blank" title="Print board" aria-label="Print board (opens in a new tab)" class="grid h-9 w-9 place-items-center rounded-xl bg-white text-ink-500 shadow-sm ring-1 ring-ink-100 transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
            </a>
            <button v-if="canAssign" @click="shuffle" title="Auto-assign unassigned" aria-label="Auto-assign unassigned" class="grid h-9 w-9 place-items-center rounded-xl bg-white text-ink-500 shadow-sm ring-1 ring-ink-100 transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
            </button>
            <button v-if="canReassign && !isObserver" @click="openReassign()" title="Bulk reassign" aria-label="Bulk reassign" class="grid h-9 w-9 place-items-center rounded-xl bg-white text-ink-500 shadow-sm ring-1 ring-ink-100 transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
            </button>
        </div>

        <!-- summary: patients per consultant -->
        <div class="mb-5 overflow-hidden rounded-2xl bg-white shadow-card ring-1 ring-ink-100/60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-ink-100 text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-2.5">Consultant</th><th scope="col" class="px-3 py-2.5 text-center">New</th><th scope="col" class="px-3 py-2.5 text-center">Old</th><th scope="col" class="px-3 py-2.5 text-center">Active</th>
                        <th scope="col" class="px-3 py-2.5 text-center">Ward</th><th scope="col" class="px-3 py-2.5 text-center">ICU</th><th scope="col" class="px-3 py-2.5 text-center">TB</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-50">
                    <template v-for="sec in sections" :key="sec.key">
                        <tr v-if="sec.rows.length" class="bg-surface/70"><td colspan="7" class="px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-ink-500">{{ sec.label }}</td></tr>
                        <tr v-for="g in sec.rows" :key="g.id" class="cursor-pointer transition hover:bg-brand-50/40" @click="toggle(g.id)">
                            <td class="px-5 py-2 font-semibold text-ink-700">
                                <svg class="mr-1.5 inline h-4 w-4 text-ink-300" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="open.has(g.id) ? 'm4.5 15.75 7.5-7.5 7.5 7.5' : 'm19.5 8.25-7.5 7.5-7.5-7.5'" /></svg> Dr. {{ g.name }}
                            </td>
                            <td class="nums px-3 py-2 text-center text-info-500">{{ g.counts.new || '' }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-600">{{ g.counts.old || '' }}</td>
                            <td class="nums px-3 py-2 text-center font-semibold text-brand-700">{{ g.counts.active }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-600">{{ g.counts.ward }}</td>
                            <td class="nums px-3 py-2 text-center text-danger-600">{{ g.counts.icu || '' }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-500">{{ g.counts.tb || '' }}</td>
                        </tr>
                    </template>
                    <tr v-if="!groups.length"><td colspan="7" class="px-5 py-8 text-center text-ink-400">No assigned patients match your filters.</td></tr>
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
                <span class="flex items-center gap-2">
                    <!-- opens the bulk-reassign modal pre-filled with from=this consultant (J2-11) -->
                    <button v-if="canReassign && !isObserver && g.patients.length" @click="openReassign(g.id)"
                        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">Change consultant</button>
                    <button @click="toggle(g.id)" title="Collapse" aria-label="Collapse" class="text-ink-400 hover:text-ink-700"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg></button>
                </span>
            </div>
            <p v-if="!g.patients.length" class="px-5 py-4 text-sm text-ink-400">No patients on this list yet.</p>
            <div v-else class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div v-for="p in g.patients" :key="p.id" class="rounded-xl ring-1 ring-ink-100">
                    <div class="flex items-center justify-between rounded-t-xl bg-surface/60 px-3 py-2">
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="locTone(p.location)">
                            {{ p.location || '—' }} ·
                            <input v-if="bedEdit && bedEdit.id === p.id" v-model="bedEdit.value" v-focus maxlength="64"
                                aria-label="Bed" class="w-14 rounded border border-ink-200 bg-white px-1 py-0 text-[11px] font-semibold text-ink-700 outline-none focus:border-brand-500"
                                @blur="saveBed(p)" @keydown.enter.prevent="$event.target.blur()" @keydown.esc.prevent="cancelBed" />
                            <button v-else-if="!isObserver && !p.discharged" type="button" @click="startBed(p)" title="Edit bed" aria-label="Edit bed"
                                class="rounded underline decoration-dotted underline-offset-2 hover:opacity-75">{{ p.bed || '—' }}</button>
                            <template v-else>{{ p.bed || '—' }}</template>
                        </span>
                        <span class="flex items-center gap-1">
                            <button type="button" @click="openHandover(p)" :title="handoverTitle(p)" :aria-label="handoverTitle(p)"
                                class="grid h-6 w-6 place-items-center rounded-lg transition hover:bg-white/70" :class="handoverTone(p)">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
                            </button>
                            <span v-if="p.los !== null" class="nums rounded-full px-2 py-0.5 text-[11px] font-bold" :class="losTone(p.los_band)">{{ p.los }}d</span>
                        </span>
                    </div>
                    <div class="px-3 py-2">
                        <div class="font-semibold text-ink-800">{{ p.name }}</div>
                        <div class="nums text-xs text-ink-400">MRN {{ p.mrn }} · {{ p.age ?? '—' }}y · {{ (p.gender||'—').slice(0,1) }}</div>
                        <div class="nums text-xs text-ink-400">Admitted {{ p.admit_date || '—' }}</div>
                        <!-- legacy badge palette (owner decision, J2-10): New=red, Readmit=orange,
                             Long-term=brown, TB=bright green, Disch-still-in=gold -->
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <span v-if="p.is_new" class="rounded-full bg-[#fde8e8] px-1.5 py-0.5 text-[10px] font-semibold text-[#d12b1f]">New</span>
                            <span v-if="p.is_readmission" class="rounded-full bg-[#fff1dd] px-1.5 py-0.5 text-[10px] font-semibold text-[#cf6a00]">Readmit ≤{{ readmitWindow ?? 3 }}d</span>
                            <span v-if="p.is_longterm" class="rounded-full bg-[#f1e7da] px-1.5 py-0.5 text-[10px] font-semibold text-[#8a5a2b]">Long-term</span>
                            <span v-if="p.is_tb" class="rounded-full bg-[#01ff70] px-1.5 py-0.5 text-[10px] font-semibold text-[#0a3622]">TB</span>
                            <!-- long-term view lists closed episodes too — read-only card with a Discharged chip -->
                            <span v-if="p.discharged" class="rounded-full bg-ink-100 px-1.5 py-0.5 text-[10px] font-semibold text-ink-500">Discharged {{ p.discharge_date }}</span>
                            <span v-else-if="p.medically_discharged" class="rounded-full bg-[#fdf3d0] px-1.5 py-0.5 text-[10px] font-semibold text-[#a8740a]">Disch. still in</span>
                            <Link v-if="p.sign_pending" href="/handovers" title="Handover awaiting your signature" class="rounded-full bg-brand-100 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700 hover:bg-brand-200">Sign pending</Link>
                            <button v-if="p.dx_count" type="button" @click="toggleDx(p.id)" :aria-expanded="dxOpen === p.id"
                                :aria-label="`${p.dx_count} diagnoses — ${dxOpen === p.id ? 'hide' : 'show'} names`"
                                class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold transition"
                                :class="dxOpen === p.id ? 'bg-brand-100 text-brand-700' : 'bg-ink-50 text-ink-500 hover:bg-ink-100'">{{ p.dx_count }} dx</button>
                        </div>
                        <ul v-if="dxOpen === p.id && p.diagnoses?.length" class="mt-1.5 space-y-0.5 rounded-lg bg-surface/70 px-2 py-1.5 text-[11px] leading-snug text-ink-600">
                            <li v-for="d in p.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</li>
                        </ul>
                    </div>
                    <div v-if="!isObserver && !p.discharged" class="flex gap-1 border-t border-ink-50 px-2 py-1.5">
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
            <div class="max-h-[90vh] w-full max-w-md overflow-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-start justify-between">
                    <div><h3 class="text-lg font-bold text-ink-900">{{ ({ assign: 'Reassign consultant', medical: 'Discharge', complete: 'Complete discharge', icu: 'ICU discharge', transfer: 'Transfer' })[modal.mode] }}</h3><p class="text-sm text-ink-400">{{ modal.row.name }} · MRN {{ modal.row.mrn }}</p></div>
                    <button @click="closeModal" class="text-ink-400 hover:text-ink-700">✕</button>
                </div>
                <form v-if="modal.mode === 'assign'" @submit.prevent="submitAssign" class="space-y-4">
                    <select v-model="aForm.consultant_id" title="On-service consultants only" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select consultant…</option><option v-for="c in assignConsultants" :key="c.id" :value="c.id">{{ c.name }}{{ !c.on_service ? ' (off service)' : '' }}</option></select>
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="aForm.mark_new" class="rounded text-brand-600" /> Mark as new patient <span class="text-xs text-ink-400">(uncheck for a quiet administrative move — no “New” badge)</span></label>
                    <!-- handover gate: write today's handover here, save, retry the assign -->
                    <div v-if="aForm.errors.handover" class="rounded-xl bg-warning-100/60 p-3 ring-1 ring-warning-500/30">
                        <p class="text-xs font-semibold text-warning-500">{{ aForm.errors.handover }}</p>
                        <textarea v-model="gateBody" rows="3" maxlength="5000" placeholder="Write today's handover for this patient…" aria-label="Handover text" class="mt-2 w-full rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                        <div class="mt-2 flex justify-end"><button type="button" @click="saveGateThen(submitAssign)" :disabled="gateBusy || !gateBody.trim()" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save handover & assign</button></div>
                    </div>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="aForm.processing || !aForm.consultant_id" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Assign</button></div>
                </form>
                <form v-else-if="modal.mode === 'medical'" @submit.prevent="submitMedical" class="space-y-4">
                    <!-- record-review step (J1-15c): legacy discharge embedded the admission record
                         above the discharge fields — read-only summary here; edits go via Modify -->
                    <div class="rounded-xl bg-surface/70 p-3 ring-1 ring-ink-100">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-warning-500">Kindly review admission details</p>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <div><dt class="font-semibold text-ink-400">Name</dt><dd class="text-ink-700">{{ modal.row.name }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">MRN</dt><dd class="nums text-ink-700">{{ modal.row.mrn || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Age / Gender</dt><dd class="nums text-ink-700">{{ modal.row.age ?? '—' }}y · {{ modal.row.gender || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Bed</dt><dd class="nums text-ink-700">{{ modal.row.bed || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Admitted from</dt><dd class="text-ink-700">{{ modal.row.admitted_from || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Admit date</dt><dd class="nums text-ink-700">{{ modal.row.admit_date || '—' }}</dd></div>
                        </dl>
                        <ul v-if="modal.row.diagnoses?.length" class="mt-2 space-y-0.5 border-t border-ink-100 pt-2 text-[11px] leading-snug text-ink-600">
                            <li v-for="d in modal.row.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</li>
                        </ul>
                    </div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge type</label>
                        <div class="flex gap-2">
                            <label v-for="t in [[false, 'Medical only (still in bed)'], [true, 'Complete (leaving now)']]" :key="String(t[0])" class="flex-1 cursor-pointer rounded-xl border-2 px-3 py-2.5 text-center text-sm font-semibold transition" :class="mdForm.complete === t[0] ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="radio" v-model="mdForm.complete" :value="t[0]" class="hidden" /> {{ t[1] }}</label>
                        </div>
                    </div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Medical discharge date</label><input v-model="mdForm.medical_discharge_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="mdForm.errors.medical_discharge_date" class="mt-1 text-xs text-danger-600">{{ mdForm.errors.medical_discharge_date }}</p></div>
                    <!-- one-step close asks Status + Destination; medical-only locks Alive (asked at completion) -->
                    <div v-if="mdForm.complete" class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Status</label>
                            <select v-model="mdForm.outcome" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option v-for="s in statuses" :key="s">{{ s }}</option></select>
                            <p v-if="mdForm.errors.outcome" class="mt-1 text-xs text-danger-600">{{ mdForm.errors.outcome }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge to</label>
                            <select v-model="mdForm.discharge_to" :disabled="mdForm.outcome === 'Dead'" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">—</option><option v-for="d in destinations" :key="d">{{ d }}</option></select>
                            <p v-if="mdForm.errors.discharge_to" class="mt-1 text-xs text-danger-600">{{ mdForm.errors.discharge_to }}</p></div>
                    </div>
                    <div v-else><label class="mb-1 block text-sm font-semibold text-ink-700">Delay reason</label>
                        <select v-model="mdForm.delay_reason" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">—</option><option value="Physical">Physical bed availability</option><option value="System">System</option></select>
                        <p v-if="mdForm.errors.delay_reason" class="mt-1 text-xs text-danger-600">{{ mdForm.errors.delay_reason }}</p></div>
                    <p class="text-xs text-ink-400">{{ mdForm.complete ? 'Closes the file and frees the bed in one step.' : 'Marks the patient clinically discharged but still in a bed. Status and destination are recorded at Complete discharge, when they physically leave.' }}</p>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mdForm.processing" class="rounded-xl px-5 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50" :class="mdForm.complete ? 'bg-success-600 hover:bg-success-700' : 'bg-warning-500'">{{ mdForm.complete ? 'Discharge & close file' : 'Medical discharge' }}</button></div>
                </form>
                <form v-else-if="modal.mode === 'complete'" @submit.prevent="submitComplete" class="space-y-4">
                    <p class="text-sm text-ink-600">Close the file and free the bed.</p>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge date</label><input v-model="cdForm.discharge_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500" /><p v-if="cdForm.errors.discharge_date" class="mt-1 text-xs text-danger-600">{{ cdForm.errors.discharge_date }}</p></div>
                    <!-- optional override of the phase-1 outcome/destination (prefilled with the current values) -->
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Status</label>
                            <select v-model="cdForm.outcome" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">—</option><option v-for="s in statuses" :key="s">{{ s }}</option></select>
                            <p v-if="cdForm.errors.outcome" class="mt-1 text-xs text-danger-600">{{ cdForm.errors.outcome }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Destination</label>
                            <select v-model="cdForm.discharge_to" :disabled="cdForm.outcome === 'Dead'" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">—</option><option v-for="d in destinations" :key="d">{{ d }}</option></select>
                            <p v-if="cdForm.errors.discharge_to" class="mt-1 text-xs text-danger-600">{{ cdForm.errors.discharge_to }}</p></div>
                    </div>
                    <p class="text-xs text-ink-400">Status and destination carry over from the medical discharge — change them here only if the situation changed before the bed exit.</p>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeModal" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="cdForm.processing" class="rounded-xl bg-success-600 px-5 py-2 text-sm font-semibold text-white hover:bg-success-700 disabled:opacity-50">Complete discharge</button></div>
                </form>
                <form v-else-if="modal.mode === 'icu'" @submit.prevent="submitIcu" class="space-y-4">
                    <!-- record-review step (K1-11): same read-only summary as the medical-discharge
                         modal — review the admission record before closing the ICU file -->
                    <div class="rounded-xl bg-surface/70 p-3 ring-1 ring-ink-100">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-warning-500">Kindly review admission details</p>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <div><dt class="font-semibold text-ink-400">Name</dt><dd class="text-ink-700">{{ modal.row.name }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">MRN</dt><dd class="nums text-ink-700">{{ modal.row.mrn || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Age / Gender</dt><dd class="nums text-ink-700">{{ modal.row.age ?? '—' }}y · {{ modal.row.gender || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Bed</dt><dd class="nums text-ink-700">{{ modal.row.bed || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Admitted from</dt><dd class="text-ink-700">{{ modal.row.admitted_from || '—' }}</dd></div>
                            <div><dt class="font-semibold text-ink-400">Admit date</dt><dd class="nums text-ink-700">{{ modal.row.admit_date || '—' }}</dd></div>
                        </dl>
                        <ul v-if="modal.row.diagnoses?.length" class="mt-2 space-y-0.5 border-t border-ink-100 pt-2 text-[11px] leading-snug text-ink-600">
                            <li v-for="d in modal.row.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</li>
                        </ul>
                    </div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Status</label><select v-model="icuForm.outcome" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option v-for="s in statuses" :key="s">{{ s }}</option></select></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">Discharge to</label>
                        <select v-model="icuForm.discharge_to" :disabled="icuForm.outcome === 'Dead'" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">—</option><option v-for="d in destinations" :key="d">{{ d }}</option></select>
                        <p v-if="icuForm.errors.discharge_to" class="mt-1 text-xs text-danger-600">{{ icuForm.errors.discharge_to }}</p></div>
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
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Receiving consultant <span class="font-normal text-ink-400">(on-service only)</span></label>
                            <select v-model="tForm.consultant_id" :disabled="!tForm.specialty_id" title="On-service consultants only" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500 disabled:bg-ink-50"><option value="">Select consultant…</option><option v-for="c in specConsultants" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                            <p v-if="tForm.specialty_id && !specConsultants.length" class="mt-1 text-xs text-warning-500">No on-service consultants under this specialty.</p>
                            <p v-if="tForm.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ tForm.errors.consultant_id }}</p></div>
                        <p class="text-xs text-ink-400">Closes this episode as a specialty handover and opens a new one under the chosen consultant.</p>
                        <!-- handover gate: write today's handover here, save, retry the transfer -->
                        <div v-if="tForm.errors.handover" class="rounded-xl bg-warning-100/60 p-3 ring-1 ring-warning-500/30">
                            <p class="text-xs font-semibold text-warning-500">{{ tForm.errors.handover }}</p>
                            <textarea v-model="gateBody" rows="3" maxlength="5000" placeholder="Write today's handover for this patient…" aria-label="Handover text" class="mt-2 w-full rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                            <div class="mt-2 flex justify-end"><button type="button" @click="saveGateThen(submitTransfer)" :disabled="gateBusy || !gateBody.trim()" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save handover & transfer</button></div>
                        </div>
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
            <div class="max-h-[90vh] w-full max-w-md overflow-auto rounded-2xl bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-bold text-ink-900">Reassign a consultant's patients</h3>
                <p class="mb-4 text-sm text-ink-400">Moves the selected active patients from one consultant to another.</p>
                <form @submit.prevent="submitReassign" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">From</label><select v-model="rForm.from_consultant_id" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select…</option><option v-for="c in consultants" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                    <div><label class="mb-1 block text-sm font-semibold text-ink-700">To <span class="font-normal text-ink-400">(on-service only)</span></label><select v-model="rForm.to_consultant_id" title="On-service consultants only" class="w-full rounded-xl border border-ink-200 px-3 py-2.5 text-sm outline-none focus:border-brand-500"><option value="">Select…</option><option v-for="c in onServiceConsultants" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                    <label class="flex items-center gap-2 text-sm text-ink-600"><input type="checkbox" v-model="rForm.mark_new" class="rounded text-brand-600" /> Mark as new patients <span class="text-xs text-ink-400">(uncheck to keep their current “New” status)</span></label>

                    <!-- preflight: pick WHO moves (all checked by default); every SELECTED patient
                         needs a handover updated TODAY before the move unlocks -->
                    <div v-if="preflight" class="rounded-xl bg-surface/70 p-3 ring-1 ring-ink-100">
                        <p v-if="preflight.loading" class="text-sm text-ink-400">Checking handovers…</p>
                        <template v-else-if="preflight.rows.length">
                            <p class="text-xs font-semibold text-ink-600">{{ selectedIds.size }} of {{ preflight.rows.length }} patient(s) selected to move — uncheck to leave someone behind.</p>
                            <ul class="mt-2 max-h-44 space-y-1 overflow-auto">
                                <li v-for="r in preflight.rows" :key="r.id">
                                    <label class="flex items-center gap-2 text-sm text-ink-700">
                                        <input type="checkbox" :checked="selectedIds.has(r.id)" @change="toggleSelected(r.id)" class="rounded text-brand-600" />
                                        <span class="font-semibold">{{ r.name }}</span>
                                        <span class="nums text-xs text-ink-400">MRN {{ r.mrn }}</span>
                                        <span v-if="!r.handover_today" class="ml-auto rounded-full bg-warning-100 px-2 py-0.5 text-[10px] font-semibold text-warning-500">handover stale</span>
                                        <span v-else class="ml-auto rounded-full bg-success-100 px-2 py-0.5 text-[10px] font-semibold text-success-600">today ✓</span>
                                    </label>
                                </li>
                            </ul>
                            <template v-if="staleRows.length">
                                <p class="mt-3 text-xs font-semibold text-warning-500">{{ staleRows.length }} selected patient(s) need today's handover before the move:</p>
                                <div v-for="r in staleRows" :key="'h' + r.id" class="mt-2">
                                    <p class="text-xs font-semibold text-ink-700">{{ r.name }} <span class="nums font-normal text-ink-400">MRN {{ r.mrn }}</span></p>
                                    <textarea v-model="preflightBodies[r.id]" rows="2" maxlength="5000" :aria-label="`Handover for ${r.name}`" placeholder="Write today's handover…" class="mt-1 w-full rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                                </div>
                                <div class="mt-2 flex justify-end"><button type="button" @click="saveAllStale" :disabled="savingAll || !allStaleFilled" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50">{{ savingAll ? 'Saving…' : 'Save all handovers' }}</button></div>
                            </template>
                            <p v-else-if="selectedIds.size" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-success-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                All {{ selectedIds.size }} selected handover(s) updated today — ready to move.
                            </p>
                            <p v-else class="mt-2 text-xs font-semibold text-warning-500">Select at least one patient to move.</p>
                        </template>
                        <p v-else class="text-xs text-ink-400">No active patients under this consultant.</p>
                    </div>
                    <p v-if="rForm.errors.handover" class="text-xs font-semibold text-danger-600">{{ rForm.errors.handover }}</p>
                    <p v-if="rForm.errors.admission_ids" class="text-xs font-semibold text-danger-600">{{ rForm.errors.admission_ids }}</p>

                    <div class="flex justify-end gap-2"><button type="button" @click="reassign = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="rForm.processing || !rForm.from_consultant_id || !rForm.to_consultant_id || !preflightReady" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Reassign {{ selectedIds.size || '' }} selected</button></div>
                </form>
            </div>
        </div>

        <!-- handover modal (read for all roles; edit for canManage) -->
        <div v-if="hModal" class="fixed inset-0 z-50 grid place-items-center bg-navy-950/40 p-4 backdrop-blur-sm" @click.self="hModal = null">
            <div class="max-h-[90vh] w-full max-w-lg overflow-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-start justify-between">
                    <div><h3 class="text-lg font-bold text-ink-900">Handover</h3><p class="text-sm text-ink-400">{{ hModal.row.name }} · MRN {{ hModal.row.mrn }}</p></div>
                    <button @click="hModal = null" aria-label="Close" class="text-ink-400 hover:text-ink-700">✕</button>
                </div>
                <p v-if="!hModal.data" class="py-6 text-center text-sm text-ink-400">Loading…</p>
                <template v-else>
                    <p class="mb-2 text-xs text-ink-400">
                        {{ hModal.data.updated_at ? `Last updated by ${hModal.data.updated_by_name || '—'} · ${fmtAt(hModal.data.updated_at)}` : 'No handover yet.' }}
                        <span v-if="hModal.data.updated_at" class="ml-1 rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="hModal.data.today ? 'bg-brand-100 text-brand-700' : 'bg-warning-100 text-warning-500'">{{ hModal.data.today ? 'Updated today' : 'Stale' }}</span>
                    </p>
                    <template v-if="hEditing">
                        <textarea v-model="hForm.body" rows="6" maxlength="5000" aria-label="Handover text" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"></textarea>
                        <p v-if="hForm.errors.body" class="mt-1 text-xs text-danger-600">{{ hForm.errors.body }}</p>
                        <div class="mt-3 flex justify-end gap-2">
                            <button type="button" @click="hEditing = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                            <button type="button" @click="submitHandover" :disabled="hForm.processing || !hForm.body.trim()" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save</button>
                        </div>
                    </template>
                    <template v-else>
                        <p class="whitespace-pre-wrap rounded-xl bg-surface/70 px-3 py-2.5 text-sm leading-relaxed text-ink-700">{{ hModal.data.body || 'No handover text recorded.' }}</p>
                        <div class="mt-3 flex items-center justify-between">
                            <button v-if="hModal.data.revisions?.length" type="button" @click="histOpen = !histOpen" :aria-expanded="histOpen" class="text-xs font-semibold text-brand-600 hover:underline">{{ histOpen ? 'Hide history' : `History (${hModal.data.revisions.length})` }}</button>
                            <span v-else class="text-xs text-ink-400">No history yet.</span>
                            <button v-if="canManage(hModal.row) && !isObserver" type="button" @click="hEditing = true" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Edit</button>
                        </div>
                        <ul v-if="histOpen" class="mt-3 max-h-56 space-y-2 overflow-auto">
                            <li v-for="(r, i) in hModal.data.revisions" :key="i" class="rounded-xl ring-1 ring-ink-100">
                                <p class="rounded-t-xl bg-surface/60 px-3 py-1.5 text-[11px] font-semibold text-ink-500">{{ r.author || '—' }} · {{ fmtAt(r.at) }}</p>
                                <p class="whitespace-pre-wrap px-3 py-2 text-xs leading-relaxed text-ink-600">{{ r.body }}</p>
                            </li>
                        </ul>
                    </template>
                </template>
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
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Nationality</label>
                            <select v-model="mForm.nationality" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500">
                                <option value="">—</option>
                                <!-- keep a dirty legacy value selectable (validate-only-on-change) -->
                                <option v-if="mForm.nationality && !countries.includes(mForm.nationality)" :value="mForm.nationality">{{ mForm.nationality }} (legacy)</option>
                                <option v-for="c in countries" :key="c">{{ c }}</option>
                            </select>
                            <p v-if="mForm.errors.nationality" class="mt-1 text-xs text-danger-600">{{ mForm.errors.nationality }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Admit date</label><input v-model="mForm.admit_date" type="date" :max="today" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" :class="{ 'border-danger-500': mForm.errors.admit_date }" /><p v-if="mForm.errors.admit_date" class="mt-1 text-xs text-danger-600">{{ mForm.errors.admit_date }}</p></div>
                        <div><label class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select v-model="mForm.current_location" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"><option>ER</option><option>Ward</option><option>ICU</option></select></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Admitted from</label><input v-model="mForm.admitted_from" list="admit-from-options" placeholder="ER, Clinic, Referral…" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500" /><datalist id="admit-from-options"><option v-for="o in admitFromOptions" :key="o" :value="o" /></datalist></div>
                        <div class="col-span-2"><label class="mb-1 block text-sm font-semibold text-ink-700">Consultant <span class="font-normal text-ink-400">(quiet change — no “New” badge)</span></label>
                            <select v-model="mForm.consultant_id" title="On-service consultants only" class="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500">
                                <option value="">— no change —</option>
                                <option v-for="c in modifyConsultants" :key="c.id" :value="c.id">{{ c.name }}{{ !c.on_service ? ' (off service)' : '' }}</option>
                            </select>
                            <p v-if="mForm.errors.consultant_id" class="mt-1 text-xs text-danger-600">{{ mForm.errors.consultant_id }}</p></div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-ink-700">Diagnoses</label>
                        <IcdTypeahead @select="addDx" />
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
