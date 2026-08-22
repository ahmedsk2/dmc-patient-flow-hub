<script setup>
import { ref, watch, computed, useId } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import ErrorSummary from '@/Components/ErrorSummary.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { localToday, vFocus, guardSubmit, formatDate, xsrf } from '@/lib/ui.js';

const { ask } = useConfirm();

// modals now use BaseModal (focus-trap + Esc owned per-instance inside BaseModal).
// Wave 3: both modals pass `:dirty` (cForm.isDirty / eForm.isDirty — real Inertia tracking) so
// Esc/backdrop/X ask before discarding; each Cancel button routes through the SAME
// useUnsavedGuard instance. ErrorSummary sits at the top of each form, mapped from that form's
// .errors onto per-instance field ids (useId()-scoped) with aria-describedby wired to the
// existing field-level messages. Submit handlers are wrapped in guardSubmit() (Item 5).

const props = defineProps({ consultations: Object, filters: Object, stats: Object, reasons: Array, consultants: Array, specialties: Array, worklist: { type: Object, default: () => ({ date: '', seen: 0, total: 0, items: [] }) } });
const page = usePage();
const me = computed(() => page.props.auth.user);
// Observers (role 5) are read-only everywhere, and the read-only guarantee is never bought with a
// capability flag — prod data really does contain observers carrying can_manage. The server refuses
// them first in every predicate; this is the client saying the same thing rather than relying on
// canAdd, which only ever gated the "New Consultation" button.
const isObserver = computed(() => me.value.role === 5);
// mirrors User::canManageConsultation server-side — the SIGN-OFF gate, deliberately NARROWER than
// canModify below: coordinators book work, they do not assert it is finished, and the server
// refuses them with a 403. This predicate must never widen.
const canSignoff = (row) => !isObserver.value
    && (me.value.is_admin || me.value.can.manage || row.consultant_id === me.value.id);
const canAdd = computed(() => !isObserver.value);
const isConsultant = computed(() => me.value.is_admin || me.value.role === 3);

/**
 * The four states, in workflow order. Every one of them carries the OBLIGATION it means, and that
 * obligation is shown wherever a state is NAMED or CHANGED — filter button, row badge, move button.
 * In plain English "active" and "ongoing" read as near-synonyms, and "ongoing" can even read as the
 * MORE engaged of the two, while here they mean opposite things: `active` owes the patient a daily
 * follow-up, `ongoing` is on the books with NO daily commitment. A clinician who inverts them files
 * a patient who needs daily review under `ongoing`, where they silently drop off the team's daily
 * must-see list — the exact failure mode this project has already been burned by.
 *   `name` — the bare state name (accessible names, sentences)
 *   `tab`  — the visible label, sized for a dense header strip
 *   `duty` — the obligation, spelled out
 */
const STATUS_TABS = [
    { id: 'new', name: 'New', tab: 'New', duty: 'awaiting triage' },
    { id: 'active', name: 'Active', tab: 'Active · daily F/U', duty: 'daily follow-up owed' },
    { id: 'ongoing', name: 'Ongoing', tab: 'Ongoing · no daily F/U', duty: 'no daily follow-up' },
    { id: 'signed_off', name: 'Signed off', tab: 'Signed off', duty: null },
];
const STATUS_BADGE = {
    new: 'bg-tint-info text-on-info',
    active: 'bg-tint-accent text-on-accent',
    ongoing: 'bg-tint-warning text-on-warning',
    signed_off: 'bg-tint-success text-on-success',
};
// A status the client does not recognise is DRIFT (a legacy value, a newer server). It used to be
// painted exactly like `active`, so drift looked like a live daily-follow-up commitment; it is now
// neutral ink, which is what "we do not know what this means" should look like.
const STATUS_UNKNOWN = 'bg-ink-100 text-ink-500';
const statusOf = (s) => STATUS_TABS.find((t) => t.id === s) || null;
const statusLabel = (s) => statusOf(s)?.tab ?? 'Unknown';
// full-sentence form, for a badge title and (prefixed with "Move to") a move button's title
const STATE_TITLE = {
    new: 'New — waiting for the team to triage it',
    active: 'Active — a daily follow-up is owed on this patient',
    ongoing: 'Ongoing — on the books, no daily follow-up commitment',
    signed_off: 'Signed off — closed, with the response recorded',
};
const statusTitle = (s) => STATE_TITLE[s] || 'Unrecognised status — ask an administrator';
const moveTitle = (next) => `Move to ${STATE_TITLE[next] || statusLabel(next)}`;
// WCAG 2.5.3 (Label in Name): the accessible name must START with the VISIBLE label, or a
// speech-input user saying what they can see ("Active dot daily F slash U") matches nothing.
const tabAria = (t) => `${t.tab}${t.duty ? ` — ${t.duty}` : ''} — ${props.stats[t.id] ?? 0} consultations`;
// the moves POST /consultations/{id}/status accepts — the UI offers only legal ones, so the
// server's 422 stays a genuine API-misuse guard rather than routine user feedback
const STATUS_MOVES = { new: ['active', 'ongoing'], active: ['ongoing'], ongoing: ['active'], signed_off: [] };
// One move at a time (every form on this page has a double-submit guard; this is the same idea for
// a plain router.post), and a refusal is SHOWN. The status endpoint answers 403 for an unauthorized
// caller and a raw JSON 422 for an illegal transition — neither is an Inertia response, so without
// onHttpException Inertia would either throw up its crash dialog or say nothing at all while the row
// silently stayed put.
const moving = ref(null);
const moveError = ref('');
const firstError = (errors) => Object.values(errors || {}).flat().find((m) => typeof m === 'string' && m);
const moveTo = (row, next) => {
    if (moving.value) return;
    moving.value = row.id;
    moveError.value = '';
    router.post(`/consultations/${row.id}/status`, { status: next }, {
        preserveScroll: true,
        onError: (errors) => { moveError.value = firstError(errors) || 'That status change was refused. Nothing was saved.'; },
        onHttpException: (res) => {
            // Deliberately NOT "nothing was saved" here: a 500 can be raised AFTER the update lands
            // (e.g. inside Audit::log), so promising the move did not happen could be a lie about a
            // patient's state. Only the 422 path above knows the write was rejected outright.
            moveError.value = res?.status === 403
                ? 'You are not allowed to change this consultation.'
                : (res?.data?.message || 'That status change failed — reload to see the current state.');

            return false;   // handled inline — no crash dialog over a clinician's list
        },
        onFinish: () => { moving.value = null; },
    });
};
// ageing copy: "open 1 days" was wrong, and a legacy row can be four figures old ("open 1834 days")
const openLabel = (n) => (Number(n) === 0 ? 'open today' : `open ${Number(n).toLocaleString()} day${Number(n) === 1 ? '' : 's'}`);

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'new');
const scope = ref(props.filters.scope || '');
let timer = null;

