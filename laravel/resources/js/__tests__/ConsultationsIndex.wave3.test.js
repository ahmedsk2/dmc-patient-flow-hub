import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 3 additions to Consultations/Index.vue: the unsaved-changes guard (dirty prop + guarded
// Cancel), the ErrorSummary wiring (form.errors mapped onto per-field ids), and the double-submit
// guard. A FULL mount (not shallow) so BaseModal's slot content — the actual form fields and the
// ErrorSummary — is inspectable; BaseModal itself is stubbed to a slot-rendering shell (its own
// behavior is covered by BaseModal.spec.js).

// `get` is exported from the mock (it used to be an anonymous inline vi.fn) so a spec can assert the
// request a control actually makes, not just the local ref it set.
const { get, post, put, deleteFn, ask } = vi.hoisted(() => ({
    get: vi.fn(), post: vi.fn(), put: vi.fn(), deleteFn: vi.fn(), ask: vi.fn(),
}));
let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get, post, delete: deleteFn, on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
    // Inertia's real form.post(url, options) transmits form.data(); the old mock forwarded only the
    // arguments it was handed, so a spec could assert a URL and call that "posts the payload" while
    // the payload itself was never observed. This mock sends the form's CURRENT field values as the
    // second argument, exactly like the real thing, so the assertions can be about the request.
    useForm: (obj) => {
        const keys = Object.keys(obj);
        const data = () => Object.fromEntries(keys.map((k) => [k, form[k]]));
        const form = reactive({
            ...obj, errors: {}, processing: false,
            post: vi.fn((url, opts) => post(url, data(), opts)),
            put: vi.fn((url, opts) => put(url, data(), opts)),
            reset: vi.fn(), clearErrors: vi.fn(),
        });

        return form;
    },
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

const admin = { role: 0, is_admin: true, id: 1, can: { manage: true } };
// a registrar with NO can_manage who is not the receiving consultant — the shape both a plain
// member of the owning specialty and a consultation coordinator present to the client (neither the
// specialty nor the coordinator flag is shared with the front end; the per-row can_modify is)
const registrar = { role: 2, is_admin: false, id: 9, can: { manage: false } };
// prod data really does contain observers carrying can_manage — read-only must survive that
const observer = { role: 5, is_admin: false, id: 8, can: { manage: true } };
const props = {
    consultations: { data: [], total: 0, last_page: 1, links: [] },
    filters: {}, stats: { new: 2, active: 3, ongoing: 4, signed_off: 5, total: 14, open: 9, mine_open: 1 },
    reasons: [], consultants: [], specialties: [],
};
const mountWith = () => { authUser = admin; return mount(ConsultationsIndex, { props }); };
// mounts the page around a given set of rows (and, optionally, a given viewer)
const row = (extra = {}) => ({ id: 1, name: 'A', mrn: '1', reasons: [], status: 'active', open_days: 2, signoff: null, indication_ids: [], can_modify: true, ...extra });
const mountRows = (data, user = admin) => {
    authUser = user;
    return mount(ConsultationsIndex, { props: { ...props, consultations: { data, total: data.length, last_page: 1, links: [] } } });
};

beforeEach(() => { get.mockClear(); post.mockClear(); put.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Consultations/Index — new-consultation modal: dirty guard + ErrorSummary', () => {
    it('clean form: Cancel closes immediately, no ask()', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();
        expect(w.vm.cForm.isDirty).toBeFalsy();
        w.vm.closeAdd();
        await w.vm.$nextTick();
        expect(ask).not.toHaveBeenCalled();
        expect(w.vm.showAdd).toBe(false);
    });

    it('dirty form: Cancel asks (danger) before closing', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeAdd();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.vm.showAdd).toBe(false);
    });

    it('dirty form: declining keeps the modal open', async () => {
        ask.mockResolvedValue(false);
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeAdd();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(w.vm.showAdd).toBe(true);
    });

    it('renders no ErrorSummary until the form has errors, then maps errors onto real field ids', async () => {
        const w = mountWith();
        w.vm.openAdd();
        await w.vm.$nextTick();
        expect(w.find('[role="alert"]').exists()).toBe(false);

        w.vm.cForm.errors = { mrn: 'Enter an MRN using digits only' };
        await w.vm.$nextTick();
        const alert = w.get('[role="alert"]');
        const href = alert.get('a').attributes('href').slice(1);
        const target = w.get(`#${href}`);
        expect(target.attributes('aria-describedby')).toBe(`${href}-err`);
    });

    it('double-submit guard: submitAdd no-ops while cForm.processing is true', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.mrn = '111';
        w.vm.cForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitAdd();
        expect(post).not.toHaveBeenCalled();
    });

    it('the Create button is disabled while processing', async () => {
        const w = mountWith();
        w.vm.openAdd();
        w.vm.cForm.processing = true;
        await w.vm.$nextTick();
        const btn = w.findAll('button').find((b) => b.text() === 'Create consultation');
        expect(btn.attributes('disabled')).toBeDefined();
    });
});

