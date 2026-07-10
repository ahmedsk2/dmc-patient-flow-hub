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

describe('Item 4 — confirmation-fatigue trim (shuffle / undoMedical fire immediately)', () => {
    it('shuffle posts immediately and never calls ask()', () => {
        const vm = mountWith(consultant()).vm;
        vm.shuffle();
        expect(post).toHaveBeenCalledWith('/admissions/shuffle', {}, { preserveScroll: true });
        expect(ask).not.toHaveBeenCalled();
    });
    it('undoMedical posts immediately and never calls ask()', () => {
        const vm = mountWith(consultant()).vm;
        vm.undoMedical({ id: 42, name: 'X', mrn: '1' });
        expect(post).toHaveBeenCalledWith('/admissions/42/undo-medical-discharge', {}, { preserveScroll: true });
        expect(ask).not.toHaveBeenCalled();
    });
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
    it('myGroupOnly filters visibleGroups to the consultant\'s own group', () => {
        const vm = mountWith(consultant({ id: 5 }), { groups: groups([5, 6, 7]) }).vm;
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([5, 6, 7]);
        vm.setMyGroupOnly(true);
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([5]);
        expect(localStorage.getItem('dmc-board-my-group')).toBe('1');
    });
    it('admin is never filtered by myGroupOnly', () => {
        const vm = mountWith(admin, { groups: groups([1, 2, 3]) }).vm;
        vm.setMyGroupOnly(true);
        expect(vm.visibleGroups.map((g) => g.id)).toEqual([1, 2, 3]);
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
