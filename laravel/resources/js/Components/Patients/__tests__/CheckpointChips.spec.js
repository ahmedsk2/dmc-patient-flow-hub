import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CheckpointChips from '@/Components/Patients/CheckpointChips.vue';

// Shared chip row (spec §D4) — extracted from HandoverModal.vue so the board card, the Handovers
// inbox, and the modal itself render the SAME chips from the SAME checkpoints shape.

describe('CheckpointChips', () => {
    it('renders a chip per set flag + the code status, and not the unset ones', () => {
        const w = mount(CheckpointChips, {
            props: { checkpoints: { high_risk: true, code_status: 'dnr', vte_completed: true } },
        });
        expect(w.text()).toContain('High-risk');
        expect(w.text()).toContain('DNR');
        expect(w.text()).toContain('VTE');
        expect(w.text()).not.toContain('D/C ready');
        expect(w.text()).not.toContain('Needs workup');
        expect(w.text()).not.toContain('Workup pending');
    });

    it('renders nothing when checkpoints is null', () => {
        const w = mount(CheckpointChips, { props: { checkpoints: null } });
        expect(w.find('div').exists()).toBe(false);
    });

    it('renders nothing when checkpoints is an empty object (every flag unset)', () => {
        const w = mount(CheckpointChips, { props: { checkpoints: {} } });
        expect(w.find('div').exists()).toBe(false);
    });

    it('renders the Full code status with the brand token, not the danger token', () => {
        const w = mount(CheckpointChips, { props: { checkpoints: { code_status: 'full' } } });
        expect(w.text()).toContain('Full');
        const span = w.find('span');
        expect(span.classes()).toContain('bg-brand-100');
    });
});
