<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import EhcLogo from '@/Components/EhcLogo.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import NavLink from '@/Components/NavLink.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import QuickJump from '@/Components/QuickJump.vue';
import ScrollTopButton from '@/Components/ScrollTopButton.vue';
import { useTour } from '@/composables/useTour';
import { useSessionTimeout } from '@/composables/useSessionTimeout';
import { xsrf } from '@/lib/ui.js';
import { clearRecents } from '@/lib/recentPatients';

// Wave 5, Item 4: sessionStorage anchor for the ABSOLUTE session-timeout countdown (see the composable
// block below) — declared up top so both `logout()` and the mount hook can reach it.
const SESSION_START_KEY = 'dmc-session-started-at';

// Wave 1 (EHC UI): signing out also forgets the command palette's recent-patient ids (module
// memory) — shared clinical workstations must never carry one user's recents into the next
// session. Used by BOTH the header sign-out button (= the nav "Lock" affordance) and the
// idle/absolute auto-logout below. Also clears the absolute-session anchor so the NEXT login starts
// its own fresh absolute clock rather than inheriting this one's.
const logout = () => { clearRecents(); sessionStorage.removeItem(SESSION_START_KEY); router.post('/logout'); };

// Wave 2, Item 10: onboarding tour. maybeAutoStart() fires once on first authenticated load when
// auth.user.tour_completed_at is null and the route isn't excluded; the header "?" replays it
// (replay never sets the flag). data-tour anchors live on the nav sections / quick-jump / bell here
// and on the board + dashboard hero in their pages.
const { startTour, maybeAutoStart, cancelAutoStart } = useTour();
const replayTour = () => startTour(usePage().props.auth?.user || {}, { auto: false });

// ---- theme toggle (light / dark / system) -----------------------------------------------------
// Persisted to localStorage('dmc-theme'); the no-flash bootstrap in app.blade.php applies the
// initial .dark class before paint. Charts listen for the `dmc-theme-change` event to re-read
// their CSS-var-driven colors (useChartTheme.js).
// Default is LIGHT, not 'system': the unit's workstations sit under bright ward lighting and a
// dark-mode OS profile should not decide the clinical UI. 'system' stays available via the toggle.
const themePref = ref('light');
const mql = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
const isDark = computed(() =>
    themePref.value === 'dark' || (themePref.value === 'system' && (mql?.matches ?? false))
);
const applyTheme = () => {
    document.documentElement.classList.toggle('dark', isDark.value);
    document.dispatchEvent(new CustomEvent('dmc-theme-change'));
};
// Cycle light → dark → system → light. (system follows the OS preference.)
const cycleTheme = () => {
    themePref.value = { light: 'dark', dark: 'system', system: 'light' }[themePref.value] || 'light';
    localStorage.setItem('dmc-theme', themePref.value);
    applyTheme();
};
onMounted(() => {
    themePref.value = localStorage.getItem('dmc-theme') || 'light';
    applyTheme();
    // when on "system", track OS changes live
    mql?.addEventListener('change', () => { if (themePref.value === 'system') applyTheme(); });
    // keep the mobile-drawer viewport flag in sync (scopes aria-hidden to the mobile drawer)
    lgMql?.addEventListener('change', (e) => { mobileViewport.value = e.matches; });
    // Wave 2, Item 10: first-login onboarding tour (auto-start once when never seen + route allowed)
    maybeAutoStart(page.props.auth?.user, page.url);
    // Wave 2, Item 1: restore the desktop sidebar's icon-only-mode preference
    sidebarCollapsed.value = localStorage.getItem('dmc-sidebar-collapsed') === '1';
});

const props = defineProps({
    title: { type: String, default: '' },
    // Wave 1, Item 5 — optional breadcrumb trail rendered near the page <h1>. Each crumb:
    // { label, href? }; the final crumb (no href) is the current page. Pages that omit it see none.
    breadcrumbs: { type: Array, default: () => [] },
});

const page = usePage();

// ---- Wave 1, Item 6: single source of truth for the document <title> --------------------------
// AppLayout owns the <Head> so deep pages no longer duplicate it. `appName` is shared by
// HandleInertiaRequests (config('app.name')); fall back to a constant if the prop is absent.
const APP_NAME = computed(() => page.props.appName || 'DMC Internal Medicine');
const documentTitle = computed(() => (props.title ? `${props.title} — ${APP_NAME.value}` : APP_NAME.value));
const sidebarOpen = ref(false);