// SPC-TM-011 (Wave 1): the free-text term is patient name/MRN, so it POSTs in the body to
// /consultations/search (POST /consultations is taken by "create"); status/scope stay in the
// query string. Term-less visits keep the plain GET flow. Page links route through goPage() so a
// term-filtered page change re-carries the term in the body.
const nonPii = () => ({ status: status.value, scope: scope.value || undefined });
const apply = () => {
    // preserveState keeps this component alive across a tab switch or search, so a refusal from a
    // PREVIOUS row would otherwise sit above a completely different list.
    moveError.value = '';
    const opts = { preserveState: true, replace: true, preserveScroll: true };
    if (search.value.trim()) {
        const q = new URLSearchParams(Object.entries(nonPii()).filter(([, v]) => v !== undefined)).toString();
        router.post(`/consultations/search${q ? `?${q}` : ''}`, { search: search.value }, opts);
    } else {
        router.get('/consultations', nonPii(), opts);
    }
};
const goPage = (url) => {
    if (!url) return;
    if (search.value.trim()) router.post(url, { search: search.value }, { preserveState: true, preserveScroll: true });
    else router.visit(url, { preserveScroll: true });
};
watch(search, () => { clearTimeout(timer); timer = setTimeout(apply, 300); });
const setStatus = (s) => { status.value = s; apply(); };
const toggleMine = () => { scope.value = scope.value === 'mine' ? '' : 'mine'; apply(); };

// ---- Wave 2b: Today's follow-up worklist ------------------------------------------------------
// A local mirror of the server prop so a tick flips instantly without a full Inertia round-trip.
// The tick is a JSON fetch (not an Inertia visit) because the server answers 422 on a repeat tick
// — one row per consult per day is a DB unique — and that refusal is rendered inline, right here.
const wl = ref({ ...props.worklist, items: [...(props.worklist?.items || [])] });
watch(() => props.worklist, (v) => { wl.value = { ...v, items: [...(v?.items || [])] }; });
const wlNotes = ref({});
const wlBusy = ref(null);
const wlError = ref('');
const wlSeen = computed(() => wl.value.items.filter((i) => i.seen_today).length);
const wlPct = computed(() => (wl.value.total ? Math.round((wlSeen.value / wl.value.total) * 100) : 0));
const markSeen = async (item) => {
    if (wlBusy.value || item.seen_today) return;
    wlBusy.value = item.id;
    wlError.value = '';
    try {
        const r = await fetch(`/consultations/${item.id}/followup`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ note: (wlNotes.value[item.id] || '').trim() || null }),
        });
        const body = await r.json().catch(() => ({}));
        if (!r.ok) { wlError.value = body.message || 'Could not record the follow-up.'; return; }
        item.seen_today = true;
        wlNotes.value[item.id] = '';
    } catch {
        wlError.value = 'Could not record the follow-up.';
    } finally {
        wlBusy.value = null;
    }
};

// new consultation
const today = localToday();
const showAdd = ref(false);
const cForm = useForm({
    mrn: '', patient_name: '', age: '', bed: '', current_location: 'Ward', consultation_date: today,
    consultation_from: '', to_service: '', consultant_id: '', indication: [], other_indication: '',
});
const cUid = useId();
const cfid = (fieldName) => `consult-add-${cUid}-${fieldName}`;
const cErrors = computed(() => Object.fromEntries(Object.entries(cForm.errors || {}).map(([k, v]) => [cfid(k), v])));
const { guardedClose: guardedCloseAdd } = useUnsavedGuard(() => cForm.isDirty, ask);
const openAdd = () => { showAdd.value = true; };
const doCloseAdd = () => { showAdd.value = false; };
const closeAdd = () => guardedCloseAdd(doCloseAdd);
const submitAdd = guardSubmit(cForm, () => cForm.post('/consultations', { preserveScroll: true, onSuccess: () => { doCloseAdd(); cForm.reset(); } }));

