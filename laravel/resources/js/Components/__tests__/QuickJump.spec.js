import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Wave 1 (EHC UI) — QuickJump grew into the command palette. The contract under test:
 *   • opens with Ctrl+K / Cmd+K anywhere and with "/" outside form fields; Esc closes and returns
 *     focus to the opener; a trigger is present for touch users at every breakpoint;
 *   • combobox a11y: the INPUT keeps focus for the whole interaction; options live in a
 *     role="listbox" of role="option" items tracked by aria-activedescendant — focus never moves
 *     into the list;
 *   • grouped results: Patients (server search) · Actions · Nav (static, role-filtered) · Recents;
 *   • first-class loading / empty / error+Retry states + a polite live result count;
 *   • SPC-TM-011: the search term travels ONLY in a POST body — never in a query string — and
 *     picking a row navigates by POSTing the MRN to the board/registry;
 *   • recents hold OPAQUE admission ids in module memory only, re-hydrated server-side on open.
 */
let pageProps;
const routerMock = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: routerMock,
    usePage: () => ({ props: pageProps }),
}));

import QuickJump from '@/Components/QuickJump.vue';
import { pushRecent, clearRecents, recentIds } from '@/lib/recentPatients';

const row = (over = {}) => ({
    id: 1, mrn: '10001', name: 'Amal Hassan', age: 63, gender: 'Female', location: 'Ward',
    admit_date: '2026-07-01', status: 'active', deceased: false, consultant: 'Dr. A', dest: 'board', ...over,
});

const adminUser = (over = {}) => ({
    id: 9, name: 'Admin', role: 0, is_admin: true,
    can: { add: true, assign: true, manage: true, modify: true }, ...over,
});
const residentUser = () => ({
    id: 4, name: 'Res', role: 4, is_admin: false, can: { add: false, assign: false, manage: false, modify: false },
});

let fetchCalls;
const stubFetch = (rowsForQ = [], rowsForIds = [], { fail = false, pending = false } = {}) => {
    fetchCalls = [];
    vi.stubGlobal('fetch', vi.fn((url, opts = {}) => {
        fetchCalls.push({ url, opts });
        if (pending) return new Promise(() => {});
        if (fail) return Promise.resolve({ ok: false, status: 500, json: async () => [] });
        const body = JSON.parse(opts.body || '{}');
        return Promise.resolve({ ok: true, status: 200, json: async () => (body.ids ? rowsForIds : rowsForQ) });
    }));
};

const mountPalette = () => mount(QuickJump, { attachTo: document.body });

const key = (props) => window.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, ...props }));
const openViaCtrlK = async (w) => { key({ key: 'k', ctrlKey: true }); await flushPromises(); await w.vm.$nextTick(); };
const input = (w) => w.get('input[aria-label="Patient search query"]');
const typeSearch = async (w, text) => {
    await input(w).setValue(text);
    await vi.advanceTimersByTimeAsync(260);
    await flushPromises();
};

