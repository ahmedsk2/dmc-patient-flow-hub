import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount, mount } from '@vue/test-utils';

// Shared Inertia mock — router methods are spies so we can assert "fired immediately" for the
// confirmation-fatigue trim (Item 4). useForm returns a plain object exposing post/reset/etc.
// vi.hoisted keeps the spies available inside the hoisted vi.mock factories.
const { post, ask } = vi.hoisted(() => ({ post: vi.fn(), ask: vi.fn(() => Promise.resolve(true)) }));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: vi.fn(), reload: vi.fn(), on: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, post: vi.fn(), put: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), errors: {}, processing: false }),
}));
// ask() must NOT be called for the trimmed actions — spy on it.
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/IcdTypeahead.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/ActivityPanel.vue', () => ({ default: { template: '<div />' } }));

import PatientsIndex from '@/Pages/Patients/Index.vue';
import InfoTip from '@/Components/InfoTip.vue';

const consultant = (over = {}) => ({ role: 3, is_admin: false, id: 5, can: { assign: true, manage: true, modify: true }, ...over });
const admin = { role: 0, is_admin: true, id: 1, can: { assign: false, manage: false, modify: false } };

const groups = (ids = []) => ids.map((id) => ({
    id, name: `Dr ${id}`, specialty_id: 1, on_service: true,
    patients: [], counts: { new: 0, old: 0, active: 0, ward: 0, icu: 0, tb: 0, total: 0 },
}));

const baseProps = (over = {}) => ({
    groups: [], filters: {}, stats: { total: 0, ward: 0, icu: 0, unassigned: 0 },
    consultants: [], specialties: [], externalServices: [], readmitWindow: 3, countries: [], fallback: null,
    ...over,
});

const mountWith = (user, props = {}) => {
    authUser = user;
    return shallowMount(PatientsIndex, { props: baseProps(props), global: { stubs: { teleport: true } } });
};
// full render (template) — for HTML assertions; child components are mocked above so this is cheap.
const renderWith = (user, props = {}) => {
    authUser = user;
    return mount(PatientsIndex, { props: baseProps(props), global: { stubs: { teleport: true } } });
};

beforeEach(() => { post.mockClear(); ask.mockClear(); localStorage.clear(); });

describe('Item 4 — confirmation-fatigue trim (shuffle fires immediately)', () => {
    it('shuffle posts immediately and never calls ask()', () => {
        const vm = mountWith(consultant()).vm;
        vm.shuffle();
        expect(post).toHaveBeenCalledWith('/admissions/shuffle', {}, { preserveScroll: true });
        expect(ask).not.toHaveBeenCalled();
    });
    // undoMedical (also a no-confirm trim) RELOCATED to Components/Patients/__tests__/PatientCard.spec.js
    // — the per-card row actions (longterm/undoMedical/delete + bed edit) moved off the Index instance
    // into PatientCard when the board card was extracted so the Grouped + Split boards share it.
});

// Item 5 — modal title map: RELOCATED to Components/Patients/__tests__/ActionModal.spec.js. The
// modal state (modal/openModal/modalTitle/aForm/…) moved off the Index instance into ActionModal
// (Wave 3, Item 4 split). The relocated specs assert the assign title is "Assign consultant", the
// other modes keep their labels, the board-assign mark_new default, and the per-mode submit URLs.
// Index now only OPENS the modal (openModal sets { mode, row } → passed as props); the title lives
// in the child. See ActionModal.spec.js "title map (relocated from PatientsIndex.wave2 Item 5)".