// edit + status moves. `can_modify` is the SERVER's own User::canModifyConsultation verdict, shipped
// per row (see ConsultationsController::index): own specialty / coordinator / admin. The client
// cannot compute it — the shared auth payload carries four capability flags and no specialty — and
// the old admin/can_manage/own-consultant guess was far narrower than the gate it claimed to mirror,
// so a registrar of the owning team could see her book's untriaged rows and move none of them, and
// coordinators got no controls at all. Observers stay read-only regardless.
const canModify = (c) => !isObserver.value && c.can_modify === true;
const editing = ref(null);
const eForm = useForm({ mrn: '', patient_name: '', age: '', bed: '', current_location: 'Ward', consultation_date: today, consultation_from: '', to_service: '', consultant_id: '', indication: [], other_indication: '' });
const eUid = useId();
const efid = (fieldName) => `consult-edit-${eUid}-${fieldName}`;
const eErrors = computed(() => Object.fromEntries(Object.entries(eForm.errors || {}).map(([k, v]) => [efid(k), v])));
const { guardedClose: guardedCloseEdit } = useUnsavedGuard(() => eForm.isDirty, ask);
const doCloseEdit = () => { editing.value = null; };
const closeEdit = () => guardedCloseEdit(doCloseEdit);
const openEdit = (c) => {
    editing.value = c;
    eForm.mrn = c.mrn || ''; eForm.patient_name = c.name || ''; eForm.age = c.age ?? ''; eForm.bed = c.bed || '';
    eForm.current_location = c.location || 'Ward'; eForm.consultation_date = c.date || today; eForm.consultation_from = c.from || '';
    eForm.to_service = c.to || ''; eForm.consultant_id = c.consultant_id || ''; eForm.indication = [...(c.indication_ids || [])]; eForm.other_indication = c.other || '';
    eForm.defaults?.();   // re-anchor Inertia's own isDirty baseline to the JUST-LOADED record
};
const submitEdit = guardSubmit(eForm, () => eForm.put(`/consultations/${editing.value.id}`, { preserveScroll: true, onSuccess: doCloseEdit }));

// when "to service" names an INTERNAL specialty, narrow the receiving-consultant list to its
// ON-SERVICE consultants (a previously chosen consultant stays selectable); if none match,
// fall back to the full list with a note. External / free-text services have NO receiving
// consultant (legacy stored none) — the select is disabled and cleared.
const isInternalService = (toService) => {
    const wanted = String(toService || '').trim().toLowerCase();
    return !!(wanted && props.specialties.find((s) => !s.is_external && s.name.trim().toLowerCase() === wanted));
};
const consultantPick = (toService, currentId) => {
    const wanted = String(toService || '').trim().toLowerCase();
    const spec = wanted && props.specialties.find((s) => !s.is_external && s.name.trim().toLowerCase() === wanted);
    if (!spec) return { list: props.consultants, fallback: false };
    const list = props.consultants.filter((c) => c.specialty_id === spec.id && c.on_service);
    if (!list.length) return { list: props.consultants, fallback: true };
    const current = currentId && props.consultants.find((c) => c.id === currentId);
    return { list: current && !list.some((c) => c.id === current.id) ? [...list, current] : list, fallback: false };
};
const cPick = computed(() => consultantPick(cForm.to_service, cForm.consultant_id));
const ePick = computed(() => consultantPick(eForm.to_service, eForm.consultant_id));
const cInternal = computed(() => isInternalService(cForm.to_service));
const eInternal = computed(() => isInternalService(eForm.to_service));
watch(cInternal, (v) => { if (!v) cForm.consultant_id = ''; });
watch(eInternal, (v) => { if (!v) eForm.consultant_id = ''; });
// W0: this used to promise a permanent, irreversible deletion. It is a SOFT delete — the row keeps
// its deleted_at and an admin restores it from Recently Deleted (/trashed) — so the copy now says
// what actually happens. The danger tone stays: it is still a guarded action.
const deleteConsult = async (c) => {
    const body = `Remove the consultation for ${c.name} (MRN ${c.mrn}), entered ${c.date || 'recently'}? `
        + 'It leaves the consultation ledger and stops counting anywhere, but it is kept — '
        + 'an administrator can restore it from Recently Deleted.';
    if (await ask('Remove consultation', body, 'danger')) router.delete(`/consultations/${c.id}`, { preserveScroll: true });
};

