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

// #23 (role/UX review 2026-09-24): the chips render only the short abbreviation with no on-page
// expansion anywhere they appear (board card, handover editor, Handovers inbox) — the full wording
// already exists in CHECKPOINT_FIELDS/CODE_STATUS_OPTIONS; this wires it up as an accessible tooltip.
describe('CheckpointChips — accessible tooltips (#23)', () => {
    it('a checkpoint flag chip carries its full label as title + aria-label', () => {
        const w = mount(CheckpointChips, { props: { checkpoints: { vte_completed: true, ready_for_discharge: true } } });
        const spans = w.findAll('span');
        const vte = spans.find((s) => s.text() === 'VTE');
        const dc = spans.find((s) => s.text() === 'D/C ready');
        expect(vte.attributes('title')).toBe('VTE prophylaxis');
        expect(vte.attributes('aria-label')).toBe('VTE prophylaxis');
        expect(dc.attributes('title')).toBe('Ready for discharge');
    });

    it.each([
        ['dnr', 'DNR', 'Do-not-resuscitate'],
        ['dni', 'DNI', 'Do-not-intubate'],
        ['full', 'Full', 'Full resuscitation'],
    ])('code status %s (%s) spells out "%s"', (value, label, title) => {
        const w = mount(CheckpointChips, { props: { checkpoints: { code_status: value } } });
        const span = w.find('span');
        expect(span.text()).toBe(label);
        expect(span.attributes('title')).toBe(title);
        expect(span.attributes('aria-label')).toBe(title);
    });
});
