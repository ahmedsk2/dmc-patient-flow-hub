<script setup>
import { ref, watch, computed, onMounted, nextTick } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityPanel from '@/Components/ActivityPanel.vue';
import PatientFlags from '@/Components/PatientFlags.vue';
import BaseModal from '@/Components/BaseModal.vue';
import PatientForm from '@/Components/PatientForm.vue';
import ActionModal from '@/Components/Patients/ActionModal.vue';
import ReassignModal from '@/Components/Patients/ReassignModal.vue';
import HandoverModal from '@/Components/Patients/HandoverModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { usePatientEdit } from '@/composables/usePatientEdit';
import { localToday, vFocus, locTone } from '@/lib/ui.js';

const { ask } = useConfirm();

// The board's stateful modals (per-patient action / bulk reassign / handover) live in focused
// child components now (Wave 3, Item 4). Index keeps the board + per-row buttons that OPEN them and
// reloads/flashes on each child's `saved` emit. Each child owns its own useForm(s) + a11y via
// BaseModal. The Modify modal still uses the canonical PatientForm + usePatientEdit here.

const props = defineProps({ groups: Array, filters: Object, stats: Object, consultants: Array, specialties: Array, externalServices: Array, readmitWindow: Number, countries: Array, fallback: { type: Object, default: null } });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canAssign = computed(() => me.value.role !== 5 && (me.value.is_admin || me.value.can.assign));   // observers never see assign controls
const canReassign = computed(() => me.value.role !== 5 && (me.value.is_admin || me.value.can.assign || me.value.can.manage));
const isObserver = computed(() => me.value.role === 5);
const canManage = (row) => me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id;

