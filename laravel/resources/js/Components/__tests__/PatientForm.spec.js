import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import PatientForm from '@/Components/PatientForm.vue';

// IcdTypeahead does a fetch on mount-watch only when typing; stub it to avoid network.
const stubs = { IcdTypeahead: { template: '<div class="icd-stub" />' } };

const makeForm = (over = {}) => reactive({
    mrn: '111', name: 'Ali', age: 40, gender: 'Male', nationality: 'Saudi Arabia', bed: '5A',
    admit_date: '2026-06-01', admitted_from: 'ER', current_location: 'Ward', consultant_id: '',
    diagnoses: [], errors: {}, ...over,
});

const mountForm = (props = {}) =>
    mount(PatientForm, {
        props: {
            form: makeForm(),
            selectedDx: [],
            countries: ['Saudi Arabia', 'Egypt'],
            today: '2026-06-14',
            ...props,
        },
        global: { stubs },
    });

describe('PatientForm', () => {
    it('renders the canonical nationality SELECT (not a free-text input)', () => {
        const w = mountForm();
        const sel = w.findAll('select').find((s) => s.findAll('option').some((o) => o.text() === 'Egypt'));
        expect(sel).toBeTruthy();
    });

    it('renders a "(legacy)" option when the nationality is not in countries', () => {
        const w = mountForm({ form: makeForm({ nationality: 'Atlantis' }) });
        expect(w.html()).toContain('Atlantis (legacy)');
    });

    it('hides the consultant field when no consultants prop is given', () => {
        const w = mountForm();
        expect(w.html()).not.toContain('Consultant');
    });

    it('shows the consultant field (on-service + keep-current) when consultants provided', () => {
        const w = mountForm({
            form: makeForm({ consultant_id: 2 }),
            consultants: [
                { id: 1, name: 'On', on_service: true },
                { id: 2, name: 'OffKept', on_service: false },
                { id: 3, name: 'OffDropped', on_service: false },
            ],
        });
        expect(w.html()).toContain('Consultant');
        expect(w.html()).toContain('On');
        expect(w.html()).toContain('OffKept (off service)');   // keepId keeps the current assignee
        expect(w.html()).not.toContain('OffDropped');
    });

    it('renders the IcdTypeahead + a DxChips removable list', async () => {
        const w = mountForm({ selectedDx: [{ code: 'A00', name: 'Cholera' }] });
        expect(w.find('.icd-stub').exists()).toBe(true);
        expect(w.text()).toContain('A00');
        // removable chips expose a × button → clicking emits remove-dx
        const removeBtn = w.findAll('button').find((b) => b.text() === '✕');
        expect(removeBtn).toBeTruthy();
        await removeBtn.trigger('click');
        expect(w.emitted('remove-dx')[0]).toEqual(['A00']);
    });
});

// Wave 3, Item 4: every field carries a stable id + matching <label for>, and an aria-describedby
// pointing at its own error message when one is present. ErrorSummary sits at the top, built from
// the SAME form.errors map, so a save-failure focus-jumps straight to the field.
describe('PatientForm — ids, aria-describedby, and the ErrorSummary wiring', () => {
    it('every labelled field has a matching id/for pair', () => {
        const w = mountForm();
        for (const label of w.findAll('label')) {
            const forId = label.attributes('for');
            if (!forId) continue;   // the Diagnoses label has no target input of its own
            expect(w.find(`#${forId}`).exists()).toBe(true);
        }
    });

    it('renders no ErrorSummary when form.errors is empty', () => {
        const w = mountForm();
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('renders the ErrorSummary mapped onto the SAME ids as the field-level errors, wired via aria-describedby', () => {
        const w = mountForm({ form: makeForm({ errors: { mrn: 'Enter an MRN using digits only' } }) });
        const alert = w.get('[role="alert"]');
        const link = alert.get('a');
        const href = link.attributes('href').slice(1);   // strip '#'
        const target = w.get(`#${href}`);
        expect(target.attributes('inputmode')).toBe('numeric');   // resolves to the MRN input
        expect(target.attributes('aria-describedby')).toBe(`${href}-err`);
        expect(w.find(`#${href}-err`).text()).toBe('Enter an MRN using digits only');
    });
});
