import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AdmissionSummary from '@/Components/Patients/AdmissionSummary.vue';

const patient = {
    name: 'Ali', mrn: '111', age: 40, gender: 'Male', bed: '5A',
    admitted_from: 'ER', admit_date: '2026-06-01',
    diagnoses: [{ code: 'A00', name: 'Cholera' }],
};

describe('AdmissionSummary', () => {
    it('renders the review header + every demographic field', () => {
        const w = mount(AdmissionSummary, { props: { patient } });
        expect(w.text()).toContain('Kindly review admission details');
        expect(w.text()).toContain('Ali');
        expect(w.text()).toContain('111');
        expect(w.text()).toContain('40y');
        expect(w.text()).toContain('Male');
        expect(w.text()).toContain('5A');
        expect(w.text()).toContain('ER');
        expect(w.text()).toContain('2026-06-01');
    });

    it('lists diagnoses when present', () => {
        const w = mount(AdmissionSummary, { props: { patient } });
        expect(w.text()).toContain('A00');
        expect(w.text()).toContain('Cholera');
    });

    it('omits the diagnoses list when none', () => {
        const w = mount(AdmissionSummary, { props: { patient: { ...patient, diagnoses: [] } } });
        expect(w.findAll('ul').length).toBe(0);
    });

    it('falls back to em-dash for missing fields', () => {
        const w = mount(AdmissionSummary, { props: { patient: { name: 'X' } } });
        expect(w.text()).toContain('—');
    });
});
