import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

let authUser;
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn(async () => true) }) }));

import RecentIndex from '@/Pages/Recent/Index.vue';

const discharges = [{ id: 1, name: 'A', mrn: '111', reversible: true, los: 3, los_band: 'mid', consultant: 'X', diagnoses: '', outcome: 'Alive' }];
const signoffs = [{ id: 9, name: 'B', mrn: '222', reversible: true, reasons: [] }];

const mountAs = (user) => {
    authUser = user;
    return mount(RecentIndex, { props: { discharges, signoffs, since: '2026-06-13' } });
};

describe('Recent/Index — undo visibility (Wave 1, Item 3)', () => {
    it('admin sees the Undo button on a reversible same-day discharge', () => {
        const w = mountAs({ role: 0, is_admin: true });
        expect(w.text()).toContain('Undo');
        expect(w.findAll('button').some((b) => b.text() === 'Undo')).toBe(true);
    });

    it('a clinical (non-admin) role does NOT see the Undo button — the view is read-only', () => {
        const w = mountAs({ role: 3, is_admin: false });
        expect(w.findAll('button').some((b) => b.text() === 'Undo')).toBe(false);
    });

    it('Observer (read-only) does NOT see the Undo button', () => {
        const w = mountAs({ role: 5, is_admin: false });
        expect(w.findAll('button').some((b) => b.text() === 'Undo')).toBe(false);
    });
});
