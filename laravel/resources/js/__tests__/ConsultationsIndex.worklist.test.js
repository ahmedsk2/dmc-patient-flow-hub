import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { localToday } from '@/lib/ui.js';

// Wave 2b — "Today's follow-up" panel on Consultations/Index.vue: the completeness indicator, the
// one-tap check-off (a JSON fetch, not an Inertia visit) and the inline refusal message.

const { post, put, deleteFn, ask } = vi.hoisted(() => ({
    post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => reactive({
        ...obj, errors: {}, processing: false,
        post: vi.fn((...a) => post(...a)),
        put: vi.fn((...a) => put(...a)),
        reset: vi.fn(), clearErrors: vi.fn(),
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';

const doc = { role: 3, is_admin: false, id: 9, can: { manage: false } };
// worklist.date matches the same day the assertions read `wl.date !== localToday()` against — it
// MUST track the real clock (not a fixed string) or every test here would trip the staleness guard
// added to fix the midnight bug, exactly the drift that guard exists to catch.
const baseProps = () => ({
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { new: 2, active: 3, ongoing: 4, signed_off: 5, total: 14, open: 9, mine_open: 1 },
    reasons: [], consultants: [], specialties: [],
    worklist: {
        date: localToday(), seen: 1, total: 2,
        items: [
            { id: 11, name: 'Aaa Ticked', mrn: '1001', bed: 'W-1', location: 'Ward', consultant: 'Dr A', seen_today: true },
            { id: 12, name: 'Bbb Pending', mrn: '1002', bed: 'W-2', location: 'Ward', consultant: 'Dr A', seen_today: false },
        ],
    },
});
const mountWith = (over = {}) => { authUser = doc; return mount(ConsultationsIndex, { props: { ...baseProps(), ...over } }); };

beforeEach(() => {
    post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset();
    global.fetch = vi.fn();
});

describe("Consultations/Index — Today's follow-up worklist", () => {
    it('renders the completeness indicator from the worklist prop', () => {
        const w = mountWith();
        expect(w.text()).toContain('Seen 1 of 2 today');
    });

    it('is hidden entirely when the active set is empty', () => {
        const w = mountWith({ worklist: { date: '2026-08-21', seen: 0, total: 0, items: [] } });
        expect(w.find('[data-test="worklist"]').exists()).toBe(false);
    });

    it('markSeen POSTs the note to the followup endpoint and flips the row', async () => {
        global.fetch.mockResolvedValue({ ok: true, json: async () => ({ ok: true, status: 'active', promoted: false }) });
        const w = mountWith();
        w.vm.wlNotes[12] = '  Reviewed, no change  ';
        await w.vm.markSeen(w.vm.wl.items[1]);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, opts] = global.fetch.mock.calls[0];
        expect(url).toBe('/consultations/12/followup');
        expect(opts.method).toBe('POST');
        expect(JSON.parse(opts.body)).toEqual({ note: 'Reviewed, no change' });
        expect(w.vm.wl.items[1].seen_today).toBe(true);
        expect(w.vm.wlSeen).toBe(2);
    });

    it('sends a null note when the box is empty', async () => {
        global.fetch.mockResolvedValue({ ok: true, json: async () => ({ ok: true, status: 'active', promoted: false }) });
        const w = mountWith();
        await w.vm.markSeen(w.vm.wl.items[1]);
        expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({ note: null });
    });

    it('shows the server refusal inline and leaves the row unticked', async () => {
        global.fetch.mockResolvedValue({
            ok: false, status: 422,
            json: async () => ({ message: 'A follow-up is already recorded for this consultation today.' }),
        });
        const w = mountWith();
        await w.vm.markSeen(w.vm.wl.items[1]);
        await w.vm.$nextTick();

        expect(w.vm.wl.items[1].seen_today).toBe(false);
        expect(w.text()).toContain('A follow-up is already recorded for this consultation today.');
    });

    it('ignores a second click while a tick is already in flight', async () => {
        let release;
        global.fetch.mockReturnValue(new Promise((r) => { release = r; }));
        const w = mountWith();
        const first = w.vm.markSeen(w.vm.wl.items[1]);
        await w.vm.markSeen(w.vm.wl.items[0]);
        expect(global.fetch).toHaveBeenCalledTimes(1);
        release({ ok: true, json: async () => ({ ok: true }) });
        await first;
    });

    it('a click on a DIFFERENT row is not blocked while another row is in flight', async () => {
        // Item 15 mirrors item 11/12's shape so the second row is a genuine, still-pending tick
        // (item 0 in the fixture is already seen_today and would short-circuit regardless).
        const releases = [];
        global.fetch.mockImplementation(() => new Promise((r) => { releases.push(r); }));
        const w = mountWith({
            worklist: {
                date: localToday(), seen: 0, total: 2,
                items: [
                    { id: 12, name: 'Bbb Pending', mrn: '1002', bed: 'W-2', location: 'Ward', consultant: 'Dr A', seen_today: false },
                    { id: 15, name: 'Eee Pending', mrn: '1005', bed: 'W-5', location: 'Ward', consultant: 'Dr A', seen_today: false },
                ],
            },
        });
        const first = w.vm.markSeen(w.vm.wl.items[0]);
        await w.vm.$nextTick();
        expect(w.vm.wlBusy[15]).toBeFalsy();
        const second = w.vm.markSeen(w.vm.wl.items[1]);
        expect(global.fetch).toHaveBeenCalledTimes(2);
        releases.forEach((r) => r({ ok: true, json: async () => ({ ok: true }) }));
        await Promise.all([first, second]);
    });

    it('clicking the real "Mark seen" button in the DOM records the follow-up', async () => {
        global.fetch.mockResolvedValue({ ok: true, json: async () => ({ ok: true, status: 'active', promoted: false }) });
        const w = mountWith();
        const buttons = w.findAll('[data-test="worklist"] button');
        expect(buttons.length).toBe(1); // only the unticked row (Bbb) renders a button
        await buttons[0].trigger('click');
        await w.vm.$nextTick();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe('/consultations/12/followup');
        expect(w.vm.wl.items[1].seen_today).toBe(true);
    });

    it('special-cases a 419 (expired session) instead of the generic failure message', async () => {
        global.fetch.mockResolvedValue({ ok: false, status: 419, json: async () => { throw new Error('not JSON'); } });
        const w = mountWith();
        await w.vm.markSeen(w.vm.wl.items[1]);
        await w.vm.$nextTick();

        expect(w.vm.wl.items[1].seen_today).toBe(false);
        expect(w.text()).toContain('Your session has expired');
    });

    it('a worklist stamped with an earlier date is treated as stale: no tick, and the panel says so', async () => {
        const w = mountWith({
            worklist: {
                date: '2000-01-01', seen: 1, total: 2,
                items: [
                    { id: 11, name: 'Aaa Ticked', mrn: '1001', bed: 'W-1', location: 'Ward', consultant: 'Dr A', seen_today: true },
                    { id: 12, name: 'Bbb Pending', mrn: '1002', bed: 'W-2', location: 'Ward', consultant: 'Dr A', seen_today: false },
                ],
            },
        });
        expect(w.text()).toContain('This list is from an earlier day');

        await w.vm.markSeen(w.vm.wl.items[1]);
        expect(global.fetch).not.toHaveBeenCalled();
        expect(w.vm.wl.items[1].seen_today).toBe(false);
    });
});
