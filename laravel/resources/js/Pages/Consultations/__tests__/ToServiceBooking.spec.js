import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Role/UX review 2026-09-24, fix #4: the own-specialty booking rule (ConsultationRequest::
// ownSpecialtyRule) was always server-enforced, but the "To service" datalist used to offer every
// specialty regardless of who was looking, and the refusal only ever surfaced after the whole form
// had been filled in. ConsultationsController::index() now ships `canBookAnyTeam`,
// `bookableToServices` (already filtered to what THIS viewer may book into) and `bookingNotice`
// (the exact server refusal wording, or null). These specs pin that the New-consultation and
// Edit-consultation forms actually USE those props: a narrowed datalist and a visible notice for a
// restricted viewer, and the full list with no notice for a privileged one.

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

const consultant = { role: 3, is_admin: false, id: 42, can: { manage: false } };
const cardio = { id: 5, name: 'Cardiology', is_external: false };
const nephro = { id: 6, name: 'Nephrology', is_external: false };
const NO_SPECIALTY_NOTICE = 'Your account is not attached to a specialty, so it cannot book a consultation into a team. Ask an administrator to set your specialty or to grant you the consultation-coordinator capability.';
const OWN_SPECIALTY_NOTICE = 'You may only book consultations for your own specialty. Ask a consultation coordinator to book this one.';

const baseProps = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
    reasons: [], consultants: [], specialties: [cardio, nephro],
    worklist: { date: '2026-09-24', seen: 0, total: 0, items: [] },
};
const mountWith = (extraProps) => {
    authUser = consultant;

    return mount(ConsultationsIndex, { props: { ...baseProps, ...extraProps } });
};

