import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

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
const baseProps = () => ({
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { active: 0, total: 0, mine_active: 0 },
    reasons: [], consultants: [], specialties: [],
    worklist: {
        date: '2026-08-21', seen: 1, total: 2,
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
});
