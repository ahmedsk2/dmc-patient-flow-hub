import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    useForm: (obj) => ({ ...obj, put: vi.fn(), post: vi.fn(), delete: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), defaults: vi.fn(), errors: {}, processing: false, isDirty: false }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Tabs.vue', () => ({ default: { name: 'Tabs', props: ['tabs', 'modelValue'], template: "<div><slot :active=\"'system'\" /></div>" } }));
vi.mock('@/Components/BaseModal.vue', () => ({ default: { name: 'BaseModal', props: ['open', 'title', 'subtitle', 'size', 'closable', 'dirty'], template: '<div v-if="open"><slot /></div>' } }));

import Control from '@/Pages/Control/Index.vue';

const settings = { min_hospitalist: 6, max_hospitalist: 30, min_subs: 7, max_subs: 7, short_los: 5, long_los: 11 };
const base = {
    settings, users: [], roles: { 0: 'Admin' }, counts: { users: 0, active_users: 0, patients: 0, admissions: 0, consultations: 0, icd10: 0, specialties: 0 },
    specialties: [], reasons: [], settingHistory: [], reportRecipients: [],
    timezones: ['UTC', 'Asia/Riyadh'],
};
const mountControl = (system) => mount(Control, { props: { ...base, system } });

describe('Control — System tab', () => {
    it('renders the three cards + a write-only password showing "Set" when one exists', () => {
        const w = mountControl({ mail_mailer: 'smtp', mail_host: 'smtp.x.com', mail_port: 587, mail_encryption: 'tls', mail_username: 'u', mail_password_set: true, mail_from_address: 'a@b.com', mail_from_name: 'DMC', app_timezone: 'UTC', app_name: 'DMC', app_url: 'https://x' });
        expect(w.text()).toContain('Email');
        expect(w.text()).toContain('Localization');
        expect(w.text()).toContain('Application');
        const pw = w.find('input[aria-label="SMTP password"]');
        expect(pw.exists()).toBe(true);
        expect(pw.attributes('type')).toBe('password');
        expect(pw.element.value).toBe('');
        expect(w.text()).toContain('Set');
        expect(w.findAll('option').some((o) => o.text() === 'Asia/Riyadh')).toBe(true);
        expect(w.findAll('button').some((b) => b.text().includes('Send test email'))).toBe(true);
    });
});
