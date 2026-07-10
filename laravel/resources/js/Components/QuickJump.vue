<script setup>
// Wave 1 (EHC UI): QuickJump grew from the "/" patient search into the command palette.
// Open with Ctrl+K / Cmd+K anywhere (plus the legacy "/" outside form fields). One combobox input
// keeps focus for the whole interaction; results are grouped Patients · Actions · Nav · Recents.
// Patients come from the role-scoped quick-search endpoint — the term travels in a POST body only
// (SPC-TM-011), never in a query string. Actions/Nav are a small static list of in-app
// destinations filtered by the same role/capability props the sidebar uses. Recents re-hydrate
// server-side from opaque admission ids held in module memory (see lib/recentPatients.js).
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import IdentityChip from '@/Components/IdentityChip.vue';
import { xsrf } from '@/lib/ui.js';
import { recentIds, pushRecent } from '@/lib/recentPatients';

const trigger = ref(null);   // the header button — focus falls back here on close (a11y)
const input = ref(null);
const open = ref(false);
const q = ref('');
const results = ref([]);     // Patients group (server search)
const recents = ref([]);     // Recents group (server re-hydration of the opaque id list)
const busy = ref(false);
const error = ref(false);
const active = ref(0);       // keyboard-highlighted option index across ALL groups
let timer = null;
let seq = 0;                 // stale-response guard for the debounced search
let openerEl = null;         // whichever trigger opened the palette — focus returns to it

const page = usePage();
const user = computed(() => page.props.auth?.user || {});

// ---- static destinations (role-filtered client-side; server authz is unchanged) ---------------
const navItems = computed(() => {
    const u = user.value;
    const can = u.can || {};
    const observer = u.role === 5;
    return [
        { label: 'Dashboard', href: '/' },
        ...(can.add && !observer ? [{ label: 'New Admissions', href: '/admissions' }] : []),
        { label: 'Patients', href: '/patients' },
        { label: 'Handovers', href: '/handovers' },
        ...(!observer ? [{ label: 'Consultations', href: '/consultations' }] : []),
        { label: 'Recent Activity', href: '/recent' },
        ...(u.is_admin ? [
            { label: 'Statistics', href: '/statistics' },
            { label: 'Reports', href: '/reports' },
            { label: 'Registry', href: '/registry' },
            { label: 'Audit Log', href: '/audit' },
            { label: 'Control Panel', href: '/control' },
        ] : []),
    ];
});
const actionItems = computed(() => {
    const u = user.value;
    const can = u.can || {};
    const observer = u.role === 5;
    return [
        ...(can.add && !observer ? [{ label: 'New admission', href: '/admissions/create' }] : []),
        { label: 'Review handovers', href: '/handovers' },
        ...(u.is_admin ? [{ label: 'Search the registry', href: '/registry' }] : []),
        { label: 'Open the printable active list', href: '/active-list' },
    ];
});

// ---- grouping ----------------------------------------------------------------------------------
const searching = computed(() => q.value.trim().length >= 2);
const groups = computed(() => {
    const out = [];
    if (searching.value && !busy.value && !error.value && results.value.length) {
        out.push({ label: 'Patients', items: results.value.map((r) => ({ kind: 'patient', row: r })) });
    }
    const q0 = q.value.trim().toLowerCase();
    const match = (i) => !q0 || i.label.toLowerCase().includes(q0);
    const acts = actionItems.value.filter(match).map((i) => ({ kind: 'action', ...i }));
    if (acts.length) out.push({ label: 'Actions', items: acts });
    const navs = navItems.value.filter(match).map((i) => ({ kind: 'nav', ...i }));
    if (navs.length) out.push({ label: 'Nav', items: navs });
    if (!searching.value && recents.value.length) {
        out.push({ label: 'Recents', items: recents.value.map((r) => ({ kind: 'patient', row: r })) });
    }
    return out;
});
// same groups with a running option index attached (drives option ids + aria-activedescendant)
const indexed = computed(() => {
    let i = 0;
    return groups.value.map((g) => ({ label: g.label, items: g.items.map((item) => ({ ...item, idx: i++ })) }));
});
const flat = computed(() => groups.value.flatMap((g) => g.items));
watch(flat, (v) => { if (active.value >= v.length) active.value = 0; });
const activeId = computed(() => (flat.value.length ? `qj-opt-${active.value}` : undefined));
const liveMsg = computed(() => {
    if (busy.value) return 'Searching…';
    if (error.value) return 'Search failed';
    const n = flat.value.length;
    return `${n} result${n === 1 ? '' : 's'}`;
});