// ---- mobile drawer focus management (a11y) ----------------------------------------------------
// On open: focus the first nav link inside the drawer + trap Tab within it. On close: return
// focus to the hamburger. The always-visible lg: sidebar is unaffected (the trap only runs while
// the drawer is in its mobile overlay state).
const hamburger = ref(null);
const aside = ref(null);
// Track whether we're below the lg breakpoint (drawer overlay mode). Used to scope aria-hidden
// to the mobile drawer only — the always-visible desktop sidebar must NOT be hidden from AT.
const lgMql = typeof window !== 'undefined' ? window.matchMedia('(max-width: 1023px)') : null;
const mobileViewport = ref(lgMql?.matches ?? false);
// Wave 2 fix: drop display:none controls (the `hidden lg:flex` desktop icon-mode toggle added below)
// from the set so they never pollute the mobile drawer's focus-trap — getClientRects() is empty for a
// display:none element, so it can never be items[0] (broke focus-into-drawer) nor the backward-wrap edge.
const focusableIn = (el) => [...(el?.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])') ?? [])]
    .filter((n) => n.getClientRects().length);
const openDrawer = () => {
    sidebarOpen.value = true;
    nextTick(() => focusableIn(aside.value)[0]?.focus());
};
const closeDrawer = () => { sidebarOpen.value = false; nextTick(() => hamburger.value?.focus()); };

// ---- Wave 2, Item 1: desktop icon-only sidebar mode (persisted) --------------------------------
// Collapses the ALWAYS-VISIBLE `lg:` rail from w-64 to w-16 (icons only); the mobile drawer is a
// SEPARATE affordance (hamburger open/close) and is intentionally untouched — every class this
// state drives (in the template below + NavLink.vue) is `lg:`-scoped, so a persisted collapsed=true
// never shrinks the drawer. The main content's `lg:pl-*` offset tracks it via mainPadClass so the
// page body never sits under (expanded) or floats away from (collapsed) the rail.
const sidebarCollapsed = ref(false);
const toggleSidebarCollapse = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    localStorage.setItem('dmc-sidebar-collapsed', sidebarCollapsed.value ? '1' : '0');
};
const mainPadClass = computed(() => (sidebarCollapsed.value ? 'lg:pl-16' : 'lg:pl-64'));
const onDrawerKeydown = (e) => {
    if (e.key === 'Escape') { closeDrawer(); return; }
    if (e.key !== 'Tab') return;
    const items = focusableIn(aside.value);
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
};

// flash toast
const toast = ref(null);
let toastTimer = null;
watch(() => page.props.flash, (f) => {
    if (f && f.message) { toast.value = f; clearTimeout(toastTimer); toastTimer = setTimeout(() => (toast.value = null), 4500); }
}, { immediate: true, deep: true });

// ---- Wave 1, Items 3+4 / Wave 2, Item 2: clinical nav (role/capability-aware, IA-grouped) -------
// Each item carries an optional `can` boolean (default true). Items whose `can` is false are
// filtered out — purely COSMETIC: server-side authz is unchanged and remains the real gate
// (HandleInertiaRequests exposes is_admin + can.{add,assign,manage,modify}). Every filter below is
// byte-for-byte the same as Wave 1's flat list — Wave 2 only REGROUPS the same items into three
// labelled buckets (Overview / Clinical / Operations), matching the admin section header style.
//   • New Admissions is hidden without can_add (Item 4).
//   • Recent Activity moved here from Administration (Item 3): the VIEW is read-only and visible to
//     all clinical roles incl. Observer; the same-day UNDO actions stay admin-only server-side.
//   • Observers (role 5) are read-only: the admissions queue + consultations workspace are
//     clinical-role pages (403 server-side) — drop their entries (J2-12).
// A bucket that filters down to zero items renders nothing (no orphaned empty section heading).
const clinicalNavSections = computed(() => {
    const user = page.props.auth?.user;
    const observer = user?.role === 5;
    const can = user?.can || {};
    const sections = [
        { section: 'Overview', items: [
            { label: 'Dashboard', href: '/', icon: 'grid', can: true },
        ] },
        { section: 'Clinical', items: [
            // New Admissions is where you ADD a patient. Gate on is_admin OR the can_add capability —
            // an admin whose can_add flag is 0 can still add server-side (is_admin bypass), so hiding
            // the link on the raw flag alone stranded admins ("can't find where to add a patient").
            { label: 'New Admissions', href: '/admissions', icon: 'plus', can: (user?.is_admin || !!can.add) && !observer },
            { label: 'Patients', href: '/patients', icon: 'bed', can: true },
            // Printable whole-ward census (unscoped, all roles) — high-traffic, so it gets its own row
            // instead of living only behind the board's print icon.
            { label: 'Active List', href: '/active-list', icon: 'list', can: true },
            { label: 'Consultations', href: '/consultations', icon: 'chat', can: !observer },
        ] },
        { section: 'Operations', items: [
            { label: 'Handovers', href: '/handovers', icon: 'clipboard', can: true },
            { label: 'Recent Activity', href: '/recent', icon: 'clock', can: true },
        ] },
    ];
    return sections
        .map((s) => ({ ...s, items: s.items.filter((i) => i.can) }))
        .filter((s) => s.items.length > 0);
});

