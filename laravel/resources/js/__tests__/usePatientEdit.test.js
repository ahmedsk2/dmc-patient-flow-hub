import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// useForm is stubbed to a plain reactive-ish object exposing the bits usePatientEdit touches.
const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    useForm: (obj) => ({ ...obj, post, clearErrors: vi.fn(), errors: {}, processing: false }),
}));

import { usePatientEdit } from '@/composables/usePatientEdit';

beforeEach(() => { post.mockClear(); global.fetch = vi.fn(); });
afterEach(() => { vi.restoreAllMocks(); });

const detail = {
    mrn: '111', name: 'Ali', age: 40, gender: 'Male', nationality: 'Saudi Arabia', bed: '5A',
    admit_date: '2026-06-01', admitted_from: 'ER', current_location: 'Ward', consultant_id: 9,
    diagnoses: [{ code: 'A00', name: 'Cholera' }], activity: [{ id: 1 }],
};

describe('usePatientEdit', () => {
    it('open() fetches the edit endpoint and populates form + identity + dx + activity', async () => {
        global.fetch.mockResolvedValue({ json: () => Promise.resolve(detail) });
        const pe = usePatientEdit({ ask: vi.fn() });
        await pe.open({ id: 42 });
        expect(global.fetch.mock.calls[0][0]).toBe('/admissions/42/edit');
        expect(pe.form.mrn).toBe('111');
        expect(pe.form.consultant_id).toBe(9);
        expect(pe.form.diagnoses).toEqual(['A00']);
        expect(pe.selectedDx.value).toEqual([{ code: 'A00', name: 'Cholera' }]);
        expect(pe.editing.value).toEqual({ id: 42, mrn: '111', name: 'Ali' });
        expect(pe.activity.value).toEqual([{ id: 1 }]);
    });

    it('addDx / removeDx mutate form.diagnoses + selectedDx without duplicating', async () => {
        global.fetch.mockResolvedValue({ json: () => Promise.resolve({ ...detail, diagnoses: [] }) });
        const pe = usePatientEdit({ ask: vi.fn() });
        await pe.open({ id: 1 });
        pe.addDx({ code: 'B01', name: 'Varicella' });
        pe.addDx({ code: 'B01', name: 'Varicella' });   // duplicate ignored
        expect(pe.form.diagnoses).toEqual(['B01']);
        pe.removeDx('B01');
        expect(pe.form.diagnoses).toEqual([]);
        expect(pe.selectedDx.value).toEqual([]);
    });

    it('submit() posts the modify endpoint when identity is unchanged (no confirm)', async () => {
        global.fetch.mockResolvedValue({ json: () => Promise.resolve(detail) });
        const ask = vi.fn(() => Promise.resolve(true));
        const pe = usePatientEdit({ ask });
        await pe.open({ id: 42 });
        await pe.submit();
        expect(ask).not.toHaveBeenCalled();
        expect(post).toHaveBeenCalledWith('/admissions/42/modify', expect.objectContaining({ preserveScroll: true }));
    });

    it('submit() asks to confirm when the MRN/name identity changed', async () => {
        global.fetch.mockResolvedValue({ json: () => Promise.resolve(detail) });
        const ask = vi.fn(() => Promise.resolve(false));   // user declines
        const pe = usePatientEdit({ ask });
        await pe.open({ id: 42 });
        pe.form.mrn = '999';
        await pe.submit();
        expect(ask).toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();   // declined → no post
    });

    it('respects custom edit/submit endpoints', async () => {
        global.fetch.mockResolvedValue({ json: () => Promise.resolve(detail) });
        const pe = usePatientEdit({ ask: vi.fn(), editEndpoint: (id) => `/x/${id}/e`, submitEndpoint: (id) => `/x/${id}/s` });
        await pe.open({ id: 5 });
        expect(global.fetch.mock.calls[0][0]).toBe('/x/5/e');
        await pe.submit();
        expect(post).toHaveBeenCalledWith('/x/5/s', expect.anything());
    });
});