// ---- server calls — POST body only, never a query string (SPC-TM-011) -------------------------
const fetchRows = async (body) => {
    const r = await fetch('/api/patients/quick-search', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    });
    if (!r.ok) throw new Error(String(r.status));
    return await r.json();
};

const runSearch = async () => {
    const term = q.value.trim();
    if (term.length < 2) return;
    const mySeq = ++seq;
    busy.value = true;
    error.value = false;
    try {
        const rows = await fetchRows({ q: term });
        if (mySeq !== seq) return;
        results.value = rows;
        active.value = 0;
    } catch {
        if (mySeq !== seq) return;
        results.value = [];
        error.value = true;
    } finally {
        if (mySeq === seq) busy.value = false;
    }
};

// Recents re-hydrate through the SAME endpoint/scope by opaque id — nothing patient-identifying is
// ever cached client-side, and rows the viewer may no longer see simply don't come back.
const loadRecents = async () => {
    const ids = recentIds();
    if (!ids.length) { recents.value = []; return; }
    try {
        const rows = await fetchRows({ ids });
        const byId = new Map(rows.map((r) => [r.id, r]));
        recents.value = ids.map((id) => byId.get(id)).filter(Boolean);
    } catch { recents.value = []; }
};

// ---- open / close ------------------------------------------------------------------------------
const focusInput = () => {
    const el = document.activeElement;
    openerEl = el && el !== document.body && typeof el.focus === 'function' ? el : null;
    open.value = true;
    loadRecents();
    nextTick(() => input.value?.focus());
};
const close = () => {
    open.value = false;
    q.value = ''; results.value = []; error.value = false; busy.value = false; active.value = 0;
    seq++;   // invalidate any in-flight search
    const back = openerEl || trigger.value;
    nextTick(() => back?.focus());
};

// Ctrl+K / Cmd+K toggles anywhere; "/" opens only outside fields (so typing "/" still works).
const onKey = (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        open.value ? close() : focusInput();
        return;
    }
    if (e.key !== '/' || open.value || e.ctrlKey || e.metaKey || e.altKey) return;
    const tag = document.activeElement?.tagName;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.activeElement?.isContentEditable) return;
    e.preventDefault();
    focusInput();
};
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => { window.removeEventListener('keydown', onKey); clearTimeout(timer); });

watch(q, (v) => {
    clearTimeout(timer);
    active.value = 0;
    error.value = false;
    if (v.trim().length < 2) { results.value = []; busy.value = false; seq++; return; }
    timer = setTimeout(runSearch, 250);
});

// ---- navigation --------------------------------------------------------------------------------
// Patient rows navigate by POSTing the MRN to the destination surface — the term never enters a
// URL. Discharged episodes (admin-only results) go to the registry; live ones to the board.
const go = (item) => {
    if (item.kind === 'patient') {
        pushRecent(item.row.id);
        close();
        const term = String(item.row.mrn ?? '');
        if (item.row.dest === 'registry') router.post('/registry?mode=admissions', { search: term });
        else router.post('/patients', { search: term });
        return;
    }
    close();
    router.visit(item.href);
};
const onArrow = (dir) => {
    const n = flat.value.length;
    if (!n) return;
    active.value = (active.value + dir + n) % n;
};
const onEnter = () => { if (flat.value[active.value]) go(flat.value[active.value]); };

const chipStatus = (row) => (row.deceased ? 'deceased' : (row.status || ''));
</script>

