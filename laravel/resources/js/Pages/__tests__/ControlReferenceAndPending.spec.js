import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Control/Index — 2026-09-24 role/UX review, g7-control-admin fixes:
 *   #2  Reference tab: rename + delete for specialties/consultation-reasons (previously add-only).
 *   #17 Users tab: capability checkboxes now carry an InfoTip explaining each one.
 *   #18 Users tab: a never-activated self-registration shows "Awaiting activation" + its
 *       registered date, distinct from a plain admin-deactivated account.
 */
const { put, post, deleteFn, ask } = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), deleteFn: vi.fn(), ask: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, visit: vi.fn() },
    useForm: (obj) => ({
        ...obj, errors: {}, processing: false, isDirty: false,
        put: vi.fn((...a) => put(...a)),
        post: vi.fn((...a) => post(...a)),
        reset: vi.fn(), clearErrors: vi.fn(), defaults: vi.fn(),
    }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/BaseModal.vue', () => ({ default: { name: 'BaseModal', props: ['open', 'title', 'subtitle', 'size', 'closable', 'dirty'], template: '<div v-if="open"><slot /></div>' } }));

import ControlIndex from '@/Pages/Control/Index.vue';

const roles = { 0: 'Admin', 2: 'Registrar', 3: 'Consultant', 4: 'Resident', 5: 'Observer' };
const counts = { users: 1, active_users: 1, patients: 0, admissions: 0, consultations: 0, icd10: 0, specialties: 0 };
const settings = { min_hospitalist: 6, max_hospitalist: 30, min_subs: 7, max_subs: 7, short_los: 5, long_los: 11 };

beforeEach(() => { put.mockClear(); post.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

/* ---------------------------------------------------------------- #2: reference-data rename/delete ---------------------------------------------------------------- */

describe('Control/Index — Reference tab rename/delete (Problem #2)', () => {
    const specialties = [{ id: 5, name: 'Cardiologi', is_external: false }];
    const reasons = [{ id: 9, name: 'Sepsis review' }];
    const mountReference = () => mount(ControlIndex, {
        props: { settings, users: [], roles, counts, specialties, reasons, settingHistory: [], reportRecipients: [] },
    });
    const gotoReference = async (w) => {
        const tab = w.findAll('[role="tab"]').find((t) => t.text().startsWith('Reference'));
        await tab.trigger('click');
    };

    it('renaming a specialty PUTs the new name to its own route', async () => {
        const w = mountReference();
        await gotoReference(w);

        await w.findAll('button').find((b) => b.text() === 'Rename').trigger('click');
        const input = w.find('input[aria-label="Rename specialty"]');
        expect(input.element.value).toBe('Cardiologi');
        await input.setValue('Cardiology');
        await w.findAll('button').find((b) => b.text() === 'Save').trigger('click');

        expect(put).toHaveBeenCalledWith('/control/specialties/5', expect.objectContaining({ preserveScroll: true }));
    });

    it('deleting a specialty asks first (danger tone) and only calls router.delete once confirmed', async () => {
        const w = mountReference();
        await gotoReference(w);
        ask.mockResolvedValue(true);

        await w.findAll('button').find((b) => b.text() === 'Delete').trigger('click');
        await flushPromises();

        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(deleteFn).toHaveBeenCalledWith('/control/specialties/5', expect.objectContaining({ preserveScroll: true }));
    });

    it('declining the delete confirm leaves the specialty untouched', async () => {
        const w = mountReference();
        await gotoReference(w);
        ask.mockResolvedValue(false);

        await w.findAll('button').find((b) => b.text() === 'Delete').trigger('click');
        await flushPromises();

        expect(deleteFn).not.toHaveBeenCalled();
    });

    it('renaming a consultation indication PUTs its own route', async () => {
        const w = mountReference();
        await gotoReference(w);

        // second list on the page — the reasons list's own Rename button
        const renameButtons = w.findAll('button').filter((b) => b.text() === 'Rename');
        expect(renameButtons.length).toBeGreaterThanOrEqual(1);
        await renameButtons[renameButtons.length - 1].trigger('click');
        const input = w.find('input[aria-label="Rename indication"]');
        await input.setValue('Sepsis workup');
        await w.findAll('button').find((b) => b.text() === 'Save').trigger('click');

        expect(put).toHaveBeenCalledWith('/control/reasons/9', expect.objectContaining({ preserveScroll: true }));
    });

    /**
     * Review fix (minor): the newly-revealed rename <input> got no focus, so a keyboard/screen-reader
     * user had to Tab to find it after activating "Rename". Both inputs sit lexically inside a
     * `v-for`, so Vue collects their template ref into a one-element ARRAY rather than the element
     * itself — the fix (and this assertion) must unwrap that array, not assume a bare element.
     */
    it('moves focus into the specialty rename input as soon as it appears', async () => {
        const w = mount(ControlIndex, {
            props: { settings, users: [], roles, counts, specialties, reasons, settingHistory: [], reportRecipients: [] },
            attachTo: document.body,
        });
        await gotoReference(w);

        await w.findAll('button').find((b) => b.text() === 'Rename').trigger('click');
        await flushPromises();
        const input = w.find('input[aria-label="Rename specialty"]');
        expect(document.activeElement).toBe(input.element);
        w.unmount();
    });

    it('moves focus into the consultation-indication rename input as soon as it appears', async () => {
        const w = mount(ControlIndex, {
            props: { settings, users: [], roles, counts, specialties, reasons, settingHistory: [], reportRecipients: [] },
            attachTo: document.body,
        });
        await gotoReference(w);

        const renameButtons = w.findAll('button').filter((b) => b.text() === 'Rename');
        await renameButtons[renameButtons.length - 1].trigger('click');
        await flushPromises();
        const input = w.find('input[aria-label="Rename indication"]');
        expect(document.activeElement).toBe(input.element);
        w.unmount();
    });
});

/* ---------------------------------------------------------------- #17: capability checkboxes carry an InfoTip ---------------------------------------------------------------- */

describe('Control/Index — capability checkboxes are explained (Problem #17)', () => {
    const user = { id: 3, name: 'Dr X', username: 'drx', email: 'x@dmc-im.com', role: 3, active: true, on_service: true,
        specialty_id: '', can: { assign: false, add: false, manage: false, modify: false } };

    it('every capability checkbox and On service carry a "More information" InfoTip button', async () => {
        const w = mount(ControlIndex, {
            props: { settings, users: [], roles, counts, specialties: [], reasons: [], settingHistory: [], reportRecipients: [] },
        });
        w.vm.editUser(user);
        await w.vm.$nextTick();

        for (const label of ['Can assign', 'Can add', 'Can manage', 'Can modify', 'Can coordinate consults', 'On service']) {
            const btn = w.find(`button[aria-label="More information: ${label}"]`);
            expect(btn.exists(), `expected an InfoTip for "${label}"`).toBe(true);
        }
    });
});

/* ---------------------------------------------------------------- #18: pending self-registration badge ---------------------------------------------------------------- */

describe('Control/Index — pending self-registration is distinct from a deactivation (Problem #18)', () => {
    const baseUser = (over) => ({ id: 1, name: 'X', full_name: 'X', username: 'x', email: 'x@d.com', role: 5,
        role_label: 'Observer', active: false, on_service: false, specialty_id: null, mfa: false,
        can: { assign: false, add: false, manage: false, modify: false }, pending_registration: false, registered_at: null, ...over });

    // Scoped to the user's own table row — the page also carries the words "Disabled" (a status
    // filter <option>) and "Awaiting activation" (the Users-tab note added for #31) unconditionally,
    // so a whole-page text() assertion would pass/fail for the wrong reason.
    const rowFor = (w, name) => w.findAll('tbody tr').find((r) => r.text().includes(name));

    it('shows "Awaiting activation" + the registered date for a pending self-registration', () => {
        const w = mount(ControlIndex, {
            props: {
                settings, roles, counts, specialties: [], reasons: [], settingHistory: [], reportRecipients: [],
                users: [baseUser({ id: 10, name: 'New Sign-up', pending_registration: true, registered_at: '2026-09-20T10:00:00+00:00' })],
            },
        });
        const row = rowFor(w, 'New Sign-up');
        expect(row.text()).toContain('Awaiting activation');
        expect(row.text()).toContain('Registered 2026-09-20');
        expect(row.text()).not.toContain('Disabled');
    });

    it('still shows a plain "Disabled" badge for an ordinary deactivated account', () => {
        const w = mount(ControlIndex, {
            props: {
                settings, roles, counts, specialties: [], reasons: [], settingHistory: [], reportRecipients: [],
                users: [baseUser({ id: 11, name: 'Deactivated Staffer', pending_registration: false })],
            },
        });
        const row = rowFor(w, 'Deactivated Staffer');
        expect(row.text()).toContain('Disabled');
        expect(row.text()).not.toContain('Awaiting activation');
    });
});