// ---- Wave 1, Items 1+2: Administration grouped into labelled sub-sections ----------------------
// Admin-only — Registry/Statistics/Reports + exports are restricted (PHI exposure control);
// non-admins' only analytics is the Dashboard. The whole block is gated by is_admin in the
// template, so per-item `can` filtering is unnecessary here. M&M Pack nests UNDER Reports
// (Item 2) via a `children` array rather than appearing as a standalone top-level row.
const adminNavSections = [
    {
        section: 'Analytics & Reports',
        items: [
            { label: 'Statistics', href: '/statistics', icon: 'chart' },
            {
                label: 'Reports', href: '/reports', icon: 'doc',
                children: [{ label: 'M&M Pack', href: '/reports/governance', icon: 'scale' }],
            },
            { label: 'Registry', href: '/registry', icon: 'search' },
        ],
    },
    {
        section: 'Governance & Safety',
        items: [
            { label: 'Audit Log', href: '/audit', icon: 'clipboard' },
            { label: 'Security', href: '/security', icon: 'lock' },
            { label: 'Recently Deleted', href: '/trashed', icon: 'trash' },
        ],
    },
    {
        section: 'Data Management',
        items: [
            { label: 'Data Quality', href: '/data-quality', icon: 'sparkles' },
            { label: 'Patient Merge', href: '/admin/patient-merge', icon: 'merge' },
            { label: 'Bulk Import', href: '/import', icon: 'upload' },
        ],
    },
    {
        section: 'Settings',
        items: [
            { label: 'Control Panel', href: '/control', icon: 'cog' },
        ],
    },
];

const url = computed(() => page.url);
// active = exact match for the root, prefix match otherwise (so /reports/governance highlights both
// Reports and the nested M&M child; the deeper /reports/governance prefix highlights only M&M).
const isActive = (href) => href === '/' ? url.value === '/' : url.value.startsWith(href);

// Heroicons-style stroked paths (24x24, stroke).
const icons = {
    grid: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25A2.25 2.25 0 0 1 8.25 10.5H6A2.25 2.25 0 0 1 3.75 8.25V6Zm9.75 0A2.25 2.25 0 0 1 15.75 3.75H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6Zm-9.75 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25Zm9.75 0a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    bed: 'M3 7.5h13.5a3 3 0 0 1 3 3V18M3 7.5V18m0-10.5V6m18 12H3m3.75-6.75h.008v.008H6.75v-.008Z',
    chat: 'M8.25 8.25h7.5m-7.5 3.75h4.5m-4.69 6.31a8.25 8.25 0 1 0-3.32-3.32L3 21l4.56-1.94Z',
    search: 'M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z',
    chart: 'M3 13.5V21h4.5v-7.5H3Zm6.75-6V21h4.5V7.5h-4.5ZM16.5 3v18H21V3h-4.5Z',
    doc: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625C5.004 1.875 4.5 2.379 4.5 3v18c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    cog: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.28c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.43l-1.003.827c-.293.241-.438.613-.43.992a7.03 7.03 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.542-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.93 6.93 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    // queue-list — the printable Active List census (rows of patients)
    list: 'M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z',
    upload: 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 7.5 12 3m0 0L7.5 7.5M12 3v13.5',
    clipboard: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z',
    shield: 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
    trash: 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
    lock: 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
    sparkles: 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z',
    merge: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5M16.5 3 21 7.5m0 0L16.5 12M21 7.5H7.5',
    // balance scales — distinct icon for the M&M / Governance pack (Item 2), so it reads as a
    // child of Reports without sharing the document icon.
    scale: 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z',
};

// resolve a nav item's icon key to its SVG path for <NavLink :icon-path>; tolerant of an unknown key.
const iconPath = (key) => icons[key] || icons.doc;

// ---- notification bell (handover transfers) ---------------------------------------------------
// Badge count comes from the shared `unreadNotifications` prop (refreshed by every Inertia visit —
// no polling timer); opening the dropdown just fetches the latest unread items — it no longer
// auto-marks them read (TD-T7). The feed is a to-do list now: it only ever shows unread ordinary
// items (see HandoverController::notifications), so an auto-clear-on-open would empty it the
// instant it was opened. The explicit "Clear" button (clearNotifications, below) is what marks
// them read; it never deletes a row (notifications are a retained clinical-audit trail).
const bellOpen = ref(false);
const bellLoading = ref(false);
const notifications = ref([]);
// HO-T7: still-open "handover.incomplete" reminders — pinned "Needs attention" group, persists
// across read-all/Clear (server only clears these via HandoverController::save's resolve step).
const actionable = ref([]);
// HO-T7 (spec §C3): the /api/notifications feed includes ALL types (incl. handover.incomplete), so an
// actionable item would otherwise show twice — once pinned above, once in the normal list. Drop the
// pinned ids from the feed (keyed by id) so the normal list holds only the non-actionable items.
const feedNotifications = computed(() => {
    const pinned = new Set(actionable.value.map((n) => n.id));
    return notifications.value.filter((n) => !pinned.has(n.id));
});
// null = trust the shared `unreadNotifications` prop; a number = a locally-known truth (the exact
// count the refetch just returned, which is resolved-aware — it excludes read ordinary items but
// still includes any surviving `handover.incomplete` alarms). Clear must NOT hardcode 0: readAll()
// deliberately never resolves those alarms, so a hardcoded zero would hide a genuinely outstanding
// reminder until the next Inertia navigation refreshed the shared prop.
const unreadOverride = ref(null);
const unread = computed(() => unreadOverride.value ?? (page.props.unreadNotifications || 0));
watch(() => page.props.unreadNotifications, () => (unreadOverride.value = null));   // a real server refresh always wins

