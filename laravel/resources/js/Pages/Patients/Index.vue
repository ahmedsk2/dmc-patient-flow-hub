<script setup>
import { ref, watch, computed, onMounted, nextTick } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActivityPanel from '@/Components/ActivityPanel.vue';
import BaseModal from '@/Components/BaseModal.vue';
import PatientForm from '@/Components/PatientForm.vue';
import PatientCard from '@/Components/Patients/PatientCard.vue';
import ActionModal from '@/Components/Patients/ActionModal.vue';
import ReassignModal from '@/Components/Patients/ReassignModal.vue';
import HandoverModal from '@/Components/Patients/HandoverModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { usePatientEdit } from '@/composables/usePatientEdit';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { localToday, vFocus } from '@/lib/ui.js';

const { ask } = useConfirm();

// The board's stateful modals (per-patient action / bulk reassign / handover) live in focused
// child components now (Wave 3, Item 4). Index keeps the board + per-row buttons that OPEN them and
// reloads/flashes on each child's `saved` emit. Each child owns its own useForm(s) + a11y via
// BaseModal. The Modify modal still uses the canonical PatientForm + usePatientEdit here.

const props = defineProps({ groups: Array, filters: Object, stats: Object, consultants: Array, specialties: Array, externalServices: Array, readmitWindow: Number, countries: Array, fallback: { type: Object, default: null }, highlight: { type: Number, default: null } });

const page = usePage();
const me = computed(() => page.props.auth.user);
const canAssign = computed(() => me.value.role !== 5 && (me.value.is_admin || me.value.can.assign));   // observers never see assign controls
const canReassign = computed(() => me.value.role !== 5 && (me.value.is_admin || me.value.can.assign || me.value.can.manage));
const isObserver = computed(() => me.value.role === 5);
// still needed here for the HandoverModal's can-manage gate (the board's per-card canManage now lives
// inside PatientCard, but the handover editor is an Index-owned modal).
const canManage = (row) => me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id;

const search = ref(props.filters.search || '');
const location = ref(props.filters.location || '');
const view = ref(props.filters.view || '');
// dashboard drill-through filters (Phase 1, Item 3) — set programmatically from the dashboard, carried
// through apply() so toolbar changes don't drop them; a Clear-filters chip resets everything.
const consultantId = ref(props.filters.consultant_id || '');
const specialtyId = ref(props.filters.specialty_id || '');
let timer = null;
// SPC-TM-011 (Wave 1): the free-text term is patient name/MRN, so a term-carrying visit POSTs it
// in the body (non-PII filters ride the query string and stay shareable); a term-less visit keeps
// the plain GET flow exactly as before. Legacy GET-with-term URLs redirect term-less server-side.
const nonPiiFilters = () => ({
    location: location.value || undefined, view: view.value || undefined,
    consultant_id: consultantId.value || undefined, specialty_id: specialtyId.value || undefined,
});
const apply = () => {
    const opts = { preserveState: true, replace: true, preserveScroll: true };
    if (search.value.trim()) {
        const q = new URLSearchParams(Object.entries(nonPiiFilters()).filter(([, v]) => v !== undefined)).toString();
        router.post(`/patients${q ? `?${q}` : ''}`, { search: search.value }, opts);
    } else {
        router.get('/patients', nonPiiFilters(), opts);
    }
};
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