<template>
    <div class="relative" data-tour="quick-jump">
        <button ref="trigger" type="button" data-qj-trigger @click="focusInput"
            title="Search patients & commands (/ or Ctrl+K)" aria-label="Search patients and commands"
            class="hidden items-center gap-2 rounded-xl border border-line bg-card px-3 py-1.5 text-sm text-ink-400 shadow-sm transition hover:border-brand-400 hover:text-ink-600 sm:flex">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
            </svg>
            <span>Search patient…</span>
            <kbd class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] font-bold text-ink-500">Ctrl K</kbd>
        </button>
        <!-- compact trigger so touch users can reach the palette below the sm breakpoint -->
        <button type="button" data-qj-trigger-mobile @click="focusInput"
            aria-label="Search patients and commands (Ctrl+K)" title="Search patients & commands (Ctrl+K)"
            class="grid h-9 w-9 coarse:h-10 coarse:w-10 place-items-center rounded-full text-ink-400 transition hover:bg-ink-50 hover:text-ink-700 sm:hidden">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
            </svg>
        </button>

        <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-20" @click.self="close">
            <div class="w-full max-w-xl rounded-2xl bg-card shadow-2xl ring-1 ring-line"
                 role="dialog" aria-label="Command palette" aria-modal="true" @keydown.esc.prevent="close">
                <div class="flex items-center gap-3 border-b border-line px-4 py-3">
                    <svg class="h-5 w-5 shrink-0 text-ink-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z" />
                    </svg>
                    <input ref="input" v-model="q" placeholder="Search patients, actions, pages…"
                           class="flex-1 bg-transparent text-sm text-ink-800 outline-none placeholder:text-ink-400"
                           aria-label="Patient search query" autocomplete="off"
                           role="combobox" aria-expanded="true" aria-controls="qj-listbox"
                           :aria-activedescendant="activeId"
                           @keydown.down.prevent="onArrow(1)" @keydown.up.prevent="onArrow(-1)"
                           @keydown.enter.prevent="onEnter" />
                    <kbd class="rounded bg-ink-100 px-1.5 py-0.5 text-[10px] font-bold text-ink-500">Esc</kbd>
                </div>

                <!-- polite live announcement of the current result count -->
                <span role="status" aria-live="polite" class="sr-only">{{ liveMsg }}</span>

                <ul id="qj-listbox" role="listbox" aria-label="Palette results" class="max-h-96 overflow-auto py-1">
                    <!-- Patients group state (calm text; option rows render via the groups below) -->
                    <template v-if="searching && (busy || error || !results.length)">
                        <li role="presentation" class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-400">Patients</li>
                        <li v-if="busy" role="presentation" class="px-4 py-3 text-sm text-ink-400">Searching…</li>
                        <li v-else-if="error" role="presentation" class="flex items-center gap-3 px-4 py-3 text-sm text-ink-500">
                            <span>Couldn’t load results.</span>
                            <button type="button" @click="runSearch"
                                class="rounded-lg border border-ink-200 px-2.5 py-1 text-xs font-semibold text-ink-600 transition hover:bg-ink-50">Retry</button>
                        </li>
                        <li v-else role="presentation" class="px-4 py-3 text-sm text-ink-400">No matches</li>
                    </template>

                    <template v-for="g in indexed" :key="g.label">
                        <li role="presentation" class="px-3 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-400">{{ g.label }}</li>
                        <li v-for="item in g.items" :key="`${g.label}-${item.idx}`"
                            role="option" :id="`qj-opt-${item.idx}`" :aria-selected="item.idx === active"
                            @mousedown.prevent @click="go(item)" @mouseenter="active = item.idx"
                            class="flex w-full cursor-pointer items-center gap-3 px-3 py-2 text-start transition"
                            :class="item.idx === active ? 'bg-brand-50/60' : 'hover:bg-brand-50/40'">
                            <template v-if="item.kind === 'patient'">
                                <IdentityChip :name="item.row.name" :mrn="item.row.mrn" :age="item.row.age"
                                    :sex="item.row.gender || ''" :location="item.row.location || ''"
                                    :status="chipStatus(item.row)" />
                                <!-- dim, end-aligned last-admission date -->
                                <span class="ms-auto nums whitespace-nowrap text-xs text-ink-400">{{ item.row.admit_date }}</span>
                            </template>
                            <template v-else>
                                <svg class="h-4 w-4 shrink-0 text-ink-300" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                </svg>
                                <span class="text-sm font-semibold text-ink-700">{{ item.label }}</span>
                            </template>
                        </li>
                    </template>
                </ul>
                <p v-if="!searching && q.trim().length" class="border-t border-line px-4 py-2 text-center text-xs text-ink-300">Type at least 2 characters to search patients</p>
            </div>
        </div>
    </div>
</template>