describe('Item 7 — persist expand/collapse + my-group-only', () => {
    it('toggle(id) persists the open Set to localStorage', () => {
        const vm = mountWith(consultant(), { groups: groups([5, 6]) }).vm;
        vm.toggle(5);
        expect(JSON.parse(localStorage.getItem('dmc-board-open'))).toContain(5);
        expect(vm.open.has(5)).toBe(true);
    });
    it('allClosed persists an empty array', () => {
        const vm = mountWith(consultant(), { groups: groups([5, 6]) }).vm;
        vm.allClosed();
        expect(JSON.parse(localStorage.getItem('dmc-board-open'))).toEqual([]);
    });
    // (role walkthrough 2026-09-25, U6) setMyGroupOnly() is gone with its one caller (the toggle
    // button) — myGroupOnly is now only ever set from a leftover localStorage value (see below), so
    // these set the ref directly to prove the underlying filter itself still behaves.
    it('myGroupOnly filters visibleGroups to the consultant\'s own group', () => {
        const vm = mountWith(consultant({ id: 5 }), { groups: groups([5, 6, 7]) }).vm;
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([5, 6, 7]);
        vm.myGroupOnly = true;
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([5]);
    });
    it('admin is never filtered by myGroupOnly', () => {
        const vm = mountWith(admin, { groups: groups([1, 2, 3]) }).vm;
        vm.myGroupOnly = true;
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([1, 2, 3]);
    });
    it('a leftover "1" in localStorage from before this change still no-ops (no UI can set it any more)', () => {
        localStorage.setItem('dmc-board-my-group', '1');
        const vm = mountWith(consultant({ id: 5 }), { groups: groups([5, 6, 7]) }).vm;
        expect(vm.myGroupOnly).toBe(true);   // restored as before
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([5]);   // same filter, just unreachable via the UI now
    });
    // (role walkthrough 2026-09-25, review finding) the guard used to be `!me.is_admin` alone, so a
    // stale flag (shared browser, or set before U6 removed the button) filtered visibleGroups to
    // `g.id === me.id` for EVERY non-admin role — but group ids are consultant ids, so a Registrar's,
    // Resident's or Observer's own id never matches any group and the board silently went empty.
    it('a leftover "1" in localStorage does NOT filter a non-consultant role\'s board (Registrar)', () => {
        localStorage.setItem('dmc-board-my-group', '1');
        const registrar = { role: 2, is_admin: false, id: 5, can: { assign: true, manage: true, modify: true } };
        const vm = mountWith(registrar, { groups: groups([5, 6, 7]) }).vm;
        expect(vm.myGroupOnly).toBe(true);   // the flag itself is still restored from storage...
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([5, 6, 7]);   // ...but never applied for this role
    });
    it('a leftover "1" in localStorage does NOT filter a non-consultant role\'s board (Resident)', () => {
        localStorage.setItem('dmc-board-my-group', '1');
        const resident = { role: 4, is_admin: false, id: 9, can: { assign: false, manage: true, modify: false } };
        const vm = mountWith(resident, { groups: groups([5, 6, 7]) }).vm;
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([5, 6, 7]);
    });
});

// (role walkthrough 2026-09-25, info marks a/b) three testers misread "Active" as Ward+ICU
// (PatientsController.php:407-425), and "Census" had no explanation for why it can differ from the
// Dashboard's Active Census tile (PatientsController.php line ~107 vs DASHBOARD-AND-STATISTICS-
// METRICS.md's "Active Census" row) — Ward (non-ICU) right next to it already carried one.
describe('info marks a/b — Active column + Census pill InfoTips', () => {
    it('the per-consultant table\'s "Active" column carries an InfoTip', () => {
        // shallowMount (mountWith) auto-stubs AppLayout's slot away even though it's mocked to a
        // slot-passthrough template — renderWith (full mount) is this file's own pattern for
        // template/text assertions (see the fallback-banner tests below).
        const w = renderWith(consultant(), { groups: groups([5]) });
        const labels = w.findAllComponents(InfoTip).map((t) => t.props('label'));
        expect(labels).toContain('Active column');
    });
    it('the toolbar\'s "Census" pill carries an InfoTip explaining the Dashboard mismatch', () => {
        const w = renderWith(consultant(), { groups: groups([5]) });
        const labels = w.findAllComponents(InfoTip).map((t) => t.props('label'));
        expect(labels).toContain('Census count');
    });
});

// (role walkthrough 2026-09-25, U6) the "My patients only" toggle only ever rendered for
// User::seesOwnPatientsOnly()'s exact condition (role===3 && !is_admin) — the one role the SERVER
// already scopes the board to unconditionally (PatientsController::boardScope), so it was a no-op
// click. Replaced with a static note for that role; every other role never saw the control at all.
describe('U6 — "My patients only" toggle replaced with a static note for a plain consultant', () => {
    it('a plain consultant sees the static note, not the interactive toggle', () => {
        const w = renderWith(consultant());
        expect(w.text()).toContain('Showing your patients');
        expect(w.findAll('button').some((b) => b.text() === 'My patients only')).toBe(false);
    });
    it('an admin (or any other role) sees neither the toggle nor the static note', () => {
        const w = renderWith(admin);
        expect(w.text()).not.toContain('Showing your patients');
        expect(w.findAll('button').some((b) => b.text() === 'My patients only')).toBe(false);
    });
});

// Item 9 — uncheckAllStale + the bulk preflight gate: RELOCATED to
// Components/Patients/__tests__/ReassignModal.spec.js. The whole bulk-reassign flow (rForm,
// selectedIds, preflight, staleRows, preflightReady, uncheckAllStale, saveAllStale, submitReassign)
// moved off the Index instance into ReassignModal (Wave 3, Item 4 split). The relocated specs assert
// the same thing this one did — uncheckAllStale drops stale ids so preflightReady unlocks — plus the
// preflight load, the locked-while-stale gate, saveAllStale, and the subset submit. See
// ReassignModal.spec.js "uncheckAllStale (relocated Item 9)".