// board view mode — Grouped (roster + stacked consultant cards) vs Split (master–detail: a pinned
// consultant rail + one consultant's cards in a detail pane). Persisted per-browser ('dmc-board-view').
// Both are pure presentation over the SAME server data; the toggle only re-lays-out the board.
const viewMode = ref('grouped');
const setViewMode = (v) => { viewMode.value = v; localStorage.setItem('dmc-board-view', v); };
// Split selection: a consultant group id, or the sentinel 'all' (flat census). `selectedId` starts
// null → resolves to the first visible group; a filter that removes the selection falls back the same.
const selectedId = ref(null);
const selectGroup = (id) => { selectedId.value = id; };
const selectedGroup = computed(() => {
    if (selectedId.value === 'all') return null;
    const id = selectedId.value ?? visibleGroups.value[0]?.id;
    return visibleGroups.value.find((g) => g.id === id) || visibleGroups.value[0] || null;
});
// which rail row reads as selected (resolves null → first group; keeps 'all' as-is)
const railSelectedId = computed(() => (selectedId.value === 'all' ? 'all' : (selectedGroup.value?.id ?? null)));
const allPatients = computed(() => visibleGroups.value.flatMap((g) => g.patients || []));

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
// group-section element refs (grouped view) — used to bring a consultant's cards into view + move
// focus there the instant it's expanded, so opening from the roster no longer means scroll-hunting.
const groupEls = {};
const setGroupEl = (id, el) => { if (el) groupEls[id] = el; else delete groupEls[id]; };
const prefersReducedMotion = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const toggle = (id) => {
    const wasOpen = open.value.has(id);
    wasOpen ? open.value.delete(id) : open.value.add(id);
    open.value = new Set(open.value);
    persistOpen();
    // just OPENED in the grouped board → scroll that consultant into view + focus the section
    if (!wasOpen && viewMode.value === 'grouped') {
        nextTick(() => {
            const el = groupEls[id];
            if (!el) return;
            el.scrollIntoView?.({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
            el.focus?.();
        });
    }
};
const allOpen = () => { open.value = new Set(props.groups.map((g) => g.id)); persistOpen(); };
const allClosed = () => { open.value = new Set(); persistOpen(); };

// deep-link target from the incomplete-handover reminder bell (?highlight=<admission_id>, Fix B) —
// the matching PatientCard briefly rings once it scrolls into view (see onMounted below). Cleared
// after ~2s; a no-op id (nothing matches, or already cleared) just means no card gets the class.
const highlightedId = ref(null);

onMounted(() => {
    const d = localStorage.getItem('dmc-density');
    if (d === 'compact' || d === 'comfortable') density.value = d;
    const vm = localStorage.getItem('dmc-board-view');
    if (vm === 'grouped' || vm === 'split') viewMode.value = vm;
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

    // deep-link: ?highlight=<admission_id> (Fix B) — find the consultant group holding that
    // admission, force it open (+ switch Split's rail to it), scroll the matching card into view,
    // and flash it briefly. Guarded throughout: no-op if highlight is absent, or the admission no
    // longer matches any visible patient (discharged/reassigned/filtered out since the reminder
    // fired) — never throws.
    if (props.highlight) {
        const targetGroup = props.groups.find((g) => (g.patients || []).some((p) => p.id === props.highlight));
        if (targetGroup) {
            open.value = new Set(open.value).add(targetGroup.id);
            persistOpen();
            if (viewMode.value === 'split') selectGroup(targetGroup.id);
            highlightedId.value = props.highlight;
            // two ticks: one for the open/selection state to flush, one for the now-visible card to mount
            nextTick(() => nextTick(() => {
                document.querySelector(`[data-admission-id="${props.highlight}"]`)?.scrollIntoView?.({
                    behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center',
                });
            }));
            setTimeout(() => { highlightedId.value = null; }, 2000);
        }
    }
});

// summary table buckets — operate on visibleGroups (Item 7: "my group only" filter)
const bucket = (g) => g.on_service && g.specialty_id === 1 ? 'hosp' : g.on_service ? 'subs' : 'off';
const sections = computed(() => [
    { key: 'hosp', label: 'On-service · Hospitalists', rows: visibleGroups.value.filter((g) => bucket(g) === 'hosp') },
    { key: 'subs', label: 'On-service · Subspecialists', rows: visibleGroups.value.filter((g) => bucket(g) === 'subs') },
    { key: 'off', label: 'Off-service', rows: visibleGroups.value.filter((g) => bucket(g) === 'off') },
]);

// ---- Index-level (non-modal) actions ----------------------------------------------------------
// Wave 2, Item 4: no confirm — shuffle is low-stakes + reversible (re-shuffle / manual reassign);
// the server's flash ("Shuffle assigned N patients…" / "No unassigned patients") is the feedback.
const shuffle = () => router.post('/admissions/shuffle', {}, { preserveScroll: true });

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

// modify (full edit) — canonical PatientForm + usePatientEdit (fetch-on-open + identity-confirm).
// The Modify modal is a BaseModal (owns its own focus-trap + Esc), so a11yModify is gone.
const { form: mForm, editing, selectedDx, activity: mActivity, open: openModify, addDx, removeDx, submit: submitModify, isDirty: mIsDirty } =
    usePatientEdit({ ask, onSuccess: () => (editing.value = null) });
// Wave 3, Item 1: closing Modify with unsaved edits (Esc/backdrop/X via @close, or the footer
// Cancel) routes through the shared discard-confirm; a clean form closes with no prompt. Kept as
// one guarded handler on both paths so there is a single prompt on every close route.
const { guardedClose: guardModify } = useUnsavedGuard(mIsDirty, ask);
const closeModify = () => guardModify(() => { editing.value = null; });

// Esc handling now lives in BaseModal (each modal owns a window-level Escape listener, matching the
// old page dispatcher's scope — so the IcdTypeahead's first-Esc dropdown swallow still works).

</script>

<template>
    <AppLayout title="Active Patients">
        <!-- result-count announcement for screen readers (filters change the visible groups) -->
        <span class="sr-only" aria-live="polite" aria-atomic="true">
            {{ groups.length ? `${groups.length} consultant group(s) shown` : 'No results' }}
        </span>
        <!-- toolbar -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Census <span class="nums ms-1 text-brand-700">{{ stats.total }}</span></span>
            <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Ward (non-ICU) <span class="nums ms-1 text-brand-700">{{ stats.ward }}</span></span>
            <span class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">ICU <span class="nums ms-1 text-on-danger">{{ stats.icu }}</span></span>
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

            <div class="relative ms-auto">
                <svg class="pointer-events-none absolute start-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" v-focus aria-label="Search patients by name or MRN" placeholder="Search name or MRN…" autocomplete="off" class="w-56 rounded-xl border border-ink-200 bg-card py-2 ps-10 pe-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line">
                <button v-for="l in ['Ward','ICU','ER']" :key="l" @click="setLocation(l)" class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="location === l ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">{{ l }}</button>
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
                    class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="density === d[0] ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">{{ d[1] }}</button>
            </div>
            <!-- board layout: Grouped (roster + stacked cards) vs Split (master–detail). Persisted per
                 browser ('dmc-board-view'); pure presentation over the same data. -->
            <div class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line" role="group" aria-label="Board layout">
                <button v-for="vm in [['grouped','Grouped'],['split','Split']]" :key="vm[0]" @click="setViewMode(vm[0])"
                    :aria-pressed="viewMode === vm[0]" :title="`${vm[1]} board layout`"
                    class="rounded-lg px-2.5 py-1.5 text-sm font-semibold transition" :class="viewMode === vm[0] ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">{{ vm[1] }}</button>
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

        <!-- empty board + discharged/unassigned fall-through (shown in BOTH layouts) -->
        <div v-if="!groups.length" class="rounded-2xl bg-card p-8 text-center shadow-card ring-1 ring-line">
            <p class="text-ink-400">No assigned patients match your filters.</p>
            <!-- Wave 2, Item 1: discharged/unassigned fall-through. Counts are D1-scoped server-side; the
                 registry link 403s for non-admins (a clean "not authorised" page) — owner-approved. -->
            <p v-if="fallback && (fallback.discharged || fallback.unassigned)" class="mt-2 text-sm text-ink-500">
                No active match.
                <span v-if="fallback.discharged">
                    Found <strong class="nums text-ink-700">{{ fallback.discharged }}</strong> discharged
                    <!-- SPC-TM-011: the term POSTs to the registry in the body -->
                    <button type="button" @click="router.post('/registry?mode=admissions&discharged=1', { search: fallback.search })"
                        class="font-semibold text-brand-600 underline underline-offset-2 hover:text-brand-700">view →</button>
                </span>
                <span v-if="fallback.unassigned">
                    / <strong class="nums text-ink-700">{{ fallback.unassigned }}</strong> awaiting assignment
                    <Link href="/admissions" class="font-semibold text-brand-600 underline underline-offset-2 hover:text-brand-700">queue →</Link>
                </span>
            </p>
        </div>

        <!-- GROUPED layout: per-consultant roster summary + stacked consultant cards -->
        <template v-else-if="viewMode === 'grouped'">
        <!-- summary: patients per consultant (data-tour anchor for the onboarding tour, Item 10) -->
        <div data-tour="board" class="mb-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
          <div class="overflow-x-auto">
            <table class="min-w-[540px] w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-start text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-2.5">Consultant</th><th scope="col" class="px-3 py-2.5 text-center">Old</th><th scope="col" class="px-3 py-2.5 text-center">New</th><th scope="col" class="px-3 py-2.5 text-center">Active</th>
                        <th scope="col" class="px-3 py-2.5 text-center">Ward</th><th scope="col" class="px-3 py-2.5 text-center">ICU</th><th scope="col" class="px-3 py-2.5 text-center">TB</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <template v-for="sec in sections" :key="sec.key">
                        <tr v-if="sec.rows.length" class="bg-app/70"><td colspan="7" class="px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-ink-500">{{ sec.label }}</td></tr>
                        <tr v-for="g in sec.rows" :key="g.id" class="cursor-pointer transition hover:bg-brand-50/40" tabindex="0" role="button" :aria-expanded="open.has(g.id)" :aria-label="`Dr. ${g.name} — ${open.has(g.id) ? 'hide' : 'show'} patient list`" @click="toggle(g.id)" @keydown.enter="toggle(g.id)" @keydown.space.prevent="toggle(g.id)">
                            <td class="px-5 py-2 font-semibold text-ink-700">
                                <svg class="me-1.5 inline h-4 w-4 text-ink-300" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="open.has(g.id) ? 'm4.5 15.75 7.5-7.5 7.5 7.5' : 'm19.5 8.25-7.5 7.5-7.5-7.5'" /></svg> Dr. {{ g.name }}
                            </td>
                            <td class="nums px-3 py-2 text-center text-ink-600">{{ g.counts.old || '' }}</td>
                            <td class="nums px-3 py-2 text-center text-on-info">{{ g.counts.new || '' }}</td>
                            <td class="nums px-3 py-2 text-center font-semibold text-brand-700">{{ g.counts.active }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-600">{{ g.counts.ward }}</td>
                            <td class="nums px-3 py-2 text-center text-on-danger">{{ g.counts.icu || '' }}</td>
                            <td class="nums px-3 py-2 text-center text-ink-500">{{ g.counts.tb || '' }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
          </div>
        </div>

        <div v-if="groups.length" class="mb-3 flex gap-3 text-xs font-semibold text-brand-600">
            <button @click="allOpen" class="hover:underline">Expand all</button>
            <button @click="allClosed" class="hover:underline">Collapse all</button>
        </div>

        <!-- per-consultant patient cards (Item 7: visibleGroups honours "my group only"). The ref +
             tabindex + scroll-mt let toggle() bring a just-expanded consultant into view (past the
             sticky header) and move focus there — no more scroll-hunting from the roster. -->
        <div v-for="g in visibleGroups" :key="g.id" v-show="open.has(g.id)" :ref="(el) => setGroupEl(g.id, el)" tabindex="-1"
            class="scroll-mt-20 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line focus:outline-none" :class="compact ? 'mb-2.5' : 'mb-4'">
            <div class="flex items-center justify-between border-b border-line px-5 py-3">
                <!-- Wave 5 a11y fix: was <h3> with no <h2> anywhere on the page (only AppLayout's own
                     <h1> precedes it) — an h1->h3 skip. This is the first/only in-page heading level,
                     so it belongs at h2 (mirrors Dashboard.vue's per-card h2 siblings). -->
                <h2 class="font-bold text-ink-800">Dr. {{ g.name }} <span class="ms-1 text-sm font-normal text-ink-400">· {{ g.counts.total }} patient(s)</span></h2>
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
                <PatientCard v-for="p in g.patients" :key="p.id" :patient="p" :compact="compact" :readmit-window="readmitWindow"
                    :data-admission-id="p.id" :class="p.id === highlightedId ? 'outline outline-2 outline-offset-2 outline-brand-500' : ''"
                    @open-modal="openModal" @modify="openModify" @handover="openHandover" />
            </div>
        </div>
        </template>

        <!-- SPLIT layout: master–detail — a pinned consultant rail + the selected consultant's cards.
             Switching consultants is one click; the rail stays put while the detail pane scrolls. -->
        <template v-else>
            <div class="lg:grid lg:grid-cols-[15rem_1fr] lg:items-start lg:gap-4">
                <!-- rail: All-active + per-section consultants. Mobile = a short scrollable list above
                     the detail; on large screens it stays pinned while only the detail pane scrolls. -->
                <aside aria-label="Consultants" class="mb-3 lg:mb-0 lg:sticky lg:top-20">
                    <div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
                        <div class="max-h-56 overflow-y-auto p-1.5 lg:max-h-[80vh]">
                            <button type="button" @click="selectGroup('all')" :aria-current="railSelectedId === 'all' ? 'true' : undefined"
                                class="mb-0.5 flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-sm font-semibold transition"
                                :class="railSelectedId === 'all' ? 'bg-tint-accent text-on-accent' : 'text-ink-700 hover:bg-ink-50'">
                                <span>All active</span>
                                <span class="nums text-xs">{{ allPatients.length }}</span>
                            </button>
                            <template v-for="sec in sections" :key="sec.key">
                                <p v-if="sec.rows.length" class="px-3 pb-1 pt-3 text-[10px] font-bold uppercase tracking-wide text-ink-400">{{ sec.label }}</p>
                                <button v-for="g in sec.rows" :key="g.id" type="button" @click="selectGroup(g.id)"
                                    :aria-current="railSelectedId === g.id ? 'true' : undefined"
                                    class="mb-0.5 flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-start text-sm transition"
                                    :class="railSelectedId === g.id ? 'bg-tint-accent font-semibold text-on-accent' : 'text-ink-700 hover:bg-ink-50'">
                                    <span class="truncate">Dr. {{ g.name }}</span>
                                    <span class="flex shrink-0 items-center gap-1.5 text-xs">
                                        <span v-if="g.counts.new" class="font-semibold text-on-info">{{ g.counts.new }}n</span>
                                        <span v-if="g.counts.icu" class="font-semibold text-on-danger">{{ g.counts.icu }}i</span>
                                        <span class="nums font-semibold" :class="railSelectedId === g.id ? 'text-on-accent' : 'text-ink-500'">{{ g.counts.active }}</span>
                                    </span>
                                </button>
                            </template>
                        </div>
                    </div>
                </aside>

                <!-- detail: the selected consultant's cards, or the flat all-active census -->
                <section class="min-w-0">
                    <template v-if="railSelectedId === 'all'">
                        <h2 class="mb-3 font-bold text-ink-800">All active patients <span class="ms-1 text-sm font-normal text-ink-400">· {{ allPatients.length }}</span></h2>
                        <div v-if="allPatients.length" class="grid sm:grid-cols-2 xl:grid-cols-3" :class="compact ? 'gap-2' : 'gap-3'">
                            <PatientCard v-for="p in allPatients" :key="p.id" :patient="p" :compact="compact" :readmit-window="readmitWindow"
                                :data-admission-id="p.id" :class="p.id === highlightedId ? 'outline outline-2 outline-offset-2 outline-brand-500' : ''"
                                @open-modal="openModal" @modify="openModify" @handover="openHandover" />
                        </div>
                        <p v-else class="rounded-2xl bg-card px-5 py-8 text-center text-sm text-ink-400 ring-1 ring-line">No active patients.</p>
                    </template>
                    <div v-else-if="selectedGroup" class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
                        <div class="flex items-center justify-between border-b border-line px-5 py-3">
                            <h2 class="font-bold text-ink-800">Dr. {{ selectedGroup.name }} <span class="ms-1 text-sm font-normal text-ink-400">· {{ selectedGroup.counts.total }} patient(s)</span></h2>
                            <button v-if="canReassign && !isObserver && selectedGroup.patients.length" @click="openReassign(selectedGroup.id)"
                                class="rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50">Reassign</button>
                        </div>
                        <p v-if="!selectedGroup.patients.length" class="px-5 py-4 text-sm text-ink-400">No patients on this list yet.</p>
                        <div v-else class="grid sm:grid-cols-2 xl:grid-cols-3" :class="compact ? 'gap-2 p-2.5' : 'gap-3 p-4'">
                            <PatientCard v-for="p in selectedGroup.patients" :key="p.id" :patient="p" :compact="compact" :readmit-window="readmitWindow"
                                :data-admission-id="p.id" :class="p.id === highlightedId ? 'outline outline-2 outline-offset-2 outline-brand-500' : ''"
                                @open-modal="openModal" @modify="openModify" @handover="openHandover" />
                        </div>
                    </div>
                </section>
            </div>
        </template>

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
                    <div class="flex justify-end gap-2 pt-1"><button type="button" @click="closeModify" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="mForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Save changes</button></div>
                </form>
        </BaseModal>
    </AppLayout>
</template>
