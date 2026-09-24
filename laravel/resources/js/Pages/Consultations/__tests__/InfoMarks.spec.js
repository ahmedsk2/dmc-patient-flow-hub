import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Role/UX review 2026-09-24, section 5 "Consultations": the four statuses, the "To service" field
// (see ToServiceBooking.spec.js), the Consultation Dashboard "Today: due / seen" figures, the
// sign-off response note, and the coordinator-only specialty picker all get an inline "!" InfoTip.
// These specs pin the EXACT wording shipped, since the review explicitly warns that "active" and
// "ongoing" read as near-synonyms in plain English but mean opposite things in this app (active
// owes a daily follow-up, ongoing does not) — the tooltip text must say so correctly, not repeat
// the ambiguity.

let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    useForm: (obj) => ({ ...obj, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn() }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({
    default: {
        props: ['open', 'title', 'subtitle', 'size', 'tall', 'fieldFirst', 'closable', 'dirty'],
        emits: ['close'],
        template: '<div v-if="open"><slot /></div>',
    },
}));
vi.mock('@/composables/useChartTheme', () => ({
    useChartTheme: () => ({
        gridColor: { value: '#000' }, axisColor: { value: '#000' },
        series: { value: { primary: '#009ca6', deep: '#00565e' } },
    }),
}));

import ConsultationsIndex from '@/Pages/Consultations/Index.vue';
import ConsultationsDashboard from '@/Pages/Consultations/Dashboard.vue';
import InfoTip from '@/Components/InfoTip.vue';

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
const indexProps = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { new: 0, active: 0, ongoing: 0, signed_off: 0, total: 0, open: 0, mine_open: 0 },
    reasons: [], consultants: [], specialties: [],
    worklist: { date: '2026-09-24', seen: 0, total: 0, items: [] },
    canBookAnyTeam: true, bookableToServices: [], bookingNotice: null,
};

describe('Consultations/Index — "!" info marks', () => {
    it('explains the four-state lifecycle without repeating the active/ongoing ambiguity', () => {
        authUser = admin;
        const w = mount(ConsultationsIndex, { props: indexProps });
        const tips = w.findAllComponents(InfoTip);
        const statusTip = tips.find((t) => t.props('label') === 'Consultation status');
        expect(statusTip).toBeTruthy();
        const text = statusTip.props('text');
        expect(text).toContain('Active = a daily follow-up is owed');
        expect(text).toContain('Ongoing = on the books, no daily follow-up owed');
        // must state the obligation for BOTH — a reader skimming only the labels must not come away
        // thinking "ongoing" sounds more actively worked than "active" (the exact confusion named in
        // the review and in the page's own STATE_TITLE comment)
        expect(text).not.toMatch(/ongoing.*under continued follow-up/i);
    });

    it('spells out HIS on the sign-off response note', async () => {
        authUser = admin;
        const w = mount(ConsultationsIndex, { props: indexProps });
        w.vm.openSignoff({ id: 7, name: 'Pt', mrn: '1', bed: 'W-1', status: 'active' });
        await w.vm.$nextTick();
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Response note');
        expect(tip).toBeTruthy();
        expect(tip.props('text')).toContain("hospital's main record system (HIS)");
    });
});

describe('Consultations/Dashboard — "!" info marks', () => {
    const dashProps = {
        canPick: true,
        filters: { specialty_id: null }, specialties: [{ id: 2, name: 'Cardiology' }],
        scopeLabel: 'All specialties',
        openCounts: { new: 1, active: 4, ongoing: 2, total: 7 },
        ageing: { b0_2: 3, b3_7: 2, b8_plus: 1, unknown: 1 },
        today: { due: 4, seen: 3 },
        turnaround: { first_followup_hours: 2.5, first_followup_n: 12, signoff_hours: 30.0, signoff_n: 9, legacy_excluded: 1283, from_cutover: true },
        trend: { labels: [], data: [] },
        topIndications: [], perConsultant: [],
        generatedAt: '09:41',
    };

    it('explains the Due/Seen figures without claiming they are only "yours" (also true for an admin viewing every team)', () => {
        authUser = { role: 0, is_admin: true };
        const w = mount(ConsultationsDashboard, { props: dashProps, global: { stubs: { ChartCanvas: true } } });
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === "Today's follow-ups");
        expect(tip).toBeTruthy();
        expect(tip.props('text')).toContain('Due =');
        expect(tip.props('text')).toContain('Seen =');
        expect(tip.props('text').toLowerCase()).not.toContain('your active');
    });

    it('explains the specialty picker is coordinator/admin-only', () => {
        authUser = { role: 0, is_admin: true };
        const w = mount(ConsultationsDashboard, { props: dashProps, global: { stubs: { ChartCanvas: true } } });
        const tip = w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Specialty picker');
        expect(tip).toBeTruthy();
        expect(tip.props('text')).toMatch(/admin/i);
        expect(tip.props('text')).toMatch(/coordinator/i);
    });

    it('renders no specialty-picker info mark for a viewer who cannot pick', () => {
        authUser = { role: 3, is_admin: false };
        const w = mount(ConsultationsDashboard, { props: { ...dashProps, canPick: false }, global: { stubs: { ChartCanvas: true } } });
        expect(w.findAllComponents(InfoTip).find((t) => t.props('label') === 'Specialty picker')).toBeFalsy();
    });
});
