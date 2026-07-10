import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

// Wave 3, Item 7: Control/Index.vue's plain-button tab switcher is retrofitted onto the committed,
// fully-accessible <Tabs> component (Tabs.spec.js/useTabs.test.js already cover the tablist/keyboard
// contract in isolation — this file proves Control wires it correctly: the four existing panels
// switch on the slot's `active` id via v-show (form state must not be destroyed), and the Settings
// tab carries a dirty badge tied to sForm.isDirty).
//
// Also covers Item 5 (double-submit) and Item 1/2 (unsaved-changes guard) for the edit-user modal,
// both introduced on this page in the same pass.

const { put, post, deleteFn, ask } = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), deleteFn: vi.fn(), ask: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), post, delete: deleteFn, visit: vi.fn() },
    useForm: (obj) => reactive({
        ...obj, errors: {}, processing: false, recentlySuccessful: false,
        put: vi.fn((...a) => put(...a)),
        post: vi.fn((...a) => post(...a)),
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

import ControlIndex from '@/Pages/Control/Index.vue';

const props = {
    settings: {
        min_hospitalist: 6, max_hospitalist: 30, min_subs: 7, max_subs: 5, short_los: 5, long_los: 11,
        ward_beds: 50, icu_beds: 10, readmission_window_days: 3, mfa_enforcement: 0,
        alert_overcensus_pct: 100, alert_boarding_max: 5, alert_readmit_rate_pct: 10, alert_deaths_delta_pct: 50,
        idle_timeout_minutes: 30, abs_timeout_minutes: 0, failed_login_notify_threshold: 5, dq_los_multiplier: 2,
    },
    users: { data: [], last_page: 1, links: [] },
    filters: {}, roles: { 0: 'Admin', 2: 'Registrar', 3: 'Consultant', 4: 'Resident', 5: 'Observer' },
    counts: { users: 1, active_users: 1, patients: 1, admissions: 1, consultations: 1, icd10: 1, specialties: 1 },
    specialties: [], reasons: [], settingHistory: [], reportRecipients: [],
};

const mountPage = () => mount(ControlIndex, { props });

beforeEach(() => { put.mockClear(); post.mockClear(); deleteFn.mockClear(); ask.mockReset(); });

describe('Control/Index — Tabs retrofit', () => {
    it('renders an accessible tablist with the four tabs, Overview active by default', () => {
        const w = mountPage();
        const tabs = w.findAll('[role="tab"]');
        expect(tabs.map((t) => t.text().replace(/\(unsaved changes\)$/, '').trim())).toEqual(['Overview', 'Settings', 'Users', 'Reference']);
        expect(tabs[0].attributes('aria-selected')).toBe('true');
    });

    it('clicking the Users tab switches the visible panel (v-show preserved, not v-if)', async () => {
        const w = mountPage();
        // Overview visible, Users hidden
        expect(w.get('.grid.grid-cols-2.gap-4').isVisible()).toBe(true);
        const usersTab = w.findAll('[role="tab"]').find((t) => t.text().includes('Users'));
        await usersTab.trigger('click');
        expect(w.get('.grid.grid-cols-2.gap-4').attributes('style')).toContain('display: none');
    });

    it('the Settings tab carries NO dirty badge while sForm is clean', () => {
        const w = mountPage();
        const settingsTab = w.findAll('[role="tab"]').find((t) => t.text().startsWith('Settings'));
        expect(settingsTab.find('[data-dirty-dot]').exists()).toBe(false);
    });

    it('the Settings tab shows the dirty badge once sForm.isDirty flips true', async () => {
        const w = mountPage();
        w.vm.sForm.isDirty = true;
        await w.vm.$nextTick();
        const settingsTab = w.findAll('[role="tab"]').find((t) => t.text().startsWith('Settings'));
        expect(settingsTab.find('[data-dirty-dot]').exists()).toBe(true);
        expect(settingsTab.attributes('aria-label')).toBe('Settings (unsaved changes)');
    });

    it('form state survives a tab switch away and back (v-show, not v-if)', async () => {
        const w = mountPage();
        w.vm.sForm.min_hospitalist = 99;
        const tabs = w.findAll('[role="tab"]');
        await tabs.find((t) => t.text().includes('Users')).trigger('click');
        await tabs.find((t) => t.text().startsWith('Settings')).trigger('click');
        expect(w.vm.sForm.min_hospitalist).toBe(99);
    });
});

describe('Control/Index — double-submit guard (Item 5)', () => {
    it('saveSettings no-ops while sForm.processing is true', () => {
        const w = mountPage();
        w.vm.sForm.processing = true;
        w.vm.saveSettings();
        expect(put).not.toHaveBeenCalled();
    });

    it('the Save settings button is disabled while processing', async () => {
        const w = mountPage();
        w.vm.sForm.processing = true;
        await w.vm.$nextTick();
        const btn = w.findAll('button').find((b) => b.text() === 'Save settings');
        expect(btn.attributes('disabled')).toBeDefined();
    });
});

describe('Control/Index — edit-user modal unsaved-changes guard (Item 1/2)', () => {
    const user = { id: 3, name: 'Dr X', username: 'drx', email: 'x@dmc-im.com', role: 3, active: true, on_service: true, specialty_id: '', can: { assign: false, add: false, manage: false, modify: false } };

    it('clean form: Cancel closes immediately, no ask()', async () => {
        const w = mountPage();
        w.vm.editUser(user);
        await w.vm.$nextTick();
        w.vm.closeEditUser();
        await w.vm.$nextTick();
        expect(ask).not.toHaveBeenCalled();
        expect(w.vm.editing).toBe(null);
    });

    it('dirty form: Cancel asks before closing', async () => {
        ask.mockResolvedValue(true);
        const w = mountPage();
        w.vm.editUser(user);
        w.vm.uForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeEditUser();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.vm.editing).toBe(null);
    });

    it('dirty form: declining keeps the modal open', async () => {
        ask.mockResolvedValue(false);
        const w = mountPage();
        w.vm.editUser(user);
        w.vm.uForm.isDirty = true;
        await w.vm.$nextTick();
        w.vm.closeEditUser();
        await w.vm.$nextTick(); await w.vm.$nextTick();
        expect(w.vm.editing).not.toBe(null);
    });
});
