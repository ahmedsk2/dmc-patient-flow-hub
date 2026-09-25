import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Role walkthrough 2026-09-25 (U4). After Preview, the primary button used to read
// "Confirm import (5 rows)" — counting every row in the editable table, invalid ones included —
// while the confirm dialog it opens says "Write 3 admission row(s)…" seconds later. The button now
// counts only what will actually be written, and names the skipped count.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => ({ ...obj, post: vi.fn(), processing: false, errors: {} }),
}));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask: vi.fn(() => Promise.resolve(false)) }) }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

import ImportIndex from '@/Pages/Import/Index.vue';

const columns = ['MRN', 'Name'];

const row = (line, ok, overrides = {}) => ({
    line, ok, mrn: `${1000 + line}`, name: `Row ${line}`, age: 40, gender: 'M', nationality: 'Saudi',
    admit_date: '2026-01-01', discharge_date: null, outcome: null, location: 'Ward', diagnoses: [],
    consultant_name: '', discharged_to: '', medical_discharge_date: '', admitted_from: '', bed: '',
    delay_reason: '', is_longterm: false, transfer_type: '', error: ok ? null : 'Bad row', warning: null,
    ...overrides,
});

describe('Import/Index — primary button counts only what will be written (U4)', () => {
    it('shows the valid count and the skipped count on a mixed, non-truncated preview', () => {
        const preview = { truncated: false, valid: 3, invalid: 2, sample: [row(1, true), row(2, true), row(3, true), row(4, false), row(5, false)] };
        const wrapper = mount(ImportIndex, { props: { columns, preview, rows: '' } });

        const btn = wrapper.findAll('button').find((b) => b.text().includes('Import'));
        expect(btn.text()).toBe('Import 3 valid rows (2 skipped)');
    });

    it('shows no skipped-count suffix when every row is valid', () => {
        const preview = { truncated: false, valid: 2, invalid: 0, sample: [row(1, true), row(2, true)] };
        const wrapper = mount(ImportIndex, { props: { columns, preview, rows: '' } });

        const btn = wrapper.findAll('button').find((b) => b.text().includes('Import'));
        expect(btn.text()).toBe('Import 2 valid rows');
    });

    it('uses the server-reported valid/invalid counts on a truncated (>200-row) preview', () => {
        const preview = { truncated: true, valid: 150, invalid: 50, sample: [row(1, true)] };
        const wrapper = mount(ImportIndex, { props: { columns, preview, rows: '' } });

        const btn = wrapper.findAll('button').find((b) => b.text().includes('Import'));
        expect(btn.text()).toBe('Import 150 valid rows (50 skipped)');
    });
});
