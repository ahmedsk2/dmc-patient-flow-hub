import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PatientFlags from '@/Components/PatientFlags.vue';

const mountFlags = (patient, props = {}) =>
    mount(PatientFlags, { props: { patient, readmitWindow: 3, ...props } });

describe('PatientFlags (badge variant)', () => {
    it('renders the badges that are set', () => {
        const w = mountFlags({ is_new: true, is_tb: true, is_longterm: false, is_readmission: false });
        expect(w.text()).toContain('New');
        expect(w.text()).toContain('TB');
        expect(w.text()).not.toContain('Long-term');
    });
    it('shows the readmit window in the Readmit badge', () => {
        const w = mountFlags({ is_readmission: true }, { readmitWindow: 7 });
        expect(w.text()).toContain('Readmit ≤7d');
    });
    it('shows the Discharged date chip (badge only) and hides Disch-still-in', () => {
        const w = mountFlags({ discharged: true, discharge_date: '2026-06-01', medically_discharged: true });
        expect(w.text()).toContain('Discharged 2026-06-01');
        expect(w.text()).not.toContain('Disch. still in');
    });
    it('shows Disch-still-in when medically (not fully) discharged', () => {
        const w = mountFlags({ medically_discharged: true });
        expect(w.text()).toContain('Disch. still in');
    });
    it('renders nothing when no flags are set', () => {
        const w = mountFlags({});
        expect(w.text()).toBe('');
    });
});

describe('PatientFlags (plain variant)', () => {
    it('renders plain text spans (no pill background) and no Discharged-date chip', () => {
        const w = mountFlags({ is_new: true, discharged: true, discharge_date: '2026-06-01', medically_discharged: true }, { variant: 'plain' });
        expect(w.text()).toContain('New');
        expect(w.text()).not.toContain('Discharged 2026-06-01');
        // plain variant uses font-semibold text spans, not rounded-full pills
        expect(w.html()).not.toContain('rounded-full');
    });
    it('shows Disch-still-in in plain variant', () => {
        const w = mountFlags({ medically_discharged: true }, { variant: 'plain' });
        expect(w.text()).toContain('Disch. still in');
    });
});