// sign off — W2A: no longer one click, because sign-off now records WHAT the team advised. It opens
// the same modal idiom as add/edit: BaseModal + useForm + the unsaved-changes guard + ErrorSummary +
// guardSubmit. Still no destructive confirm (Wave 2, Item 4): a sign-off is admin-reversible on the
// same day, and the modal itself is the deliberate step. deleteConsult keeps its danger confirm.
// The vocabulary mirrors ConsultationSignoffRequest::DISPOSITIONS — keep the two in step.
const DISPOSITIONS = [
    ['advice_given', 'Advice given'],
    ['taking_over', 'Taking over the patient'],
    ['follow_up_arranged', 'Follow-up arranged'],
    ['no_further_input', 'No further input needed'],
];
const dispositionLabel = (v) => (DISPOSITIONS.find((d) => d[0] === v) || [null, ''])[1];
const signingOff = ref(null);
const sForm = useForm({ response_disposition: '', response_followup_needed: false, response_note: '' });
const sUid = useId();
const sfid = (fieldName) => `consult-signoff-${sUid}-${fieldName}`;
const sErrors = computed(() => Object.fromEntries(Object.entries(sForm.errors || {}).map(([k, v]) => [sfid(k), v])));
const { guardedClose: guardedCloseSignoff } = useUnsavedGuard(() => sForm.isDirty, ask);
const openSignoff = (row) => {
    signingOff.value = row;
    sForm.response_disposition = ''; sForm.response_followup_needed = false; sForm.response_note = '';
    sForm.clearErrors?.();
    sForm.defaults?.();   // re-anchor Inertia's isDirty baseline to the empty response
};
const doCloseSignoff = () => { signingOff.value = null; };
const closeSignoff = () => guardedCloseSignoff(doCloseSignoff);
const submitSignoff = guardSubmit(sForm, () => sForm.post(`/consultations/${signingOff.value.id}/signoff`, {
    preserveScroll: true,
    onSuccess: () => { doCloseSignoff(); sForm.reset(); },
}));
const field = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20';
</script>