describe('Item 1 — fallback prop + zero-state affordance', () => {
    it('accepts a fallback object and exposes it', () => {
        const vm = mountWith(consultant(), { fallback: { discharged: 2, unassigned: 1, search: 'Ali' } }).vm;
        expect(vm.fallback.discharged).toBe(2);
        expect(vm.fallback.unassigned).toBe(1);
    });
    it('renders the discharged/unassigned affordance when groups empty (term POSTs — SPC-TM-011)', async () => {
        const w = renderWith(consultant(), { groups: [], fallback: { discharged: 2, unassigned: 1, search: 'Ali' } });
        const html = w.html();
        expect(html).toContain('No active match.');
        expect(html).toContain('discharged');
        expect(html).toContain('awaiting assignment');
        // the registry jump carries the term in a POST body, never a URL; the queue link stays a link
        expect(html).not.toContain('search=Ali');
        const view = w.findAll('button').find((b) => b.text().includes('view →'));
        expect(view).toBeTruthy();
        await view.trigger('click');
        expect(post).toHaveBeenCalledWith('/registry?mode=admissions&discharged=1', { search: 'Ali' });
        const hrefs = w.findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toContain('/admissions');
    });
    it('renders NO fallback row when fallback is null', () => {
        const w = renderWith(consultant(), { groups: [], fallback: null });
        expect(w.html()).not.toContain('No active match.');
    });
});

describe('Item 8 — standardized verb wording', () => {
    it('group-header reassign button reads "Reassign" (not "Change consultant")', () => {
        const g = groups([5]);
        g[0].patients = [{ id: 1, name: 'P', mrn: '1', location: 'Ward', consultant_id: 5, los: null, dx_count: 0, diagnoses: [] }];
        const w = renderWith(consultant({ can: { assign: true, manage: true, modify: true } }), { groups: g });
        const html = w.html();
        expect(html).toContain('Reassign');
        expect(html).not.toContain('Change consultant');
    });
});

// Wave 5, Item 1 (a11y): the board summary table's per-consultant row was a click-only <tr> (no
// keyboard path to toggle expand/collapse). Now a real role="button" + tabindex="0" +
// keydown.enter/space row — verify all three ways in, and that it isn't just a div-with-@click.
describe('Wave 5 — board summary row is keyboard-operable', () => {
    it('the summary row carries tabindex="0" and role="button", not a bare div/tr-with-@click', () => {
        const w = renderWith(consultant(), { groups: groups([5]) });
        const row = w.find('tr[role="button"]');
        expect(row.exists()).toBe(true);
        expect(row.attributes('tabindex')).toBe('0');
        expect(row.attributes('aria-label')).toContain('Dr. Dr 5');
    });

    it('Enter toggles expand/collapse, same as a click', async () => {
        const w = renderWith(consultant(), { groups: groups([5]) });
        expect(w.vm.open.has(5)).toBe(false);   // collapsed by default (not filtering)
        await w.find('tr[role="button"]').trigger('keydown.enter');
        expect(w.vm.open.has(5)).toBe(true);
    });

    it('Space toggles expand/collapse and does not scroll the page (space.prevent)', async () => {
        const w = renderWith(consultant(), { groups: groups([5]) });
        expect(w.vm.open.has(5)).toBe(false);
        await w.find('tr[role="button"]').trigger('keydown.space');
        expect(w.vm.open.has(5)).toBe(true);
    });
});

// PERF-03: the long-term registry is capped server-side; the page must SAY it was trimmed rather
// than present a partial registry as a complete one. Registry search is admin-only, so the
// recovery affordance differs by role — a consultant pointed at /registry would hit a 403.
describe('PERF-03 — truncated long-term registry banner', () => {
    it('says nothing when the server did not trim', () => {
        const w = renderWith(admin, { truncated: null });
        expect(w.text()).not.toContain('long-term episodes');
    });

    it('reports the counts and promises that admitted patients are still listed', () => {
        const w = renderWith(admin, { truncated: { shown: 1000, total: 1200 } });
        expect(w.text()).toContain('Showing 1000 of 1200 long-term episodes');
        expect(w.text()).toContain('Every patient still admitted is listed');
    });

    it('offers Registry search to an admin only, and the board search to everyone else', () => {
        const forAdmin = renderWith(admin, { truncated: { shown: 1000, total: 1200 } });
        expect(forAdmin.html()).toContain('/registry');

        const forConsultant = renderWith(consultant(), { truncated: { shown: 1000, total: 1200 } });
        expect(forConsultant.html()).not.toContain('/registry');
        expect(forConsultant.text()).toContain('Search by name or MRN above');
    });
});
