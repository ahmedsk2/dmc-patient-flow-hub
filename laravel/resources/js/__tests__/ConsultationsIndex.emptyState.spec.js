import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// U1 (role walkthrough 2026-09-25) — reproduced as a no-capability resident: the ledger defaults to
// the "New" tab, the tab is genuinely empty, but the summary tiles above (Open (all) 1, Ongoing 1)
// prove this SAME viewer can see a real row one click away. The old empty state just said "No
// consultations match your filters.", which reads as "there is nothing here" rather than "you are
// looking at one empty slice of your own book". The fix names what `stats` (already scoped by
// Consultation::scopeVisibleTo) says exists elsewhere and offers a one-click tab switch.
//
// U1 pt.2 — a viewer with no specialty AND no coordinator capability is narrowed by scopeVisibleTo
// to just `consultant_id = them OR entered_by = them` (no specialty clause at all); the page now
// shows the server-computed `scopeNotice` explaining that, verified against the exact predicate.

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

const resident = { role: 4, is_admin: false, id: 9, can: { manage: false } };
const baseProps = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    reasons: [], consultants: [], specialties: [],
    worklist: { date: '2026-09-25', seen: 0, total: 0, items: [] },
};
const mountWith = (extra = {}) => { authUser = resident; return mount(ConsultationsIndex, { props: { ...baseProps, ...extra } }); };

beforeEach(() => { post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Consultations/Index — empty-state hint (U1)', () => {
    it('the exact reproduced case: New tab empty, Ongoing holds 1 — names it and offers a switch', () => {
        const w = mountWith({
            filters: { status: 'new' },
            stats: { new: 0, active: 0, ongoing: 1, signed_off: 0, total: 1, open: 1, mine_open: 0 },
        });

        const text = w.text();
        expect(text).toContain('No new consultations');
        expect(text).toContain('1 ongoing');
        const switchBtn = w.findAll('button').find((b) => b.text() === 'Show Ongoing · no daily F/U');
        expect(switchBtn).toBeTruthy();
    });

    it('clicking the switch button re-queries the named tab (reuses setStatus, not a new endpoint)', async () => {
        const w = mountWith({
            filters: { status: 'new' },
            stats: { new: 0, active: 0, ongoing: 1, signed_off: 0, total: 1, open: 1, mine_open: 0 },
        });
        const { router } = await import('@inertiajs/vue3');

        const switchBtn = w.findAll('button').find((b) => b.text() === 'Show Ongoing · no daily F/U');
        await switchBtn.trigger('click');

        expect(router.get).toHaveBeenCalledWith('/consultations', expect.objectContaining({ status: 'ongoing' }), expect.anything());
    });

    it('every other tab is also genuinely empty: falls back to the plain message, no switch buttons', () => {
        const w = mountWith({
            filters: { status: 'new' },
            stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
        });

        expect(w.text()).toContain('No new consultations.');
        expect(w.text()).not.toContain('elsewhere');
        expect(w.findAll('button').some((b) => b.text().startsWith('Show '))).toBe(false);
    });

    it('a populated tab shows the ordinary table, not the empty state', () => {
        const w = mountWith({
            filters: { status: 'new' },
            consultations: { data: [{ id: 1, name: 'A', mrn: '1', reasons: [], indication_ids: [], status: 'new', open_days: 0, signoff: null, can_modify: true }], total: 1, last_page: 1, links: [] },
            stats: { new: 1, active: 0, ongoing: 0, signed_off: 0, total: 1, open: 1, mine_open: 0 },
        });

        expect(w.text()).not.toContain('No new consultations');
    });
});

describe('Consultations/Index — scope notice for a no-specialty, no-coordinator viewer (U1 pt.2)', () => {
    it('renders the server-supplied scopeNotice near the ledger', () => {
        const w = mountWith({
            filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
            scopeNotice: 'You see only the consultations you booked or are the consultant for — your account has no specialty and no coordinator role.',
        });

        expect(w.text()).toContain('your account has no specialty and no coordinator role');
    });

    it('shows nothing when the server sends no notice (the common case)', () => {
        const w = mountWith({
            filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
        });

        expect(w.text()).not.toContain('no specialty and no coordinator role');
    });
});