<template>
    <AppLayout title="Consultations">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <!-- These chips answer two DIFFERENT questions and used to be labelled as if they
                 answered one: `total` follows the search box and the My-consultations toggle,
                 while `open`/`mine_open` are deliberately whole-book figures. With a search active
                 the pair could read "Open 9 · Total 1", so the scope is now part of the label. -->
            <div class="flex gap-2">
                <span data-stat-chip title="Every open consultation in your book — not narrowed by the search box or the My-consultations filter." class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Open (all) <span class="nums ms-1 text-on-accent">{{ stats.open ?? 0 }}</span></span>
                <span data-stat-chip title="Consultations matching the current search and filters, across all four states — not just the tab you are looking at." class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Total (all states) <span class="nums ms-1 text-ink-600">{{ stats.total }}</span></span>
                <!-- personal counter for consultant viewers (K1-13): own open out of all open -->
                <span v-if="me.role === 3" data-stat-chip title="Your own open consultations out of every open one in your book — not narrowed by the search box or filters." class="rounded-xl bg-card px-3 py-2 text-sm font-semibold text-ink-700 shadow-sm ring-1 ring-line">Mine (all) <span class="nums ms-1 text-brand-700">{{ stats.mine_open ?? 0 }} of {{ stats.open ?? 0 }} open</span></span>
            </div>
            <div class="relative ms-auto">
                <svg class="pointer-events-none absolute start-3 top-2.5 h-5 w-5 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" /></svg>
                <input v-model="search" v-focus aria-label="Search consultations by name or MRN" placeholder="Search name or MRN…" autocomplete="off" class="w-64 rounded-xl border border-ink-200 bg-card py-2 ps-10 pe-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
            </div>
            <!-- These are FILTER toggles, not ARIA tabs: each one re-queries the server and replaces
                 the whole list, and there is no client-side panel for a tab to control. The previous
                 markup announced "tab, 1 of 4" while arrow keys did nothing, which is worse than
                 plain buttons; aria-pressed states the same thing truthfully and matches the house
                 pattern for server-backed filters (Patients board density / layout). Each button's
                 accessible name keeps the count OUT of the label and spells the obligation. -->
            <div role="group" aria-label="Filter consultations by status" class="flex gap-1 rounded-xl bg-card p-1 shadow-sm ring-1 ring-line">
                <button v-for="s in STATUS_TABS" :key="s.id" data-status-tab type="button" :aria-pressed="status === s.id"
                    :aria-label="tabAria(s)" :title="statusTitle(s.id)" @click="setStatus(s.id)"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition" :class="status === s.id ? 'bg-brand-solid text-white' : 'text-ink-500 hover:bg-ink-50'">
                    {{ s.tab }} <span class="nums ms-1">{{ stats[s.id] ?? 0 }}</span>
                </button>
            </div>
            <button v-if="isConsultant" @click="toggleMine" class="rounded-xl px-3 py-2 text-sm font-semibold shadow-sm ring-1 transition" :class="scope === 'mine' ? 'bg-accent-500 text-white ring-accent-500' : 'bg-card text-ink-500 ring-line hover:bg-ink-50'">My consultations</button>
            <button v-if="canAdd" @click="openAdd" class="inline-flex items-center gap-2 rounded-xl bg-brand-solid px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-solid-hover">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New Consultation
            </button>
        </div>

        <!-- Today's follow-up: the ACTIVE set only — the one status that asserts a daily round -->
        <section v-if="wl.total" data-test="worklist" class="mb-5 overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <h2 class="text-sm font-bold text-ink-800">Today's follow-up</h2>
                <span class="nums rounded-full bg-tint-accent px-2.5 py-0.5 text-xs font-semibold text-on-accent">Seen {{ wlSeen }} of {{ wl.total }} today</span>
                <div class="ms-auto h-2 w-40 overflow-hidden rounded-full bg-ink-100">
                    <div class="h-full rounded-full bg-brand-solid transition-all" :style="{ width: wlPct + '%' }" />
                </div>
            </header>
            <p v-if="wlError" role="alert" class="border-b border-line bg-tint-danger px-5 py-2 text-xs font-semibold text-on-danger">{{ wlError }}</p>
            <ul class="divide-y divide-line">
                <li v-for="item in wl.items" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <div class="min-w-48">
                        <div class="font-semibold text-ink-800">{{ item.name }}</div>
                        <div class="nums text-xs text-ink-400">MRN {{ item.mrn }} · Bed {{ item.bed || '—' }} · {{ item.location || '—' }} · {{ item.consultant }}</div>
                    </div>
                    <input v-if="!item.seen_today" v-model="wlNotes[item.id]" :aria-label="`Follow-up note for ${item.name}`"
                        placeholder="Optional one-line note…" maxlength="500"
                        class="ms-auto w-64 rounded-xl border border-ink-200 px-3 py-1.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
                    <span v-if="item.seen_today" class="ms-auto rounded-full bg-tint-success px-2.5 py-0.5 text-xs font-semibold text-on-success">Seen today</span>
                    <button v-else type="button" :disabled="wlBusy === item.id" @click="markSeen(item)"
                        class="rounded-xl bg-brand-solid px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-brand-solid-hover disabled:opacity-50">Mark seen</button>
                </li>
            </ul>
            <span class="sr-only" aria-live="polite" aria-atomic="true">Seen {{ wlSeen }} of {{ wl.total }} today</span>
        </section>

        <!-- a refused status move (403 / illegal transition) says so here instead of vanishing -->
        <div v-if="moveError" data-move-error role="alert" class="mb-3 rounded-xl bg-tint-danger px-4 py-2 text-sm font-semibold text-on-danger">{{ moveError }}</div>

        <div class="overflow-hidden rounded-2xl bg-card shadow-card ring-1 ring-line">
          <div class="overflow-x-auto">
            <table class="min-w-[640px] w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-start text-xs font-semibold uppercase tracking-wide text-ink-400">
                        <th scope="col" class="px-5 py-3">Patient</th><th scope="col" class="px-3 py-3">Location</th>
                        <th scope="col" class="px-3 py-3">From → To</th><th scope="col" class="px-3 py-3">Indication</th>
                        <th scope="col" class="px-3 py-3">Consultant</th><th scope="col" class="px-3 py-3">Date</th>
                        <th scope="col" class="px-3 py-3">Open</th><th scope="col" class="px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="c in consultations.data" :key="c.id" class="transition hover:bg-brand-50/40">
                        <td class="px-5 py-3">
                            <div class="font-semibold text-ink-800">{{ c.name }}</div>
                            <div class="nums text-xs text-ink-400">MRN {{ c.mrn }} · {{ c.age ?? '—' }}y · Bed {{ c.bed || '—' }}</div>
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ c.location || '—' }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ c.from || '—' }} <span class="text-ink-300">→</span> {{ c.to || '—' }}</td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span v-for="r in c.reasons" :key="r" class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700">{{ r }}</span>
                                <span v-if="c.other" class="rounded-full bg-ink-50 px-2 py-0.5 text-[11px] text-ink-500">{{ c.other }}</span>
                                <span v-if="!c.reasons.length && !c.other" class="text-ink-300">—</span>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-ink-600">{{ c.consultant }}</td>
                        <td class="nums px-3 py-3 text-ink-500">{{ formatDate(c.date) || '—' }}</td>
                        <!-- ageing: whole days since requested_at, or since consultation_date for the
                             historical rows that have no request time. Signed-off rows show nothing. -->
                        <td data-open-days class="nums px-3 py-3 text-ink-500">{{ c.open_days === null || c.open_days === undefined ? '—' : openLabel(c.open_days) }}</td>
                        <td class="px-5 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span data-status-badge class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="STATUS_BADGE[c.status] || STATUS_UNKNOWN"
                                    :title="statusTitle(c.status)">
                                    {{ statusLabel(c.status) }}<template v-if="c.status === 'signed_off' && c.signoff"> {{ formatDate(c.signoff) }}</template>
                                </span>
                                <!-- the closing entry, READABLE: the disposition used to be a hover
                                     title (invisible to keyboard and touch) and the follow-up flag
                                     was collected at sign-off and then never shown to anyone -->
                                <span v-if="c.status === 'signed_off' && c.disposition" data-response class="rounded-full bg-ink-100 px-2 py-0.5 text-[11px] font-semibold text-ink-600">{{ dispositionLabel(c.disposition) }}</span>
                                <span v-if="c.status === 'signed_off' && c.followup_needed" data-followup class="rounded-full bg-tint-warning px-2 py-0.5 text-[11px] font-semibold text-on-warning">Follow-up needed</span>
                                <button v-for="next in (canModify(c) ? (STATUS_MOVES[c.status] || []) : [])" :key="next" data-status-move type="button" @click="moveTo(c, next)"
                                    :disabled="!!moving" :title="moveTitle(next)" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-50 disabled:opacity-50">→ {{ statusLabel(next) }}</button>
                                <button v-if="c.status !== 'signed_off' && canSignoff(c)" @click="openSignoff(c)" title="Sign off" class="rounded-lg px-2 py-1 text-xs font-semibold text-on-success hover:bg-tint-success">Sign off</button>
                                <button v-if="canModify(c)" @click="openEdit(c)" title="Edit" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-50">Edit</button>
                                <button v-if="me.is_admin" @click="deleteConsult(c)" title="Delete" class="rounded-lg px-2 py-1 text-xs font-semibold text-on-danger hover:bg-tint-danger">Delete</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!consultations.data.length"><td colspan="8" class="px-5 py-10 text-center text-ink-400">No consultations match your filters.</td></tr>
                </tbody>
            </table>
          </div>
        </div>

        <!-- result-count announcement for screen readers (filters change the visible rows) -->
        <span class="sr-only" aria-live="polite" aria-atomic="true">
            {{ consultations.total ? `${consultations.total} consultation(s) found` : 'No results' }}
        </span>

        <div v-if="consultations.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-ink-500">
            <span class="nums">Showing {{ consultations.from }}–{{ consultations.to }} of {{ consultations.total }}</span>
            <div class="flex gap-1">
                <component :is="l.url ? 'button' : 'span'" v-for="l in consultations.links" :key="l.label" :type="l.url ? 'button' : undefined" @click="l.url && goPage(l.url)"
                    class="grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-semibold transition"
                    :class="l.active ? 'bg-brand-solid text-white' : (l.url ? 'bg-card text-ink-600 ring-1 ring-line hover:bg-ink-50' : 'text-ink-300')" v-html="l.label" />
            </div>
        </div>
        <!-- edit consultation modal -->
        <BaseModal :open="!!editing" title="Edit consultation" size="2xl" tall :dirty="!!eForm.isDirty" @close="closeEdit">
                <ErrorSummary :errors="eErrors" />
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label :for="efid('mrn')" class="mb-1 block text-sm font-semibold text-ink-700">MRN</label><input :id="efid('mrn')" v-model="eForm.mrn" :aria-describedby="eForm.errors.mrn ? efid('mrn') + '-err' : undefined" :class="[field, eForm.errors.mrn && 'border-danger-500']" /></div>
                        <div><label :for="efid('patient_name')" class="mb-1 block text-sm font-semibold text-ink-700">Patient name</label><input :id="efid('patient_name')" v-model="eForm.patient_name" :class="field" /></div>
                        <div class="grid grid-cols-2 gap-3"><div><label :for="efid('age')" class="mb-1 block text-sm font-semibold text-ink-700">Age <span class="text-danger-500">*</span></label><input :id="efid('age')" v-model="eForm.age" inputmode="numeric" :aria-describedby="eForm.errors.age ? efid('age') + '-err' : undefined" :class="[field, eForm.errors.age && 'border-danger-500']" /><p v-if="eForm.errors.age" :id="efid('age') + '-err'" class="mt-1 text-xs text-on-danger">{{ eForm.errors.age }}</p></div><div><label :for="efid('bed')" class="mb-1 block text-sm font-semibold text-ink-700">Bed <span class="text-danger-500">*</span></label><input :id="efid('bed')" v-model="eForm.bed" :aria-describedby="eForm.errors.bed ? efid('bed') + '-err' : undefined" :class="[field, eForm.errors.bed && 'border-danger-500']" /><p v-if="eForm.errors.bed" :id="efid('bed') + '-err'" class="mt-1 text-xs text-on-danger">{{ eForm.errors.bed }}</p></div></div>
                        <div><label :for="efid('current_location')" class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select :id="efid('current_location')" v-model="eForm.current_location" :class="field"><option>Ward</option><option>ICU</option><option>ER</option></select></div>
                        <div><label :for="efid('consultation_date')" class="mb-1 block text-sm font-semibold text-ink-700">Date</label><input :id="efid('consultation_date')" v-model="eForm.consultation_date" type="date" :max="today" :class="field" /></div>
                        <div><label :for="efid('consultation_from')" class="mb-1 block text-sm font-semibold text-ink-700">From service <span class="text-danger-500">*</span></label><input :id="efid('consultation_from')" v-model="eForm.consultation_from" list="svc-list" :aria-describedby="eForm.errors.consultation_from ? efid('consultation_from') + '-err' : undefined" :class="[field, eForm.errors.consultation_from && 'border-danger-500']" /><p v-if="eForm.errors.consultation_from" :id="efid('consultation_from') + '-err'" class="mt-1 text-xs text-on-danger">{{ eForm.errors.consultation_from }}</p></div>
                        <div><label :for="efid('to_service')" class="mb-1 block text-sm font-semibold text-ink-700">To service <span class="text-danger-500">*</span></label><input :id="efid('to_service')" v-model="eForm.to_service" list="svc-list" :aria-describedby="eForm.errors.to_service ? efid('to_service') + '-err' : undefined" :class="[field, eForm.errors.to_service && 'border-danger-500']" /><p v-if="eForm.errors.to_service" :id="efid('to_service') + '-err'" class="mt-1 text-xs text-on-danger">{{ eForm.errors.to_service }}</p></div>
                        <div class="sm:col-span-2"><label :for="efid('consultant_id')" class="mb-1 block text-sm font-semibold text-ink-700">Receiving consultant <span v-if="eInternal" class="text-danger-500">*</span></label><select :id="efid('consultant_id')" v-model="eForm.consultant_id" :disabled="!eInternal" :aria-describedby="eForm.errors.consultant_id ? efid('consultant_id') + '-err' : undefined" :class="[field, 'disabled:bg-ink-50', eForm.errors.consultant_id && 'border-danger-500']"><option value="">Select consultant…</option><option v-for="c in ePick.list" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                            <p v-if="!eInternal" class="mt-1 text-xs text-ink-400">External / free-text service — no internal consultant is recorded.</p>
                            <p v-else-if="ePick.fallback" class="mt-1 text-xs text-on-warning">No on-service consultants for this specialty — showing all.</p>
                            <p v-if="eForm.errors.consultant_id" :id="efid('consultant_id') + '-err'" class="mt-1 text-xs text-on-danger">{{ eForm.errors.consultant_id }}</p></div>
                    </div>
                    <div>
                        <label :id="efid('indication') + '-label'" class="mb-1 block text-sm font-semibold text-ink-700">Indication <span class="text-danger-500">*</span></label>
                        <div :id="efid('indication')" tabindex="-1" role="group" :aria-labelledby="efid('indication') + '-label'" class="flex flex-wrap gap-2">
                            <label v-for="r in reasons" :key="r.id" class="cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition" :class="eForm.indication.includes(r.id) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'"><input type="checkbox" :value="r.id" v-model="eForm.indication" class="hidden" /> {{ r.name }}</label>
                        </div>
                        <p v-if="eForm.errors.indication" :id="efid('indication') + '-err'" class="mt-1 text-xs text-on-danger">{{ eForm.errors.indication }}</p>
                        <input :id="efid('other_indication')" v-model="eForm.other_indication" :aria-describedby="eForm.errors.other_indication ? efid('other_indication') + '-err' : undefined" :class="[field, 'mt-2', eForm.errors.other_indication && 'border-danger-500']" placeholder="Other indication (required when 'Other' is selected)" />
                        <p v-if="eForm.errors.other_indication" :id="efid('other_indication') + '-err'" class="mt-1 text-xs text-on-danger">{{ eForm.errors.other_indication }}</p>
                    </div>
                    <div class="flex justify-end gap-2"><button type="button" @click="closeEdit" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button><button type="submit" :disabled="eForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Update consultation</button></div>
                </form>
        </BaseModal>

        <!-- new consultation modal -->
        <BaseModal :open="showAdd" title="New consultation" size="2xl" tall field-first :dirty="!!cForm.isDirty" @close="closeAdd">
                <ErrorSummary :errors="cErrors" />
                <form @submit.prevent="submitAdd" class="space-y-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label :for="cfid('mrn')" class="mb-1 block text-sm font-semibold text-ink-700">MRN <span class="text-danger-500">*</span></label><input :id="cfid('mrn')" v-model="cForm.mrn" :aria-describedby="cForm.errors.mrn ? cfid('mrn') + '-err' : undefined" :class="[field, cForm.errors.mrn && 'border-danger-500']" /><p v-if="cForm.errors.mrn" :id="cfid('mrn') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.mrn }}</p></div>
                        <div><label :for="cfid('patient_name')" class="mb-1 block text-sm font-semibold text-ink-700">Patient name <span class="text-danger-500">*</span></label><input :id="cfid('patient_name')" v-model="cForm.patient_name" :class="[field, cForm.errors.patient_name && 'border-danger-500']" /></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label :for="cfid('age')" class="mb-1 block text-sm font-semibold text-ink-700">Age <span class="text-danger-500">*</span></label><input :id="cfid('age')" v-model="cForm.age" inputmode="numeric" :aria-describedby="cForm.errors.age ? cfid('age') + '-err' : undefined" :class="[field, cForm.errors.age && 'border-danger-500']" /><p v-if="cForm.errors.age" :id="cfid('age') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.age }}</p></div>
                            <div><label :for="cfid('bed')" class="mb-1 block text-sm font-semibold text-ink-700">Bed <span class="text-danger-500">*</span></label><input :id="cfid('bed')" v-model="cForm.bed" :aria-describedby="cForm.errors.bed ? cfid('bed') + '-err' : undefined" :class="[field, cForm.errors.bed && 'border-danger-500']" /><p v-if="cForm.errors.bed" :id="cfid('bed') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.bed }}</p></div>
                        </div>
                        <div><label :for="cfid('current_location')" class="mb-1 block text-sm font-semibold text-ink-700">Location</label><select :id="cfid('current_location')" v-model="cForm.current_location" :class="field"><option>Ward</option><option>ICU</option><option>ER</option></select></div>
                        <div><label :for="cfid('consultation_date')" class="mb-1 block text-sm font-semibold text-ink-700">Date <span class="text-danger-500">*</span></label><input :id="cfid('consultation_date')" v-model="cForm.consultation_date" type="date" :max="today" :class="field" /></div>
                        <div><label :for="cfid('consultation_from')" class="mb-1 block text-sm font-semibold text-ink-700">From service <span class="text-danger-500">*</span></label><input :id="cfid('consultation_from')" v-model="cForm.consultation_from" list="svc-list" :aria-describedby="cForm.errors.consultation_from ? cfid('consultation_from') + '-err' : undefined" :class="[field, cForm.errors.consultation_from && 'border-danger-500']" placeholder="Referring service" /><p v-if="cForm.errors.consultation_from" :id="cfid('consultation_from') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.consultation_from }}</p></div>
                        <div><label :for="cfid('to_service')" class="mb-1 block text-sm font-semibold text-ink-700">To service <span class="text-danger-500">*</span></label><input :id="cfid('to_service')" v-model="cForm.to_service" list="svc-list" :aria-describedby="cForm.errors.to_service ? cfid('to_service') + '-err' : undefined" :class="[field, cForm.errors.to_service && 'border-danger-500']" placeholder="Consulted service" /><p v-if="cForm.errors.to_service" :id="cfid('to_service') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.to_service }}</p></div>
                        <datalist id="svc-list"><option v-for="s in specialties" :key="s.id" :value="s.name" /></datalist>
                        <div class="sm:col-span-2"><label :for="cfid('consultant_id')" class="mb-1 block text-sm font-semibold text-ink-700">Receiving consultant <span v-if="cInternal" class="text-danger-500">*</span></label><select :id="cfid('consultant_id')" v-model="cForm.consultant_id" :disabled="!cInternal" :aria-describedby="cForm.errors.consultant_id ? cfid('consultant_id') + '-err' : undefined" :class="[field, 'disabled:bg-ink-50', cForm.errors.consultant_id && 'border-danger-500']"><option value="">Select consultant…</option><option v-for="c in cPick.list" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                            <p v-if="!cInternal" class="mt-1 text-xs text-ink-400">External / free-text service — no internal consultant is recorded.</p>
                            <p v-else-if="cPick.fallback" class="mt-1 text-xs text-on-warning">No on-service consultants for this specialty — showing all.</p>
                            <p v-if="cForm.errors.consultant_id" :id="cfid('consultant_id') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.consultant_id }}</p></div>
                    </div>
                    <div>
                        <label :id="cfid('indication') + '-label'" class="mb-1 block text-sm font-semibold text-ink-700">Indication <span class="text-danger-500">*</span></label>
                        <div :id="cfid('indication')" tabindex="-1" role="group" :aria-labelledby="cfid('indication') + '-label'" class="flex flex-wrap gap-2">
                            <label v-for="r in reasons" :key="r.id" class="cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition"
                                :class="cForm.indication.includes(r.id) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-ink-200 text-ink-500'">
                                <input type="checkbox" :value="r.id" v-model="cForm.indication" class="hidden" /> {{ r.name }}
                            </label>
                        </div>
                        <p v-if="cForm.errors.indication" :id="cfid('indication') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.indication }}</p>
                        <input :id="cfid('other_indication')" v-model="cForm.other_indication" :aria-describedby="cForm.errors.other_indication ? cfid('other_indication') + '-err' : undefined" :class="[field, 'mt-2', cForm.errors.other_indication && 'border-danger-500']" placeholder="Other indication (required when 'Other' is selected)" />
                        <p v-if="cForm.errors.other_indication" :id="cfid('other_indication') + '-err'" class="mt-1 text-xs text-on-danger">{{ cForm.errors.other_indication }}</p>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeAdd" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="submit" :disabled="cForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Create consultation</button>
                    </div>
                </form>
        </BaseModal>

        <!-- sign-off modal: closing a consult records WHAT the team advised, not just that it closed -->
        <BaseModal :open="!!signingOff" title="Sign off consultation" size="lg" field-first :dirty="!!sForm.isDirty" @close="closeSignoff">
                <ErrorSummary :errors="sErrors" />
                <form @submit.prevent="submitSignoff" class="space-y-4">
                    <p v-if="signingOff" class="text-sm text-ink-500">
                        <span class="font-semibold text-ink-800">{{ signingOff.name }}</span>
                        <span class="nums"> · MRN {{ signingOff.mrn }} · Bed {{ signingOff.bed || '—' }}</span>
                    </p>
                    <div>
                        <label :for="sfid('response_disposition')" class="mb-1 block text-sm font-semibold text-ink-700">Response <span class="text-danger-500">*</span></label>
                        <select :id="sfid('response_disposition')" v-model="sForm.response_disposition" :aria-describedby="sForm.errors.response_disposition ? sfid('response_disposition') + '-err' : undefined" :class="[field, sForm.errors.response_disposition && 'border-danger-500']">
                            <option value="">Select a response…</option>
                            <option v-for="d in DISPOSITIONS" :key="d[0]" :value="d[0]">{{ d[1] }}</option>
                        </select>
                        <p v-if="sForm.errors.response_disposition" :id="sfid('response_disposition') + '-err'" class="mt-1 text-xs text-on-danger">{{ sForm.errors.response_disposition }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-ink-700">
                        <input :id="sfid('response_followup_needed')" v-model="sForm.response_followup_needed" type="checkbox" class="h-4 w-4 rounded border-ink-200" />
                        Follow-up still needed
                    </label>
                    <div>
                        <label :for="sfid('response_note')" class="mb-1 block text-sm font-semibold text-ink-700">Note</label>
                        <textarea :id="sfid('response_note')" v-model="sForm.response_note" rows="3" maxlength="2000" :aria-describedby="sForm.errors.response_note ? sfid('response_note') + '-err' : undefined" :class="[field, sForm.errors.response_note && 'border-danger-500']" placeholder="Working note — the clinical note stays in the HIS"></textarea>
                        <p v-if="sForm.errors.response_note" :id="sfid('response_note') + '-err'" class="mt-1 text-xs text-on-danger">{{ sForm.errors.response_note }}</p>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeSignoff" class="rounded-xl px-4 py-2 text-sm font-semibold text-ink-500">Cancel</button>
                        <button type="submit" :disabled="sForm.processing" class="rounded-xl bg-brand-solid px-5 py-2 text-sm font-semibold text-white hover:bg-brand-solid-hover disabled:opacity-50">Sign off</button>
                    </div>
                </form>
        </BaseModal>
    </AppLayout>
</template>