const toggleBell = async () => {
    bellOpen.value = !bellOpen.value;
    if (!bellOpen.value) return;
    bellLoading.value = true;
    try {
        const d = await (await fetch('/api/notifications', { headers: { Accept: 'application/json' } })).json();
        notifications.value = d.notifications || [];
        actionable.value = d.actionable || [];
    } finally {
        bellLoading.value = false;
    }
};
const clearing = ref(false);
/** TD-T7: Clear = mark ordinary notifications read. Handover alarms are excluded server-side and
 *  stay pinned until the note is saved. Nothing is deleted (the reminder trail is retained for
 *  audit — see docs/HANDOVER-COMPLIANCE.md). */
const clearNotifications = async () => {
    clearing.value = true;
    try {
        await fetch('/notifications/read-all', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
        const d = await (await fetch('/api/notifications', { headers: { Accept: 'application/json' } })).json();
        notifications.value = d.notifications || [];
        actionable.value = d.actionable || [];
        unreadOverride.value = d.unread ?? 0;   // still-open actionable reminders keep the badge lit
    } finally { clearing.value = false; }
};
const goInbox = () => { bellOpen.value = false; router.visit('/handovers'); };
// focus the first actionable element in the bell dropdown when it mounts (a11y). Used as a
// function ref on the panel — Vue calls it with the element on mount, null on unmount.
const focusFirstBell = (el) => { if (el) nextTick(() => el.querySelector('button,a')?.focus()); };
const notifText = (n) => {
    const p = n.payload || {};
    // Phase 4 — Item 3: failed-login burst alert
    if (n.type === 'security.failed_logins') {
        return `Security: ${p.count || ''} failed login attempt(s) for "${p.username || 'unknown'}"${p.ip ? ` from ${p.ip}` : ''}`;
    }
    // Phase 4 — Item 6: daily data-quality digest
    if (n.type === 'dq.daily_report') {
        const total = (p.over_los || 0) + (p.no_dx || 0) + (p.bad_dates || 0) + (p.orphan_dx || 0) + (p.double_open || 0);
        return `Data quality: ${total} item(s) need review (see the Data Quality page)`;
    }
    // HO-T7: persistent "incomplete handover" reminder — the reassign moved the patient before the
    // handover note was refreshed today; stays lit until someone saves a note (see resolve step).
    if (n.type === 'handover.incomplete') {
        return `${p.patient_name || 'A patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''} was reassigned from Dr. ${p.from_name || '—'} without a completed handover — complete it.`;
    }
    if (p.count) return `Dr. ${p.from_name || 'A consultant'} handed over ${p.count} patient(s) to you`;
    return `Dr. ${p.from_name || 'A consultant'} handed over ${p.patient_name || 'a patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''}`;
};
const relTime = (iso) => {
    if (!iso) return '';
    const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    if (mins < 60 * 24) return `${Math.round(mins / 60)}h ago`;
    return `${Math.round(mins / (60 * 24))}d ago`;
};

// ---- Wave 5, Item 4 (Security P1): idle + absolute session-timeout warning --------------------
// App\Http\Middleware\SessionTimeout is the AUTHORITATIVE enforcer for BOTH limits — this client
// overlay is a UX convenience that warns before whichever limit fires SOONER (useSessionTimeout does
// that "which one binds" arithmetic; see resources/js/__tests__/useSessionTimeout.test.js) and
// auto-logs-out at the limit, avoiding an abrupt 419-on-next-request. Activity events reset the IDLE
// clock only — the ABSOLUTE clock is deliberately never reset by activity (that's the point of an
// absolute cap), matching the server's own `session_started_at` (stamped once, at login).
//
// The server does not currently share `session_started_at` to Inertia props (only the two *_Minutes
// LIFETIMES are shared — see HandleInertiaRequests). Rather than add a new shared prop for this UX-only
// convenience, the absolute anchor is approximated client-side: seeded once into sessionStorage the
// first time it's observed missing (i.e. shortly after login) and read back on every subsequent mount
// — AppLayout is not a persistent Inertia layout, so it fully remounts on every navigation, and a
// plain component-local timestamp would incorrectly restart the absolute clock on every page view.
// sessionStorage survives that remount (same tab) and is cleared on sign-out (see `logout()` above)
// so the next login starts its own anchor. This is an approximation of the true login time, not the
// authoritative value — acceptable because the SERVER enforces the real limit regardless of what this
// overlay displays.
const idleMinutes = computed(() => Number(page.props.idleTimeoutMinutes || 0));
const absMinutes = computed(() => Number(page.props.absTimeoutMinutes || 0));
const sessionTimeout = useSessionTimeout({ idleMinutes: idleMinutes.value, absMinutes: absMinutes.value, warnBeforeSeconds: 60 });

const showIdleWarning = ref(false);
const idleSecondsLeft = ref(60);
const idleReason = ref('idle');   // 'idle' | 'absolute' — which limit is currently driving the countdown
let idleTimer = null;

const resetIdle = () => {
    sessionTimeout.markActivity();
    // only an IDLE warning is dismissed by activity — an absolute warning stays up (activity can't
    // extend the absolute cap, so hiding it would be misleading)
    if (showIdleWarning.value && idleReason.value === 'idle') showIdleWarning.value = false;
};
const staySignedIn = () => {
    resetIdle();
    // A cheap authenticated request — reloads the CURRENT page's Inertia props with `only: []`, i.e.
    // fetches nothing, but still round-trips through the `auth` middleware group, so
    // App\Http\Middleware\SessionTimeout re-stamps `last_activity_at` server-side. Deliberately NOT a
    // new endpoint. This cannot and does not extend an absolute-timeout warning (see reason above).
    router.reload({ only: [] });
};
// Nav "Lock" affordance + the timeout panel's own escape hatch — both just sign out now.
const lockNow = () => { showIdleWarning.value = false; logout(); };
const idleTick = () => {
    const state = sessionTimeout.tick();
    if (state.secondsRemaining === null) { showIdleWarning.value = false; return; }
    if (state.expired) {
        showIdleWarning.value = false;
        logout();   // clears palette recents too — same path as the manual sign-out
        return;
    }
    idleReason.value = state.reason;
    idleSecondsLeft.value = state.secondsRemaining;
    showIdleWarning.value = state.warn;
};
const idleEvents = ['mousemove', 'keydown', 'click', 'scroll'];
onMounted(() => {
    if (!idleMinutes.value && !absMinutes.value) return;
    // restore (or seed) the absolute-session anchor described above
    const stored = Number(sessionStorage.getItem(SESSION_START_KEY)) || null;
    const start = stored || Date.now();
    if (!stored) sessionStorage.setItem(SESSION_START_KEY, String(start));
    sessionTimeout.setSessionStart(start);

    idleEvents.forEach((e) => window.addEventListener(e, resetIdle, { passive: true }));
    idleTimer = setInterval(idleTick, 1000);
});
onUnmounted(() => {
    idleEvents.forEach((e) => window.removeEventListener(e, resetIdle));
    if (idleTimer) clearInterval(idleTimer);
    // cancel a first-login tour auto-start still inside its 400ms paint delay — unmounting within
    // that window otherwise leaks a callback that drives driver.js against a gone document
    cancelAutoStart();
});
</script>

<template>
    <!-- Wave 1, Item 6: the layout owns the document <title> ({Page} — DMC Internal Medicine) -->
    <Head :title="documentTitle" />
    <div class="min-h-full">
        <!-- Skip to content (keyboard users) — visually hidden until focused -->
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-[100] focus:rounded-xl focus:bg-brand-solid focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
            Skip to content
        </a>
        <!-- Sidebar -->
        <aside
            id="app-sidebar"
            ref="aside"
            class="fixed inset-y-0 left-0 z-40 flex flex-col w-64 -translate-x-full bg-gradient-to-b from-navy-900 to-navy-950 text-navy-100 transition-[width,transform] lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen, 'lg:w-16': sidebarCollapsed }"
            :aria-hidden="sidebarOpen ? undefined : (mobileViewport ? 'true' : undefined)"
            @keydown="sidebarOpen && onDrawerKeydown($event)"
        >
            <div class="flex h-16 items-center gap-3 px-5 border-b border-white/5" :class="{ 'lg:justify-center lg:px-2': sidebarCollapsed }">
                <div class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-card p-1 shadow-lg shadow-brand-950/40"><EhcLogo class="h-7 w-7" /></div>
                <div class="leading-tight" :class="{ 'lg:hidden': sidebarCollapsed }">
                    <div class="font-display text-sm font-bold text-white tracking-wide">DMC <span class="text-brand-300">IM</span></div>
                    <div class="text-[10px] uppercase tracking-[0.18em] text-navy-400">Patient Flow</div>
                </div>
            </div>

            <!-- Wave 2, Item 1: desktop-only icon-mode toggle. The mobile drawer never shows this —
                 it already has the hamburger/backdrop for open/close and always renders full labels. -->
            <div class="hidden border-b border-white/5 px-3 py-2 lg:flex" :class="sidebarCollapsed ? 'justify-center' : 'justify-end'">
                <button type="button" @click="toggleSidebarCollapse"
                    :aria-expanded="!sidebarCollapsed" aria-controls="app-sidebar"
                    :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    class="grid h-8 w-8 coarse:h-10 coarse:w-10 place-items-center rounded-lg text-navy-300 transition hover:bg-white/10 hover:text-white">
                    <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': sidebarCollapsed }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 min-h-0 overflow-y-auto px-3 py-5 space-y-1">
                <!-- Wave 2, Item 2: Overview / Clinical / Operations, each a labelled bucket (same
                     header style as the admin sections below). data-tour anchor kept on the WRAPPING
                     block (Item 10's tour highlights the whole clinical nav area, not one label). -->
                <div data-tour="nav-clinical" class="space-y-1">
                    <template v-for="section in clinicalNavSections" :key="section.section">
                        <p class="px-3 pb-2 pt-4 text-[10px] font-semibold uppercase tracking-[0.16em] text-navy-400 first:pt-0" :class="{ 'lg:sr-only': sidebarCollapsed }">{{ section.section }}</p>
                        <NavLink v-for="item in section.items" :key="item.href"
                            :href="item.href" :icon-path="iconPath(item.icon)" :label="item.label" :active="isActive(item.href)" :collapsed="sidebarCollapsed" />
                    </template>
                </div>

                <template v-if="page.props.auth?.user?.is_admin">
                    <template v-for="(section, si) in adminNavSections" :key="section.section">
                        <p :data-tour="si === 0 ? 'nav-admin' : undefined" class="px-3 pt-5 pb-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-navy-400" :class="{ 'lg:sr-only': sidebarCollapsed }">{{ section.section }}</p>
                        <template v-for="item in section.items" :key="item.href">
                            <NavLink :href="item.href" :icon-path="iconPath(item.icon)" :label="item.label" :active="isActive(item.href)" :collapsed="sidebarCollapsed" />
                            <NavLink v-for="child in (item.children || [])" :key="child.href"
                                :href="child.href" :icon-path="iconPath(child.icon)" :label="child.label" :active="isActive(child.href)" :indent="true" :collapsed="sidebarCollapsed" />
                        </template>
                    </template>
                </template>
            </nav>

            <!-- pinned in normal flow at the column's foot (nav above flexes + scrolls); previously
                 absolute bottom-0, which overlapped the last nav items when the nav ran tall. -->
            <div class="shrink-0 p-4" :class="{ 'lg:hidden': sidebarCollapsed }">
                <div class="rounded-2xl bg-white/5 p-4 text-center">
                    <p class="text-[11px] text-navy-300">Eastern Health Cluster</p>
                    <p lang="ar" dir="rtl" class="text-[11px] font-semibold text-brand-300">تجمع الشرقية الصحي</p>
                </div>
            </div>
        </aside>

        <!-- Backdrop (mobile) -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-navy-950/50 lg:hidden" @click="closeDrawer"></div>

        <!-- Main -->
        <div :class="mainPadClass">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-line bg-card/80 px-5 backdrop-blur">
                <button ref="hamburger" class="grid h-9 w-9 coarse:h-10 coarse:w-10 place-items-center rounded-full text-ink-500 transition hover:bg-ink-50 lg:hidden" @click="openDrawer" aria-label="Open navigation menu" :aria-expanded="sidebarOpen" aria-controls="app-sidebar">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" /></svg>
                </button>
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-bold text-ink-900">{{ title }}</h1>
                    <!-- Wave 1, Item 5: optional breadcrumb trail (renders only with 2+ crumbs) -->
                    <Breadcrumbs :crumbs="breadcrumbs" />
                </div>
                <div class="ms-auto flex items-center gap-3">
                    <!-- Wave 2, Item 2: global patient quick-jump (press /) -->
                    <QuickJump />
                    <div class="hidden items-center gap-2 rounded-full bg-tint-success px-3 py-1 text-xs font-semibold text-on-success sm:flex">
                        <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success-500 opacity-60"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-success-500"></span></span>
                        Live
                    </div>
                    <!-- Wave 2, Item 10: replay the onboarding tour (never sets the "seen" flag) -->
                    <button @click="replayTour" aria-label="Replay the guided tour" title="Guided tour"
                        class="grid h-9 w-9 coarse:h-10 coarse:w-10 place-items-center rounded-full text-ink-400 transition hover:bg-ink-50 hover:text-ink-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" /></svg>
                    </button>
                    <!-- theme toggle (light / dark / system) -->
                    <button @click="cycleTheme"
                        :aria-label="`Theme: ${themePref}. Switch theme.`"
                        :title="`Theme: ${themePref} (click to change)`"
                        class="relative grid h-9 w-9 coarse:h-10 coarse:w-10 place-items-center rounded-full text-ink-400 transition hover:bg-ink-50 hover:text-ink-700">
                        <!-- sun (light/system-light) -->
                        <svg v-if="!isDark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                        <!-- moon (dark) -->
                        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
                        <!-- tiny "system" indicator dot when following OS -->
                        <span v-if="themePref === 'system'" class="absolute -bottom-0.5 end-1.5 h-1.5 w-1.5 rounded-full bg-brand-400"></span>
                    </button>
                    <!-- notification bell -->
                    <div class="relative" data-tour="bell">
                        <button @click="toggleBell" aria-label="Notifications" title="Notifications" :aria-expanded="bellOpen" aria-controls="notifications-panel" aria-haspopup="dialog" class="relative grid h-9 w-9 coarse:h-10 coarse:w-10 place-items-center rounded-full text-ink-400 transition hover:bg-ink-50 hover:text-ink-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                            <span v-if="unread > 0" class="nums absolute -end-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-danger-600 px-1 text-[10px] font-bold leading-none text-white">{{ unread > 9 ? '9+' : unread }}</span>
                        </button>
                        <div v-if="bellOpen" class="fixed inset-0 z-40" @click="bellOpen = false"></div>
                        <div v-if="bellOpen" id="notifications-panel" role="dialog" aria-label="Notifications" :ref="focusFirstBell" @keydown.esc="bellOpen = false" class="absolute end-0 top-11 z-50 flex max-h-[70vh] w-80 flex-col overflow-hidden rounded-2xl bg-card shadow-2xl ring-1 ring-line">
                            <div class="shrink-0 flex items-center justify-between border-b border-line px-4 py-2.5">
                                <span class="text-sm font-bold text-ink-800">Notifications</span>
                                <div class="flex items-center gap-3">
                                    <!-- TD-T7: explicit, non-destructive Clear — marks ordinary items read;
                                         unresolved handover alarms stay pinned (see clearNotifications()). -->
                                    <button v-if="feedNotifications.length" type="button" @click="clearNotifications" :disabled="clearing"
                                            class="text-xs font-semibold text-ink-500 transition hover:text-ink-700 disabled:opacity-50">{{ clearing ? 'Clearing…' : 'Clear' }}</button>
                                    <button @click="goInbox" class="text-xs font-semibold text-brand-600 hover:underline">Handover inbox →</button>
                                </div>
                            </div>
                            <div v-if="bellLoading" class="px-4 py-6 text-center text-sm text-ink-400">Loading…</div>
                            <!-- TD-T6: both the pinned group and the feed live inside ONE scroll container so a
                                 long actionable list can't push the panel (and page) past the viewport — the
                                 panel itself is capped at 70vh above. `min-h-0` lets this flex child actually
                                 shrink below its content (a flex child's default min-height is its content
                                 size, which would defeat the max-height cap); `overscroll-contain` stops
                                 scroll chaining to the page once this list hits its end — the reported bug. -->
                            <div v-else data-notif-scroll class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                            <!-- HO-T7: pinned "Needs attention" group — persistent handover.incomplete
                                 reminders that stay lit until resolved server-side (a note is saved), not
                                 dismissed by read-all. Links by admission id (?highlight=), NOT by MRN —
                                 the board's GET handler strips a `search` query param (SPC-TM-011,
                                 MRN-out-of-URL) and PHI must never ride in a URL regardless. -->
                            <div v-if="actionable.length" class="border-b border-line">
                                <p class="px-4 pt-2.5 pb-1 text-[11px] font-bold uppercase tracking-wide text-on-warning">Needs attention</p>
                                <Link v-for="n in actionable" :key="n.id" :href="`/patients?highlight=${n.payload?.admission_id || ''}`" @click="bellOpen = false"
                                      class="block px-4 py-2.5 text-start transition hover:bg-tint-warning/40">
                                    <p class="text-sm leading-snug text-ink-700">{{ notifText(n) }}</p>
                                    <p class="nums mt-0.5 text-xs text-ink-400">{{ relTime(n.created_at) }}</p>
                                </Link>
                            </div>
                            <!-- non-actionable feed (actionable ids are pinned above, so they're filtered out here) -->
                            <ul v-if="feedNotifications.length" class="divide-y divide-line">
                                <li v-for="n in feedNotifications" :key="n.id">
                                    <button @click="goInbox" class="w-full px-4 py-3 text-start transition hover:bg-brand-50/40" :class="{ 'bg-brand-50/30': !n.read_at }">
                                        <p class="text-sm leading-snug text-ink-700">{{ notifText(n) }}</p>
                                        <p class="nums mt-0.5 text-xs text-ink-400">{{ relTime(n.created_at) }}</p>
                                    </button>
                                </li>
                            </ul>
                            <!-- empty state ONLY when neither group has anything (a lone pinned group must not trigger it) -->
                            <div v-if="!actionable.length && !feedNotifications.length" class="px-4 py-6 text-center text-sm text-ink-400">No notifications yet.</div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 border-s border-line ps-3">
                        <Link href="/profile" class="flex items-center gap-3 rounded-xl px-1 py-1 transition hover:bg-ink-50">
                            <!-- Wave 5 residual (measured, not assumed): bg-brand-600/text-white measures 4.31:1
                                 in LIGHT (fails the 4.5 AA-normal-text bar for this 14px label) and 2.09:1 in
                                 DARK (brand-600 is deliberately LIGHTENED there for text-on-dark-card legibility
                                 — see the ".dark { --color-brand-600 }" comment in app.css — which is wrong for a
                                 fill holding white text). navy-700 is the theme-INVARIANT deep-teal chrome colour
                                 (no .dark override) — white on it is 8.42:1 in both themes. See scripts/contrast.mjs. -->
                            <div class="grid h-9 w-9 place-items-center rounded-full bg-navy-700 text-sm font-semibold text-white">
                                {{ (page.props.auth?.user?.name || 'DMC').slice(0, 2).toUpperCase() }}
                            </div>
                            <div class="hidden leading-tight sm:block">
                                <div class="text-sm font-semibold text-ink-800">{{ page.props.auth?.user?.name || 'DMC Staff' }}</div>
                                <div class="text-xs text-ink-400">{{ page.props.auth?.user?.role_label || 'Internal Medicine' }}</div>
                            </div>
                        </Link>
                        <button @click="logout" title="Sign out (lock this workstation)" aria-label="Sign out (lock this workstation)" class="ms-1 grid h-9 w-9 coarse:h-10 coarse:w-10 place-items-center rounded-full text-ink-400 transition hover:bg-danger-100 hover:text-danger-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
                        </button>
                    </div>
                </div>
            </header>

            <main id="main-content" tabindex="-1" class="p-5 lg:p-7">
                <slot />
            </main>
        </div>

        <!-- Flash toast — announced to assistive tech (errors assertive, others polite) -->
        <Transition enter-active-class="transition duration-300" enter-from-class="translate-y-3 opacity-0" leave-active-class="transition duration-300" leave-to-class="translate-y-3 opacity-0">
            <div v-if="toast"
                :role="toast.type === 'error' ? 'alert' : 'status'"
                :aria-live="toast.type === 'error' ? 'assertive' : 'polite'"
                aria-atomic="true"
                class="fixed bottom-6 end-6 z-50 flex items-center gap-3 rounded-2xl px-5 py-3.5 text-sm font-semibold text-white shadow-2xl"
                :class="toast.type === 'error' ? 'bg-danger-600' : 'bg-success-600'">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path v-if="toast.type === 'error'" stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    <path v-else stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                {{ toast.message }}
            </div>
        </Transition>

        <!-- Wave 5, Item 4: idle/absolute session-timeout warning. The countdown is its own
             role="timer" + aria-live="polite" region (updates once a second) rather than assertive —
             an assertive live region re-interrupting a screen-reader user every single second was
             judged too chatty; role="timer" already signals "this updates on its own" to AT, and
             polite announcements queue instead of barging in. -->
        <div v-if="showIdleWarning" class="fixed inset-0 z-[90] grid place-items-center bg-navy-950/50 p-4" role="alertdialog" aria-modal="true" aria-labelledby="idle-title">
            <div class="w-full max-w-sm rounded-2xl bg-card p-6 text-center shadow-2xl ring-1 ring-line">
                <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-tint-warning text-on-warning">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </div>
                <h2 id="idle-title" class="font-display text-lg font-bold text-ink-900">Session expiring</h2>
                <p class="mt-1 text-sm text-ink-500">
                    You'll be signed out in
                    <span role="timer" aria-live="polite" class="nums font-bold text-ink-800">{{ idleSecondsLeft }} second{{ idleSecondsLeft === 1 ? '' : 's' }}</span>
                    {{ idleReason === 'absolute' ? '— your session has reached its maximum length.' : 'due to inactivity.' }}
                </p>
                <div class="mt-4 flex gap-2">
                    <!-- "Stay signed in" only extends the IDLE clock (see staySignedIn()'s comment) — withheld
                         for an absolute-timeout warning so it never promises something it can't do. -->
                    <button v-if="idleReason !== 'absolute'" @click="staySignedIn" class="flex-1 rounded-xl bg-brand-solid px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-solid-hover">Stay signed in</button>
                    <button @click="lockNow" class="flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold text-ink-600 ring-1 ring-line transition hover:bg-ink-50">Lock now</button>
                </div>
            </div>
        </div>

        <!-- Global themed confirmation dialog (replaces window.confirm; reads useConfirm singleton) -->
        <ConfirmDialog />

        <!-- Global "back to top" — appears on every page once the reader scrolls past the fold -->
        <ScrollTopButton />
    </div>
</template>