describe('Consultations/Index — edit-consultation modal: dirty guard + ErrorSummary', () => {
    const consult = { id: 7, mrn: '111', name: 'Ali', age: 40, bed: '5A', location: 'Ward', date: '2026-06-01', from: 'ER', to: 'Cardio', consultant_id: 5, indication_ids: [], other: '' };

    it('clean form: Cancel closes immediately, no ask()', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        await w.vm.$nextTick();
        w.vm.closeEdit();
        await w.vm.$nextTick();
        expect(ask).not.toHaveBeenCalled();
        expect(w.vm.editing).toBe(null);
    });

    it('dirty form: Cancel asks before closing', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        w.vm.openEdit(consult);
        w.vm.eForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeEdit();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(w.vm.editing).toBe(null);
    });

    it('maps eForm.errors onto ids that resolve to real inputs', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        w.vm.eForm.errors = { bed: 'Enter a bed' };
        await w.vm.$nextTick();
        const alert = w.get('[role="alert"]');
        const href = alert.get('a').attributes('href').slice(1);
        expect(w.get(`#${href}`).element.tagName).toBe('INPUT');
    });

    it('double-submit guard: submitEdit no-ops while eForm.processing is true', async () => {
        const w = mountWith();
        w.vm.openEdit(consult);
        w.vm.eForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitEdit();
        expect(put).not.toHaveBeenCalled();
    });
});

describe('Consultations/Index — deleteConsult copy (Wave 3, Item 6)', () => {
    // W0 (consultation ledger): this used to pin the old "permanently … cannot be undone" copy,
    // which was a lie — the delete is a soft delete restorable from Recently Deleted. Updated in
    // lockstep with the corrected copy in ConsultationsIndex.w0DeleteCopy.test.js.
    it('states patient name + MRN and the exact effect', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        await w.vm.deleteConsult({ id: 7, name: 'Ali', mrn: '111', date: '2026-06-01' });
        const [title, body, tone] = ask.mock.calls[0];
        expect(title).toMatch(/remove/i);
        expect(body).toContain('Ali');
        expect(body).toContain('111');
        expect(body).toMatch(/restore/i);
        expect(body).not.toMatch(/cannot be undone/i);
        expect(tone).toBe('danger');
    });
});

describe('Consultations/Index — W2A: four status tabs with live counts', () => {
    // deliberately updated (never deleted): the four states are unchanged, but a bare "Active" /
    // "Ongoing" pair is exactly the confusion this ledger cannot afford — every label now carries
    // the follow-up obligation the state actually means.
    it('renders exactly the four states, each carrying its count and its follow-up obligation', () => {
        const w = mountWith();
        const labels = w.findAll('[data-status-tab]').map((b) => b.text());
        expect(labels).toEqual(['New 2', 'Active · daily F/U 3', 'Ongoing · no daily F/U 4', 'Signed off 5']);
    });

    // this used to assert only `w.vm.status` — deleting the apply() call left it green while the
    // list silently kept showing the previous tab's rows
    it('clicking a tab re-queries the SERVER with that status', async () => {
        const w = mountWith();
        await w.findAll('[data-status-tab]')[2].trigger('click');
        expect(w.vm.status).toBe('ongoing');
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0]).toBe('/consultations');
        expect(get.mock.calls[0][1]).toEqual({ status: 'ongoing', scope: undefined });
        expect(get.mock.calls[0][2]).toEqual(expect.objectContaining({ preserveState: true, replace: true, preserveScroll: true }));
    });

    it('shows the ageing of an open consult and nothing for a signed-off one', () => {
        const w = mount(ConsultationsIndex, {
            props: {
                ...props,
                consultations: {
                    data: [
                        { id: 1, name: 'A', mrn: '1', reasons: [], status: 'ongoing', open_days: 6, signoff: null, indication_ids: [] },
                        { id: 2, name: 'B', mrn: '2', reasons: [], status: 'signed_off', open_days: null, signoff: '2026-08-20', indication_ids: [] },
                    ],
                    total: 2, last_page: 1, links: [],
                },
            },
        });
        const cells = w.findAll('[data-open-days]').map((c) => c.text());
        expect(cells).toEqual(['open 6 days', '—']);
    });
});