const search = ref(props.filters.search || '');
const location = ref(props.filters.location || '');
const view = ref(props.filters.view || '');
// dashboard drill-through filters (Phase 1, Item 3) — set programmatically from the dashboard, carried
// through apply() so toolbar changes don't drop them; a Clear-filters chip resets everything.
const consultantId = ref(props.filters.consultant_id || '');
const specialtyId = ref(props.filters.specialty_id || '');
let timer = null;
const apply = () => router.get('/patients', {
        search: search.value || undefined, location: location.value || undefined, view: view.value || undefined,
        consultant_id: consultantId.value || undefined, specialty_id: specialtyId.value || undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
const setLocation = (l) => { location.value = location.value === l ? '' : l; apply(); };
const setView = (v) => { view.value = view.value === v ? '' : v; apply(); };

// board density — Comfortable/Compact, persisted per-browser (night-shift census fits more
// per screen). Pure presentation: a class on the board container, no data/request change.
const density = ref('comfortable');
const setDensity = (d) => { density.value = d; localStorage.setItem('dmc-density', d); };
const compact = computed(() => density.value === 'compact');

// Wave 2, Item 7: "show only my group" (consultant role only) — purely presentational; server data
// + D1 scoping are unchanged. Persisted per-browser, mirroring density.
const myGroupOnly = ref(false);
const setMyGroupOnly = (v) => { myGroupOnly.value = v; localStorage.setItem('dmc-board-my-group', v ? '1' : '0'); };
const visibleGroups = computed(() =>
    myGroupOnly.value && !me.value.is_admin
        ? props.groups.filter((g) => g.id === me.value.id)
        : props.groups);

// clear ALL filters (incl. the dashboard drill-through ones) — reset to the default board
const clearFilters = () => {
    search.value = ''; location.value = ''; view.value = ''; consultantId.value = ''; specialtyId.value = '';
    apply();
};
// collapsible consultant sections — expanded when a filter is active, else collapsed.
// Wave 2, Item 7: the expanded Set is persisted per-browser ('dmc-board-open') so a consultant's
// collapses survive navigation; restored in onMounted (intersected with the current group ids).
const filtering = computed(() => !!(props.filters.search || props.filters.view || props.filters.location
    || props.filters.consultant_id || props.filters.specialty_id));
const open = ref(new Set(filtering.value ? props.groups.map((g) => g.id) : []));
const persistOpen = () => localStorage.setItem('dmc-board-open', JSON.stringify([...open.value]));
const toggle = (id) => { open.value.has(id) ? open.value.delete(id) : open.value.add(id); open.value = new Set(open.value); persistOpen(); };
const allOpen = () => { open.value = new Set(props.groups.map((g) => g.id)); persistOpen(); };
const allClosed = () => { open.value = new Set(); persistOpen(); };

onMounted(() => {
    const d = localStorage.getItem('dmc-density');
    if (d === 'compact' || d === 'comfortable') density.value = d;
    myGroupOnly.value = localStorage.getItem('dmc-board-my-group') === '1';

    // board expand state — persisted per browser; intersect with current group ids (a group may
    // have been removed since the state was saved). Corrupt storage → fall back to the default.
    const saved = localStorage.getItem('dmc-board-open');
    if (saved) {
        try {
            const ids = JSON.parse(saved);
            if (Array.isArray(ids)) {
                const valid = new Set(props.groups.map((g) => g.id));
                open.value = new Set(ids.filter((id) => valid.has(id)));
            }
        } catch { /* corrupt storage — ignore, use default */ }
    }
    // first visit / all collapsed WHILE a filter is active → expand everything as before
    if (open.value.size === 0 && filtering.value) {
        open.value = new Set(props.groups.map((g) => g.id));
    }
});

// summary table buckets — operate on visibleGroups (Item 7: "my group only" filter)
const bucket = (g) => g.on_service && g.specialty_id === 1 ? 'hosp' : g.on_service ? 'subs' : 'off';
const sections = computed(() => [
    { key: 'hosp', label: 'On-service · Hospitalists', rows: visibleGroups.value.filter((g) => bucket(g) === 'hosp') },
    { key: 'subs', label: 'On-service · Subspecialists', rows: visibleGroups.value.filter((g) => bucket(g) === 'subs') },
    { key: 'off', label: 'Off-service', rows: visibleGroups.value.filter((g) => bucket(g) === 'off') },
]);

// diagnosis list expand — clicking the "N dx" badge reveals the names (read-only, all roles)
const dxOpen = ref(null);
const toggleDx = (id) => (dxOpen.value = dxOpen.value === id ? null : id);

// per-card kebab (touch only): collapses the rare actions (Delete / Long-term / Undo-medical)
// into one menu so the action row isn't cramped on coarse-pointer devices. Keyed by admission id.
const kebabOpen = ref(null);
const toggleKebab = (id) => (kebabOpen.value = kebabOpen.value === id ? null : id);
const closeKebab = () => (kebabOpen.value = null);

// inline bed edit — ANY clinical role, matching the J1-opened /bed endpoint (K1-2; was
// canManage-only affordance); observers stay read-only. Saves on blur/Enter, Esc cancels.
// (vFocus now imported from @/lib/ui.js — Item 6 shares one directive across pages.)
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

// ---- Index-level (non-modal) actions ----------------------------------------------------------
// Wave 2, Item 4: no confirm — shuffle is low-stakes + reversible (re-shuffle / manual reassign);
// the server's flash ("Shuffle assigned N patients…" / "No unassigned patients") is the feedback.
const shuffle = () => router.post('/admissions/shuffle', {}, { preserveScroll: true });

// board handover icon — tone/title come from the per-row `handover` summary the server ships with
// each card; clicking opens the HandoverModal child (which does the fetch + edit + save).
const fmtAt = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
// W0-T3e: the stale branch hovered to warning-600 as text, an undeclared step → no hover at all.
// `text-on-warning` is the AA-safe amber (5.93:1 light / 9.96:1 dark) and darkens on light, brightens
// on dark — the right affordance in both. warning-600 itself is a FILL token; as text it is 3.29:1.
const handoverTone = (p) => !p.handover ? 'text-ink-300 hover:text-ink-500' : p.handover.today ? 'text-brand-600 hover:text-brand-700' : 'text-on-warning hover:text-on-warning';
const handoverTitle = (p) => p.handover ? `Handover — last updated ${p.handover.updated_by || '—'} ${fmtAt(p.handover.updated_at)}` : 'No handover yet';

// ---- modal orchestration: Index OPENS the child modals + reloads/flashes on their `saved` --------
const today = localToday();

// per-patient ActionModal (assign / medical / complete / icu / transfer) — holds { mode, row }.
const modal = ref(null);
const openModal = (mode, row) => { modal.value = { mode, row }; };
const closeModal = () => { modal.value = null; };
const actionMode = computed(() => modal.value?.mode ?? '');
const actionPatient = computed(() => modal.value?.row ?? null);

// bulk ReassignModal — Index holds a template ref so the group-header / toolbar buttons can open it
// PRE-FILLED with from=this consultant (J2-11) / blank; the child owns the preflight + submit.
const reassign = ref(false);
const reassignModal = ref(null);
const openReassign = (fromId = '') => { reassign.value = true; nextTick(() => reassignModal.value?.openModal(fromId)); };
const closeReassign = () => { reassign.value = false; };

// per-patient HandoverModal — Index just toggles open + which patient; the child fetches on open.
const handoverOpen = ref(false);
const handoverPatient = ref(null);
const openHandover = (p) => { handoverPatient.value = p; handoverOpen.value = true; };
const closeHandover = () => { handoverOpen.value = false; };

// a child `saved` emit means the server already re-rendered the board (Inertia) — close + done.
const onActionSaved = () => closeModal();
const onReassignSaved = () => closeReassign();
const onHandoverSaved = () => closeHandover();

const longterm = (row) => router.post(`/admissions/${row.id}/longterm`, {}, { preserveScroll: true });
// the board shows active patients only, so the undo here is the phase-1 (medical) one;
// reversing a COMPLETED discharge lives on the admin Recent registry.
// Wave 2, Item 4: no confirm — this is itself a reversal (re-run medical-discharge to redo); the
// server flashes 'Medical discharge undone.'
const undoMedical = (row) => router.post(`/admissions/${row.id}/undo-medical-discharge`, {}, { preserveScroll: true });

// modify (full edit) — canonical PatientForm + usePatientEdit (fetch-on-open + identity-confirm).
// The Modify modal is a BaseModal (owns its own focus-trap + Esc), so a11yModify is gone.
const canModify = computed(() => me.value.is_admin || me.value.can.modify);
const { form: mForm, editing, selectedDx, activity: mActivity, open: openModify, addDx, removeDx, submit: submitModify } =
    usePatientEdit({ ask, onSuccess: () => (editing.value = null) });
const closeModify = () => { editing.value = null; };

// Esc handling now lives in BaseModal (each modal owns a window-level Escape listener, matching the
// old page dispatcher's scope — so the IcdTypeahead's first-Esc dropdown swallow still works).

// hard delete (admin only — server re-checks)
const destroyAdmission = async (row) => {
    if (await ask('Delete admission',
        `Permanently remove the episode for ${row.name} (MRN ${row.mrn}) and its diagnoses. This cannot be undone.`, 'danger'))
        router.delete(`/admissions/${row.id}`, { preserveScroll: true });
};

const losTone = (b) => b === 'short' ? 'bg-tint-success text-on-success' : b === 'long' ? 'bg-tint-danger text-on-danger' : 'bg-tint-warning text-on-warning';
</script>

<template>
    <AppLayout title="Active Patients">
        <!-- result-count announcement for screen readers (filters change the visible groups) -->
        <span class="sr-only" aria-live="polite" aria-atomic="true">
            {{ groups.length ? `${groups.length} consultant group(s) shown` : 'No results' }}
        </span>
        <!-- toolbar -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Census <span class="nums ml-1 text-brand-700">{{ stats.total }}</span></span>
            <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Ward (non-ICU) <span class="nums ml-1 text-brand-700">{{ stats.ward }}</span></span>
            <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">ICU <span class="nums ml-1 text-on-danger">{{ stats.icu }}</span></span>
            <!-- observers don't get the queue link — the page behind it is clinical-role only (J2-12) -->
            <!-- W0-T3e. Was accent-300 at /30 behind accent-600 text, hovering to /50 — 2.99:1 (light)
                 / 2.22:1 (dark) at rest, 1.26:1 (dark) on hover. Both fills were theme-invariant gold
                 at alpha, so on the dark board they LIGHTENED behind an already-pale label. The base
                 is now the theme-aware tint at /70 and the hover fills it in.

                 W0-T3h. Contrast FALLS on hover, it does not rise — filling in the tint moves the
                 backdrop AWAY from the page surface and toward the label. The earlier note said
                 "rises", and also composited against `bg-card`; this chip is a direct child of
                 AppLayout's <main>, which has no background, so the /70 rest fill actually sits on
                 `--surface-app`. With the olive `on-accent`:
                   rest  on tint-accent/70 over bg-app   7.15:1 light · 10.26:1 dark
                   hover on tint-accent (opaque)         7.00:1 light ·  9.42:1 dark
                 The opaque tint is the FLOOR, and the floor clears AA in both themes — which is the
                 property that actually matters. (Class names are spelled WITHOUT their utility prefix
                 on purpose — Tailwind's extractor reads comments, and naming them would re-emit the
                 rules we just retired.) -->
            <Link v-if="stats.unassigned && !isObserver" href="/admissions" class="inline-flex items-center gap-1.5 rounded-xl bg-tint-accent/70 px-3 py-2 text-sm font-semibold text-on-accent ring-1 ring-accent-300/50 transition hover:bg-tint-accent">
                {{ stats.unassigned }} awaiting assignment →
            </Link>

            <div class="relative ml-auto">
                <svg class="pointer-events-none absolute left-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" v-focus aria-label="Search patients by name or MRN" placeholder="Search name or MRN…" class="w-56 rounded-xl border border-ink-200 bg-card py-2 pl-10 pr-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line">
                <button v-for="l in ['Ward','ICU','ER']" :key="l" @click="setLocation(l)" class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="location === l ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ l }}</button>
                <button v-for="v in [['longterm','Long-term'],['tb','TB'],['boarding','Boarding']]" :key="v[0]" @click="setView(v[0])" class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="view === v[0] ? 'bg-accent-500 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ v[1] }}</button>
            </div>
            <!-- a dashboard drill-through (consultant/specialty) or any filter shows a Clear chip -->
            <button v-if="filtering" @click="clearFilters" class="inline-flex items-center gap-1.5 rounded-xl bg-ink-100 px-3 py-2 text-sm font-semibold text-ink-600 transition hover:bg-ink-200">
                Clear filters ✕
            </button>
            <!-- Item 7: "My patients only" — consultant role only (admins/registrars/residents see
                 all groups, which is correct). Pure client filter, persisted per-browser. -->
            <button v-if="me.role === 3 && !me.is_admin" @click="setMyGroupOnly(!myGroupOnly)"
                :aria-pressed="myGroupOnly" :title="myGroupOnly ? 'Showing only your group' : 'Show only your group'"
                class="rounded-xl px-3 py-2 text-sm font-semibold shadow-sm ring-1 transition"
                :class="myGroupOnly ? 'bg-accent-500 text-white ring-accent-500' : 'bg-card text-ink-500 ring-line hover:bg-ink-50'">My patients only</button>
            <!-- density: Comfortable/Compact (localStorage 'dmc-density'); compact tightens card padding + gaps -->
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line" role="group" aria-label="Board density">
                <button v-for="d in [['comfortable','Comfortable'],['compact','Compact']]" :key="d[0]" @click="setDensity(d[0])"
                    :aria-pressed="density === d[0]" :title="`${d[1]} board density`"
                    class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="density === d[0] ? 'bg-brand-600 text-white' : 'text-ink-500 hover:bg-ink-50'">{{ d[1] }}</button>
            </div>
            <a href="/active-list" target="_blank" title="Print board" aria-label="Print board (opens in a new tab)" class="grid h-9 w-9 place-items-center rounded-xl bg-card text-ink-500 shadow-sm ring-1 ring-line transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.4 42.4 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.32 0H6.34m11.32 0 .55-6.171M6.34 18l-.55-6.171m0 0a42.4 42.4 0 0 1 12.42 0M5.79 11.829V6.75A2.25 2.25 0 0 1 8.04 4.5h7.92a2.25 2.25 0 0 1 2.25 2.25v5.079" /></svg>
            </a>
            <button v-if="canAssign" @click="shuffle" title="Auto-assign unassigned" aria-label="Auto-assign unassigned" class="grid h-9 w-9 place-items-center rounded-xl bg-card text-ink-500 shadow-sm ring-1 ring-line transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
            </button>
            <button v-if="canReassign && !isObserver" @click="openReassign()" title="Bulk reassign" aria-label="Bulk reassign" class="grid h-9 w-9 place-items-center rounded-xl bg-card text-ink-500 shadow-sm ring-1 ring-line transition hover:bg-ink-50">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
            </button>
        </div>

        <!-- summary: patients per consultant (data-tour anchor for the onboarding tour, Item 10) -->
        <div data-tour="board" class="mb-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
          <div class="overflow-x-auto">
            <table class="min-w-[540px] w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-2.5">Consultant</th><th scope="col" class="px-3 py-2.5 text-center">Old</th><th scope="col" class="px-3 py-2.5 text-center">New</th><th scope="col" class="px-3 py-2.5 text-center">Active</th>
                        <th scope="col" class="px-3 py-2.5 text-center">Ward</th><th scope="col" class="px-3 py-2.5 text-center">ICU</th><th scope="col" class="px-3 py-2.5 text-center">TB</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template v-for="sec in sections" :key="sec.key">
                        <tr v-if="sec.rows.length" class="bg-app/70"><td colspan="7" class="px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-ink-500">{{ sec.label }}</td></tr>
                        <tr v-for="g in sec.rows" :key="g.id" class="cursor-pointer transition hover:bg-brand-50/40" @click="toggle(g.id)">
                            <td class="px-5 py-2 font-semibold text-ink-700">
                                <svg class="mr-1.5 inline h-4 w-4 text-ink-300" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="open.has(g.id) ? 'm4.5 15.75 7.5-7.5 7.5 7.5' : 'm19.5 8.25-7.5 7.5-7.5-7.5'" /></svg> Dr. {{ g.name }}
                            </td>
                            <td class="nums px-3 py-2 text-center text-ink-600">{{ g.counts.old || '' }}</td>
                            <td class="nums px-3 py-2 text-center text-on-info">{{ g.counts.new || '' }}</td>
                            <td class="nums px-3 py-2 text-center font-semibold text-brand-700">{{ g.counts.active }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-600">{{ g.counts.ward }}</td>
                            <td class="nums px-3 py-2 text-center text-on-danger">{{ g.counts.icu || '' }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-500">{{ g.counts.tb || '' }}</td>
                        </tr>
                    </template>
                    <tr v-if="!groups.length"><td colspan="7" class="px-5 py-8 text-center text-ink-400">No assigned patients match your filters.</td></tr>
                    <!-- Wave 2, Item 1: discharged/unassigned fall-through. Only shows when a search
                         returned an empty board AND there are matching discharged/unassigned rows.
                         Counts are D1-scoped server-side; the registry link 403s for non-admins (a
                         clean "not authorised" page, not a dead end) — owner-approved default. -->
                    <tr v-if="!groups.length && fallback && (fallback.discharged || fallback.unassigned)">
                        <td colspan="7" class="px-5 py-3 text-center text-sm text-ink-500">
                            No active match.
                            <span v-if="fallback.discharged">
                                Found <strong class="nums text-ink-700">{{ fallback.discharged }}</strong> discharged
                                <Link :href="`/registry?mode=admissions&search=${encodeURIComponent(fallback.search)}&discharged=1`"
                                      class="font-semibold text-brand-600 underline underline-offset-2 hover:text-brand-700">view →</Link>
                            </span>
                            <span v-if="fallback.unassigned">
                                / <strong class="nums text-ink-700">{{ fallback.unassigned }}</strong> awaiting assignment
                                <Link href="/admissions" class="font-semibold text-brand-600 underline underline-offset-2 hover:text-brand-700">queue →</Link>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
          </div>
        </div>

        <div v-if="groups.length" class="mb-3 flex gap-3 text-xs font-semibold text-brand-600">
            <button @click="allOpen" class="hover:underline">Expand all</button>
            <button @click="allClosed" class="hover:underline">Collapse all</button>
        </div>

        <!-- per-consultant patient cards (Item 7: visibleGroups honours "my group only") -->
        <div v-for="g in visibleGroups" :key="g.id" v-show="open.has(g.id)" class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line" :class="compact ? 'mb-2.5' : 'mb-4'">
            <div class="flex items-center justify-between border-b border-line px-5 py-3">
                <h3 class="font-bold text-ink-800">Dr. {{ g.name }} <span class="ml-1 text-sm font-normal text-ink-400">· {{ g.counts.total }} patient(s)</span></h3>
                <span class="flex items-center gap-2">
                    <!-- opens the bulk-reassign modal pre-filled with from=this consultant (J2-11).
                         Wave 2, Item 8 — canonical verb table (one verb per concept):
                           • move a patient to a different consultant → "Reassign" (single) / "Bulk reassign" (toolbar aria-label)
                           • first assignment of an unassigned patient → "Assign" / "Assign to me"
                         This group-header button changes a whole consultant's list → "Reassign". -->
                    <button v-if="canReassign && !isObserver && g.patients.length" @click="openReassign(g.id)"
                        class="rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">Reassign</button>
                    <button @click="toggle(g.id)" title="Collapse" aria-label="Collapse" class="text-ink-400 hover:text-ink-700"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg></button>
                </span>
            </div>
            <p v-if="!g.patients.length" class="px-5 py-4 text-sm text-ink-400">No patients on this list yet.</p>
            <div v-else class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" :class="compact ? 'gap-2 p-2.5' : 'gap-3 p-4'">
                <div v-for="p in g.patients" :key="p.id" class="rounded-xl ring-1 ring-line">
                    <div class="flex items-center justify-between rounded-t-xl bg-app/60" :class="compact ? 'px-2.5 py-1' : 'px-3 py-2'">
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="locTone(p.location)">
                            {{ p.location || '—' }} ·
                            <input v-if="bedEdit && bedEdit.id === p.id" v-model="bedEdit.value" v-focus maxlength="64"
                                aria-label="Bed" class="w-14 rounded border border-ink-200 bg-card px-1 py-0 text-[11px] font-semibold text-ink-700 outline-none focus:border-brand-500"
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
                    <div :class="compact ? 'px-2.5 py-1.5' : 'px-3 py-2'">
                        <div class="font-semibold text-ink-800">{{ p.name }}</div>
                        <div class="nums text-xs text-ink-400">MRN {{ p.mrn }} · {{ p.age ?? '—' }}y · {{ (p.gender||'—').slice(0,1) }}</div>
                        <div class="nums text-xs text-ink-400">Admitted {{ p.admit_date || '—' }}</div>
                        <!-- refined badge set (owner-approved, supersedes the loud legacy hex; J2-10):
                             token-based so the .dark remap covers both themes. Semantics kept:
                             New=info(blue), Readmit=warning(amber), Long-term=accent(gold subtle),
                             TB=danger(red infection alert — NOT success-green), Disch-still-in=neutral "in progress". -->
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            <PatientFlags :patient="p" :readmit-window="readmitWindow" variant="badge" />
                            <Link v-if="p.sign_pending" href="/handovers" title="Handover awaiting your signature" class="rounded-full bg-brand-100 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700 hover:bg-brand-200">Sign pending</Link>
                            <button v-if="p.dx_count" type="button" @click="toggleDx(p.id)" :aria-expanded="dxOpen === p.id"
                                :aria-label="`${p.dx_count} diagnoses — ${dxOpen === p.id ? 'hide' : 'show'} names`"
                                class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold transition"
                                :class="dxOpen === p.id ? 'bg-brand-100 text-brand-700' : 'bg-ink-50 text-ink-500 hover:bg-ink-100'">{{ p.dx_count }} dx</button>
                        </div>
                        <ul v-if="dxOpen === p.id && p.diagnoses?.length" class="mt-1.5 space-y-0.5 rounded-lg bg-app/70 px-2 py-1.5 text-[11px] leading-snug text-ink-600">
                            <li v-for="d in p.diagnoses" :key="d.code"><span class="nums font-semibold text-brand-700">{{ d.code }}</span> {{ d.name }}</li>
                        </ul>
                    </div>
                    <!-- Touch (coarse): primary buttons lift to 40px and the three rare actions
                         (long-term / undo-medical / delete) fold away into a kebab so the row stays
                         uncramped. Desktop layout is unchanged (h-7 + every action inline). -->
                    <div v-if="!isObserver && !p.discharged" class="flex items-center gap-1 coarse:gap-0.5 border-t border-ink-50 px-2" :class="compact ? 'py-1' : 'py-1.5'">
                        <button v-if="canAssign" @click="openModal('assign', p)" title="Reassign consultant" aria-label="Reassign consultant" class="grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-info-100 hover:text-info-500"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v6m3-3h-6m-3.75-1.875a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg></button>
                        <button v-if="canModify" @click="openModify(p)" title="Modify details" aria-label="Modify details" class="grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg></button>
                        <!-- long-term: rare action — hidden on touch (lives in the kebab below) -->
                        <button @click="longterm(p)" :title="p.is_longterm ? 'Remove long-term' : 'Mark long-term'" :aria-label="p.is_longterm ? 'Remove long-term' : 'Mark long-term'" class="grid h-7 w-7 coarse:hidden place-items-center rounded-lg hover:bg-tint-accent" :class="p.is_longterm ? 'text-on-accent' : 'text-ink-400 hover:text-on-accent'"><svg class="h-4 w-4" :fill="p.is_longterm ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" /></svg></button>
                        <button v-if="canManage(p)" @click="openModal('transfer', p)" title="Transfer" aria-label="Transfer" class="grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-brand-100 hover:text-brand-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg></button>
                        <template v-if="canManage(p)">
                            <button v-if="p.location === 'ICU'" @click="openModal('icu', p)" title="ICU discharge" aria-label="ICU discharge" class="ml-auto grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></button>
                            <template v-else-if="p.medically_discharged">
                                <button @click="openModal('complete', p)" title="Complete discharge" aria-label="Complete discharge" class="ml-auto grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-success-600 hover:bg-success-100"><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" /></svg></button>
                                <!-- undo medical: rare action — hidden on touch (kebab) -->
                                <button @click="undoMedical(p)" title="Undo medical discharge" aria-label="Undo medical discharge" class="grid h-7 w-7 coarse:hidden place-items-center rounded-lg text-ink-400 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg></button>
                            </template>
                            <button v-else @click="openModal('medical', p)" title="Discharge" aria-label="Discharge" class="ml-auto grid h-7 w-7 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-ink-400 hover:bg-success-100 hover:text-success-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75" /></svg></button>
                        </template>
                        <!-- delete: rare action — hidden on touch (kebab) -->
                        <button v-if="me.is_admin" @click="destroyAdmission(p)" title="Delete admission" aria-label="Delete admission" class="grid h-7 w-7 coarse:hidden place-items-center rounded-lg text-ink-400 hover:bg-danger-100 hover:text-danger-600"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg></button>
                        <!-- kebab (touch only): groups the three rare actions above -->
                        <div class="relative hidden coarse:block">
                            <button type="button" @click="toggleKebab(p.id)" :aria-expanded="kebabOpen === p.id" aria-haspopup="menu" title="More actions" aria-label="More actions" class="grid h-10 w-10 place-items-center rounded-lg text-ink-400 hover:bg-ink-50 hover:text-ink-700"><svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 6.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 6.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /></svg></button>
                            <!-- transparent backdrop closes the menu on outside tap -->
                            <div v-if="kebabOpen === p.id" class="fixed inset-0 z-0" @click="closeKebab"></div>
                            <div v-if="kebabOpen === p.id" role="menu" class="absolute right-0 bottom-12 z-10 w-44 overflow-hidden rounded-xl bg-card py-1 shadow-lg ring-1 ring-line" @keydown.esc="closeKebab">
                                <button type="button" role="menuitem" @click="longterm(p); closeKebab()" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-medium text-ink-700 hover:bg-ink-50">{{ p.is_longterm ? 'Remove long-term' : 'Mark long-term' }}</button>
                                <button v-if="canManage(p) && p.medically_discharged && p.location !== 'ICU'" type="button" role="menuitem" @click="undoMedical(p); closeKebab()" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-medium text-on-danger hover:bg-tint-danger">Undo medical discharge</button>
                                <button v-if="me.is_admin" type="button" role="menuitem" @click="destroyAdmission(p); closeKebab()" class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-medium text-on-danger hover:bg-tint-danger">Delete admission</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- per-patient action modal (assign / discharge / complete / icu / transfer) — owns its own
             useForms + the handover gate-then-retry; Index just opens it + reloads on `saved`. -->
        <ActionModal :open="!!modal" :mode="actionMode" :patient="actionPatient"
            :consultants="consultants" :specialties="specialties" :external-services="externalServices"
            :today="today" @saved="onActionSaved" @close="closeModal" />

        <!-- bulk reassign modal — owns the preflight gate + per-stale handover editors + subset submit -->
        <ReassignModal ref="reassignModal" :open="reassign" :consultants="consultants"
            @saved="onReassignSaved" @close="closeReassign" />

        <!-- per-patient handover editor (read for all roles; edit for canManage non-Observer) -->
        <HandoverModal :open="handoverOpen" :patient="handoverPatient"
            :can-manage="!!handoverPatient && canManage(handoverPatient)" :is-observer="isObserver"
            @saved="onHandoverSaved" @close="closeHandover" />

        <!-- modify modal (canonical PatientForm inside BaseModal) -->
        <BaseModal :open="!!editing" title="Modify patient" size="lg" tall @close="closeModify">
                <form @submit.prevent="submitModify" class="space-y-3">
                    <PatientForm :form="mForm" :selected-dx="selectedDx" :countries="countries" :consultants="consultants" :today="today" @add-dx="addDx" @remove-dx="removeDx" />
                    <!-- per-patient activity trail (Phase 2 — Item 2) -->
                    <details class="rounded-xl ring-1 ring-line">
                        <summary class="cursor-pointer select-none px-3 py-2 text-sm font-semibold text-ink-700">Activity <span class="nums font-normal text-ink-400">({{ mActivity.length }})</span></summary>
                        <div class="px-3 pb-3"><ActivityPanel :items="mActivity" /></div>
                    </details>
                    <div class="flex justify-end gap-2 pt-1"><button type="button" @click="closeModify" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mForm.processing" class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">Save changes</button></div>
                </form>
        </BaseModal>
    </AppLayout>
</template>