beforeEach(() => { post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Consultations/Index — "To service" booking scope (role/UX review #4)', () => {
    it('a privileged viewer (canBookAnyTeam) sees every specialty and no restriction notice', async () => {
        const w = mountWith({ canBookAnyTeam: true, bookableToServices: [cardio, nephro], bookingNotice: null });
        w.vm.openAdd();
        await w.vm.$nextTick();

        const options = w.findAll('#svc-list-to option').map((o) => o.attributes('value'));
        expect(options).toEqual(['Cardiology', 'Nephrology']);
        expect(w.find('[data-test="booking-notice"]').exists()).toBe(false);
    });

    it('a specialty-bound viewer sees only their own team in the datalist, plus the own-specialty notice', async () => {
        const w = mountWith({ canBookAnyTeam: false, bookableToServices: [cardio], bookingNotice: OWN_SPECIALTY_NOTICE });
        w.vm.openAdd();
        await w.vm.$nextTick();

        const options = w.findAll('#svc-list-to option').map((o) => o.attributes('value'));
        expect(options).toEqual(['Cardiology']);
        const notice = w.find('[data-test="booking-notice"]');
        expect(notice.exists()).toBe(true);
        expect(notice.text()).toBe(OWN_SPECIALTY_NOTICE);
    });

    it('a viewer with no specialty and no coordinator capability sees an empty datalist and the no-specialty notice', async () => {
        const w = mountWith({ canBookAnyTeam: false, bookableToServices: [], bookingNotice: NO_SPECIALTY_NOTICE });
        w.vm.openAdd();
        await w.vm.$nextTick();

        expect(w.findAll('#svc-list-to option')).toHaveLength(0);
        expect(w.find('[data-test="booking-notice"]').text()).toBe(NO_SPECIALTY_NOTICE);
    });

    it('the edit form shows the notice only when the NEW value would actually be refused, not on every change (adversarial fix-up)', async () => {
        // cardio is this viewer's OWN specialty (their only entry in bookableToServices); nephro is
        // a DIFFERENT internal specialty they do not belong to.
        const w = mountWith({ canBookAnyTeam: false, bookableToServices: [cardio], bookingNotice: OWN_SPECIALTY_NOTICE });
        w.vm.openEdit({ id: 9, name: 'Pt', mrn: '1', to: 'Nephrology', consultant_id: null, indication_ids: [] });
        await w.vm.$nextTick();

        // untouched: this consult already belongs to Nephrology, which is NOT this viewer's own
        // team — but leaving it alone is the legacy-open modify path (ConsultationRequest's
        // validate-on-change), so no warning should appear yet.
        expect(w.find('[data-test="edit-booking-notice"]').exists()).toBe(false);

        // changed to a DIFFERENT internal specialty the viewer does not belong to: this really would
        // be refused by ownSpecialtyRule, so the notice must appear.
        w.vm.eForm.to_service = 'Cardiology-typo-does-not-exist';
        await w.vm.$nextTick();
        expect(w.find('[data-test="edit-booking-notice"]').exists()).toBe(false); // unmatched text is unowned — always allowed

        // changed back to the viewer's OWN specialty (Cardiology) — re-routing a mis-filed consult
        // back to one's own team is the single most common self-service correction and ALWAYS saves
        // per ownSpecialtyRule (targetId === user's specialty_id), so the notice must NOT appear —
        // this is exactly the false alarm the adversarial review caught.
        w.vm.eForm.to_service = 'Cardiology';
        await w.vm.$nextTick();
        expect(w.find('[data-test="edit-booking-notice"]').exists()).toBe(false);

        // reverted back to the stored value (Nephrology): the legacy-open modify path again
        w.vm.eForm.to_service = 'Nephrology';
        await w.vm.$nextTick();
        expect(w.find('[data-test="edit-booking-notice"]').exists()).toBe(false);
    });

    it('the edit form DOES warn when changed to a different internal specialty the viewer does not belong to', async () => {
        const w = mountWith({ canBookAnyTeam: false, bookableToServices: [cardio], bookingNotice: OWN_SPECIALTY_NOTICE });
        w.vm.openEdit({ id: 9, name: 'Pt', mrn: '1', to: 'Cardiology', consultant_id: null, indication_ids: [] });
        await w.vm.$nextTick();
        expect(w.find('[data-test="edit-booking-notice"]').exists()).toBe(false);

        // re-routed to Nephrology, a REAL other team this viewer has no claim on — ownSpecialtyRule
        // really would refuse this, so the upfront notice must say so.
        w.vm.eForm.to_service = 'Nephrology';
        await w.vm.$nextTick();
        const notice = w.find('[data-test="edit-booking-notice"]');
        expect(notice.exists()).toBe(true);
        expect(notice.text()).toBe(OWN_SPECIALTY_NOTICE);
    });

    it('the edit form warns on any internal-specialty change for a no-specialty viewer (always refused)', async () => {
        const w = mountWith({ canBookAnyTeam: false, bookableToServices: [], bookingNotice: NO_SPECIALTY_NOTICE });
        w.vm.openEdit({ id: 9, name: 'Pt', mrn: '1', to: 'Nephrology', consultant_id: null, indication_ids: [] });
        await w.vm.$nextTick();
        expect(w.find('[data-test="edit-booking-notice"]').exists()).toBe(false); // untouched

        w.vm.eForm.to_service = 'Cardiology';
        await w.vm.$nextTick();
        // a no-specialty viewer belongs to NO team, so bookableToServices is empty — every internal
        // specialty (even one they've never touched before) is refused per ownSpecialtyRule.
        const notice = w.find('[data-test="edit-booking-notice"]');
        expect(notice.exists()).toBe(true);
        expect(notice.text()).toBe(NO_SPECIALTY_NOTICE);
    });

    it('defaults canBookAnyTeam/bookableToServices/bookingNotice safely when the props are absent', () => {
        // guards against a snapshot/older-render mismatch during a rolling deploy
        const w = mount(ConsultationsIndex, { props: baseProps, global: { config: { warnHandler: () => {} } } });
        expect(w.vm.canBookAnyTeam).toBe(true);
        expect(w.vm.bookableToServices).toEqual([]);
        expect(w.vm.bookingNotice).toBe(null);
    });
});