describe('Consultations/Index — W2A: sign-off response modal', () => {
    const row = { id: 7, name: 'Ali', mrn: '111', status: 'active', open_days: 2, signoff: null, reasons: [], indication_ids: [] };

    // the ONE clinically meaningful payload in this feature: what the team advised, whether anyone
    // still owes the patient a follow-up, and the note. Asserting the URL alone let all three be
    // dropped without a single red test.
    it('submitting posts the disposition, follow-up flag and note to the sign-off route', async () => {
        const w = mountWith();
        w.vm.openSignoff(row);
        await w.vm.$nextTick();
        w.vm.sForm.response_disposition = 'advice_given';
        w.vm.sForm.response_followup_needed = true;
        w.vm.sForm.response_note = 'Repeat echo in 6 weeks.';
        w.vm.submitSignoff();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/consultations/7/signoff');
        expect(post.mock.calls[0][1]).toEqual({
            response_disposition: 'advice_given',
            response_followup_needed: true,
            response_note: 'Repeat echo in 6 weeks.',
        });
        expect(post.mock.calls[0][2]).toEqual(expect.objectContaining({ preserveScroll: true }));
    });

    it('double-submit guard: submitSignoff no-ops while processing', async () => {
        const w = mountWith();
        w.vm.openSignoff(row);
        w.vm.sForm.processing = true;
        await w.vm.$nextTick();
        w.vm.submitSignoff();
        expect(post).not.toHaveBeenCalled();
    });

    it('dirty form: Cancel asks (danger) before discarding the response', async () => {
        ask.mockResolvedValue(true);
        const w = mountWith();
        w.vm.openSignoff(row);
        w.vm.sForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeSignoff();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.vm.signingOff).toBe(null);
    });

    it('maps sForm.errors onto an id that resolves to a real control', async () => {
        const w = mountWith();
        w.vm.openSignoff(row);
        w.vm.sForm.errors = { response_disposition: 'Record what the team advised before signing off.' };
        await w.vm.$nextTick();
        const alert = w.get('[role="alert"]');
        const href = alert.get('a').attributes('href').slice(1);
        expect(w.get(`#${href}`).element.tagName).toBe('SELECT');
    });

    // I3: the note is a bookkeeping line, NOT the medical record. "What the team advised" invites a
    // clinician to type the consultation note into a ledger that is not the HIS.
    it('the note placeholder says the clinical note stays in the HIS', async () => {
        const w = mountWith();
        w.vm.openSignoff(row);
        await w.vm.$nextTick();
        expect(w.get('textarea').attributes('placeholder'))
            .toBe('Working note — the clinical note stays in the HIS');
    });
});