beforeEach(() => {
    vi.useFakeTimers();
    clearRecents();
    routerMock.post.mockClear();
    routerMock.visit.mockClear();
    pageProps = { auth: { user: adminUser() } };
    stubFetch([row()]);
});
afterEach(() => {
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('opening and closing', () => {
    it('opens with Ctrl+K and with Cmd+K', async () => {
        const w = mountPalette();
        expect(w.find('[role="dialog"]').exists()).toBe(false);
        await openViaCtrlK(w);
        expect(w.find('[role="dialog"]').exists()).toBe(true);
        await input(w).trigger('keydown', { key: 'Escape' });
        expect(w.find('[role="dialog"]').exists()).toBe(false);
        key({ key: 'k', metaKey: true });
        await w.vm.$nextTick();
        expect(w.find('[role="dialog"]').exists()).toBe(true);
        w.unmount();
    });

    it('opens with "/" — but not while typing in a form field', async () => {
        const w = mountPalette();
        const field = document.createElement('input');
        document.body.appendChild(field);
        field.focus();
        key({ key: '/' });
        await w.vm.$nextTick();
        expect(w.find('[role="dialog"]').exists()).toBe(false);
        field.blur();
        document.body.removeChild(field);
        key({ key: '/' });
        await w.vm.$nextTick();
        expect(w.find('[role="dialog"]').exists()).toBe(true);
        w.unmount();
    });

    it('has a trigger for touch users at EVERY breakpoint (a compact one below sm)', () => {
        const w = mountPalette();
        expect(w.find('[data-qj-trigger]').exists()).toBe(true);
        const mobile = w.get('[data-qj-trigger-mobile]');
        expect(mobile.classes()).toContain('sm:hidden');
        expect(mobile.attributes('aria-label')).toBeTruthy();
        w.unmount();
    });

    it('Esc returns focus to the trigger that opened it', async () => {
        const w = mountPalette();
        const trigger = w.get('[data-qj-trigger]');
        trigger.element.focus();
        await trigger.trigger('click');
        await w.vm.$nextTick();
        await input(w).trigger('keydown', { key: 'Escape' });
        await w.vm.$nextTick();
        expect(document.activeElement).toBe(trigger.element);
        w.unmount();
    });
});

describe('SPC-TM-011 — the term travels in a POST body, never a URL', () => {
    it('searching POSTs {q} to the endpoint with no query string', async () => {
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'ama');
        const call = fetchCalls.find((c) => c.opts.method === 'POST');
        expect(call).toBeTruthy();
        expect(call.url).toBe('/api/patients/quick-search');
        expect(call.url).not.toContain('?');
        expect(JSON.parse(call.opts.body).q).toBe('ama');
        w.unmount();
    });

    it('the input opts out of browser autofill retention', async () => {
        const w = mountPalette();
        await openViaCtrlK(w);
        expect(input(w).attributes('autocomplete')).toBe('off');
        w.unmount();
    });

    it('picking an active row POSTs the MRN to the board; a discharged row POSTs to the registry', async () => {
        stubFetch([row(), row({ id: 2, mrn: '10002', name: 'Amr Closed', status: 'discharged', dest: 'registry' })]);
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        await input(w).trigger('keydown', { key: 'Enter' });
        expect(routerMock.post).toHaveBeenCalledWith('/patients', { search: '10001' });
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        await input(w).trigger('keydown', { key: 'ArrowDown' });
        await input(w).trigger('keydown', { key: 'Enter' });
        expect(routerMock.post).toHaveBeenCalledWith('/registry?mode=admissions', { search: '10002' });
        w.unmount();
    });
});

describe('combobox a11y model', () => {
    it('options are role=option in a role=listbox; the input keeps focus and tracks via aria-activedescendant', async () => {
        stubFetch([row(), row({ id: 2, mrn: '10002', name: 'Amr Two' })]);
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        const list = w.get('[role="listbox"]');
        const options = list.findAll('[role="option"]');
        expect(options.length).toBeGreaterThanOrEqual(2);
        expect(document.activeElement).toBe(input(w).element);
        expect(input(w).attributes('aria-activedescendant')).toBe(options[0].attributes('id'));
        expect(options[0].attributes('aria-selected')).toBe('true');

        await input(w).trigger('keydown', { key: 'ArrowDown' });
        expect(input(w).attributes('aria-activedescendant')).toBe(options[1].attributes('id'));
        expect(options[1].attributes('aria-selected')).toBe('true');
        // focus NEVER moved into the list
        expect(document.activeElement).toBe(input(w).element);

        await input(w).trigger('keydown', { key: 'ArrowUp' });
        expect(input(w).attributes('aria-activedescendant')).toBe(options[0].attributes('id'));
        w.unmount();
    });

    it('group headers are presentational', async () => {
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        const headers = w.findAll('[role="presentation"]');
        expect(headers.length).toBeGreaterThanOrEqual(1);
        w.unmount();
    });

    it('announces a polite result count', async () => {
        stubFetch([row(), row({ id: 2, mrn: '10002', name: 'Amr Two' })]);
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        const live = w.get('[aria-live="polite"]');
        expect(live.text()).toMatch(/2 results/);
        w.unmount();
    });
});

describe('groups — Patients · Actions · Nav · Recents', () => {
    it('typing shows Patients results plus label-matching Actions/Nav destinations', async () => {
        stubFetch([row()]);
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'regis');   // matches "Search the registry" / "Registry", no patient rows needed
        const text = w.text();
        expect(text).toContain('Actions');
        expect(text).toContain('Nav');
        expect(text).toContain('Search the registry');
        w.unmount();
    });

    it('idle open (no query) lists Actions + Nav destinations', async () => {
        const w = mountPalette();
        await openViaCtrlK(w);
        const text = w.text();
        expect(text).toContain('Actions');
        expect(text).toContain('Nav');
        expect(text).toContain('Dashboard');
        expect(text).toContain('Patients');
        w.unmount();
    });

    it('destinations are role-filtered client-side from the shared can/is_admin props', async () => {
        pageProps = { auth: { user: residentUser() } };
        const w = mountPalette();
        await openViaCtrlK(w);
        const text = w.text();
        expect(text).not.toContain('New admission');
        expect(text).not.toContain('Registry');
        expect(text).not.toContain('Audit Log');
        expect(text).toContain('Dashboard');
        w.unmount();
    });

    it('picking a Nav destination is a plain visit (no term involved)', async () => {
        const w = mountPalette();
        await openViaCtrlK(w);
        await input(w).trigger('keydown', { key: 'Enter' });   // first idle option
        expect(routerMock.visit).toHaveBeenCalled();
        expect(routerMock.post).not.toHaveBeenCalled();
        w.unmount();
    });

    it('identity-ribbon rows: MRN is tabular and never shortened; discharged + deceased are marked', async () => {
        stubFetch([
            row({ id: 3, mrn: '30003', name: 'Gone Home', status: 'discharged', dest: 'registry' }),
            row({ id: 4, mrn: '30004', name: 'Passed Away', status: 'discharged', deceased: true, dest: 'registry' }),
        ]);
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'go');
        expect(w.text()).toContain('Discharged');
        expect(w.text()).toContain('Deceased');
        const mrn = w.get('[data-id-mrn]');
        expect(mrn.classes()).toContain('nums');
        expect(mrn.classes()).not.toContain('truncate');
        // the dim end-aligned last-admission date
        expect(w.text()).toContain('2026-07-01');
        w.unmount();
    });
});