describe('Consultations/Index — the follow-up obligation is spelled out, never implied', () => {
    // "active" and "ongoing" read as near-synonyms in plain English (and "ongoing" can read as MORE
    // engaged), while here they mean opposite things: active = a daily follow-up is owed, ongoing =
    // on the books with no daily commitment. A clinician who inverts them drops a patient who needs
    // daily review off the team's must-see list — the exact failure this project has been burned by.
    it('gives every tab an accessible name that spells the obligation and separates the count', () => {
        const w = mountWith();
        const names = w.findAll('[data-status-tab]').map((b) => b.attributes('aria-label'));
        expect(names).toEqual([
            'New, awaiting triage — 2 consultations',
            'Active, daily follow-up owed — 3 consultations',
            'Ongoing, no daily follow-up — 4 consultations',
            'Signed off — 5 consultations',
        ]);
    });

    it('the status filter is a group of aria-pressed toggles, not a half-built tablist', () => {
        const w = mountWith();
        expect(w.find('[role="tablist"]').exists()).toBe(false);
        expect(w.find('[role="tab"]').exists()).toBe(false);
        const btns = w.findAll('[data-status-tab]');
        expect(btns.map((b) => b.attributes('type'))).toEqual(['button', 'button', 'button', 'button']);
        expect(btns.map((b) => b.attributes('aria-pressed'))).toEqual(['true', 'false', 'false', 'false']);
    });

    it('spells the obligation on the row badge', () => {
        const badges = (rows) => mountRows(rows).findAll('[data-status-badge]').map((b) => b.text());
        expect(badges([row({ status: 'active' })])).toEqual(['Active · daily F/U']);
        expect(badges([row({ status: 'ongoing' })])).toEqual(['Ongoing · no daily F/U']);
    });

    it('spells the obligation on the move buttons and in their titles', () => {
        const w = mountRows([row({ status: 'new' })]);
        const moves = w.findAll('[data-status-move]');
        expect(moves.map((b) => b.text())).toEqual(['→ Active · daily F/U', '→ Ongoing · no daily F/U']);
        expect(moves.map((b) => b.attributes('title'))).toEqual([
            'Move to Active — a daily follow-up is owed on this patient',
            'Move to Ongoing — on the books, no daily follow-up commitment',
        ]);
    });

    it('labels the unfiltered headline counters so they cannot be read against the filtered total', () => {
        const w = mountWith();
        const chips = w.findAll('[data-stat-chip]').map((c) => c.text());
        expect(chips[0]).toContain('Open (all)');
        expect(chips[1]).toContain('Total in view');
        expect(w.findAll('[data-stat-chip]')[0].attributes('title')).toMatch(/search/i);
    });
});

describe('Consultations/Index — ageing and status-badge polish', () => {
    it('pluralises the ageing, formats a long stay readably and says "today" for day zero', () => {
        const w = mountRows([
            row({ id: 1, open_days: 1 }), row({ id: 2, open_days: 0 }), row({ id: 3, open_days: 1834 }),
        ]);
        expect(w.findAll('[data-open-days]').map((c) => c.text()))
            .toEqual(['open 1 day', 'open today', 'open 1,834 days']);
    });

    it('an unrecognised status looks like drift, not like Active', () => {
        const badge = mountRows([row({ status: 'archived_2019' })]).get('[data-status-badge]');
        expect(badge.text()).toBe('Unknown');
        expect(badge.classes()).toContain('bg-ink-100');
        expect(badge.classes()).not.toContain('bg-tint-accent');
    });
});

describe('Consultations/Index — row controls follow the SERVER\'s own modify verdict', () => {
    // The client cannot reconstruct User::canModifyConsultation (own specialty / coordinator /
    // admin): the shared auth payload carries four capability flags and no specialty. So the verdict
    // ships per row as `can_modify`, and the row controls gate on it — never on the far narrower
    // admin/can_manage/own-consultant guess, which hid triage from the very people who own the book.
    const hasBtn = (w, label) => w.findAll('button').some((b) => b.text() === label);

    it('offers the moves and Edit to a non-manager the server would accept', () => {
        const w = mountRows([row({ status: 'new', can_modify: true, consultant_id: 999 })], registrar);
        expect(w.findAll('[data-status-move]').length).toBe(2);
        expect(hasBtn(w, 'Edit')).toBe(true);
    });

    it('withholds them when the server says the row is not modifiable', () => {
        const w = mountRows([row({ status: 'new', can_modify: false, consultant_id: 999 })], registrar);
        expect(w.findAll('[data-status-move]').length).toBe(0);
        expect(hasBtn(w, 'Edit')).toBe(false);
    });

    it('offers only the legal transitions out of each state', () => {
        const moves = (status) => mountRows([row({ status, can_modify: true })], registrar)
            .findAll('[data-status-move]').map((b) => b.text());
        expect(moves('new')).toEqual(['→ Active · daily F/U', '→ Ongoing · no daily F/U']);
        expect(moves('active')).toEqual(['→ Ongoing · no daily F/U']);
        expect(moves('ongoing')).toEqual(['→ Active · daily F/U']);
        expect(moves('signed_off')).toEqual([]);
    });

    // I8 — the wave's own invariant, and every other consultation spec mounts as admin.
    it('a coordinator-shaped viewer may triage the book but is NEVER offered sign-off', () => {
        const w = mountRows([row({ status: 'active', can_modify: true, consultant_id: 999 })], registrar);
        expect(w.findAll('[data-status-move]').length).toBe(1);
        expect(hasBtn(w, 'Sign off')).toBe(false);
    });

    it('hides sign-off on an already signed-off row, even for an admin', () => {
        const w = mountRows([row({ status: 'signed_off', signoff: '2026-08-20', open_days: null })]);
        expect(hasBtn(w, 'Sign off')).toBe(false);
    });

    it('an observer carrying can_manage gets no write control at all', () => {
        const w = mountRows([row({ status: 'active', can_modify: true, consultant_id: observer.id })], observer);
        expect(hasBtn(w, 'Sign off')).toBe(false);
        expect(hasBtn(w, 'Edit')).toBe(false);
        expect(w.findAll('[data-status-move]').length).toBe(0);
    });
});