describe('first-class states', () => {
    it('loading is calm text', async () => {
        stubFetch([], [], { pending: true });
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        expect(w.text()).toContain('Searching');
        w.unmount();
    });

    it('empty says "No matches"', async () => {
        stubFetch([]);
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'zzz');
        expect(w.text()).toContain('No matches');
        w.unmount();
    });

    it('error offers Retry, and Retry refetches', async () => {
        stubFetch([], [], { fail: true });
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        const retry = w.findAll('button').find((b) => b.text() === 'Retry');
        expect(retry).toBeTruthy();
        const before = fetchCalls.length;
        await retry.trigger('click');
        await flushPromises();
        expect(fetchCalls.length).toBe(before + 1);
        w.unmount();
    });
});

describe('recents — opaque ids in module memory only', () => {
    it('the module keeps the last 7 unique ids, most recent first', () => {
        for (let i = 1; i <= 9; i++) pushRecent(i);
        pushRecent(5);
        expect(recentIds()).toEqual([5, 9, 8, 7, 6, 4, 3]);
        clearRecents();
        expect(recentIds()).toEqual([]);
    });

    it('picking a patient records ONLY its opaque id', async () => {
        const w = mountPalette();
        await openViaCtrlK(w);
        await typeSearch(w, 'am');
        await input(w).trigger('keydown', { key: 'Enter' });
        expect(recentIds()).toEqual([1]);
        w.unmount();
    });

    it('on open, recents re-hydrate via the SAME endpoint by POSTing ids (no PII cached client-side)', async () => {
        pushRecent(42);
        stubFetch([], [row({ id: 42, mrn: '40042', name: 'Recent Rana' })]);
        const w = mountPalette();
        await openViaCtrlK(w);
        await flushPromises();
        const idsCall = fetchCalls.find((c) => c.opts.body && JSON.parse(c.opts.body).ids);
        expect(idsCall).toBeTruthy();
        expect(idsCall.opts.method).toBe('POST');
        expect(JSON.parse(idsCall.opts.body).ids).toEqual([42]);
        expect(w.text()).toContain('Recents');
        expect(w.text()).toContain('Recent Rana');
        w.unmount();
    });

    it('no recents → no Recents group and no ids fetch', async () => {
        const w = mountPalette();
        await openViaCtrlK(w);
        await flushPromises();
        expect(fetchCalls.filter((c) => c.opts.body && JSON.parse(c.opts.body).ids)).toEqual([]);
        expect(w.text()).not.toContain('Recents');
        w.unmount();
    });
});