describe('Consultations/Index — the recorded response is readable, not a tooltip', () => {
    it('shows the disposition and the outstanding-follow-up flag on a signed-off row', () => {
        const w = mountRows([row({
            status: 'signed_off', signoff: '2026-08-20', open_days: null,
            disposition: 'follow_up_arranged', followup_needed: true,
        })]);
        expect(w.get('[data-response]').text()).toBe('Follow-up arranged');
        expect(w.get('[data-followup]').text()).toBe('Follow-up needed');
    });

    it('says nothing extra when the team recorded no outstanding follow-up', () => {
        const w = mountRows([row({
            status: 'signed_off', signoff: '2026-08-20', open_days: null,
            disposition: 'advice_given', followup_needed: false,
        })]);
        expect(w.get('[data-response]').text()).toBe('Advice given');
        expect(w.find('[data-followup]').exists()).toBe(false);
    });

    it('shows neither on a row that is still open', () => {
        const w = mountRows([row({ status: 'active', disposition: null, followup_needed: null })]);
        expect(w.find('[data-response]').exists()).toBe(false);
        expect(w.find('[data-followup]').exists()).toBe(false);
    });
});

describe('Consultations/Index — status moves: double-submit guard and real feedback', () => {
    const openRow = { id: 1, status: 'new', can_modify: true };

    it('no-ops a second click while a move is already in flight', () => {
        const w = mountRows([row(openRow)], registrar);
        w.vm.moveTo(openRow, 'active');
        w.vm.moveTo(openRow, 'ongoing');
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/consultations/1/status');
        expect(post.mock.calls[0][1]).toEqual({ status: 'active' });
    });

    it('releases the guard when the request finishes', () => {
        const w = mountRows([row(openRow)], registrar);
        w.vm.moveTo(openRow, 'active');
        post.mock.calls[0][2].onFinish();
        w.vm.moveTo(openRow, 'ongoing');
        expect(post).toHaveBeenCalledTimes(2);
    });

    it('surfaces a 403 refusal inline instead of letting Inertia throw up a crash dialog', async () => {
        const w = mountRows([row(openRow)], registrar);
        w.vm.moveTo(openRow, 'active');
        const handled = post.mock.calls[0][2].onHttpException({ status: 403, data: {} });
        await w.vm.$nextTick();
        expect(handled).toBe(false);
        expect(w.get('[data-move-error]').text()).toMatch(/not allowed|may not/i);
    });

    it('shows the 422 the status endpoint actually sent', async () => {
        const w = mountRows([row(openRow)], registrar);
        w.vm.moveTo(openRow, 'ongoing');
        post.mock.calls[0][2].onHttpException({
            status: 422,
            data: { message: 'A signed-off consultation cannot be moved. An admin must reverse the sign-off first.' },
        });
        await w.vm.$nextTick();
        expect(w.get('[data-move-error]').text()).toContain('cannot be moved');
    });

    it('surfaces a validation error delivered the ordinary Inertia way', async () => {
        const w = mountRows([row(openRow)], registrar);
        w.vm.moveTo(openRow, 'active');
        post.mock.calls[0][2].onError({ status: 'A consultation cannot move from active to active.' });
        await w.vm.$nextTick();
        expect(w.get('[data-move-error]').text()).toContain('cannot move from active to active');
    });

    it('renders no error region until something is actually refused', () => {
        const w = mountRows([row(openRow)], registrar);
        expect(w.find('[data-move-error]').exists()).toBe(false);
    });
});
