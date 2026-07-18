import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import HandoverCapture from '@/Components/Patients/HandoverCapture.vue';

const cp = { vte_completed: true, ready_for_discharge: false, high_risk: true, needs_workup: false, workup_pending: false, code_status: 'dnr' };

describe('HandoverCapture', () => {
    it('full density renders a labelled checkbox per flag plus a code-status select', () => {
        const w = mount(HandoverCapture, { props: { density: 'full', body: 'prior note', checkpoints: cp } });
        expect(w.findAll('input[type="checkbox"]')).toHaveLength(5);
        expect(w.find('select').exists()).toBe(true);
        expect(w.find('textarea').element.value).toBe('prior note');
        w.unmount();
    });

    it('compact density renders one toggle button per flag (no checkbox list)', () => {
        const w = mount(HandoverCapture, { props: { density: 'compact', body: '', checkpoints: cp } });
        expect(w.findAll('[data-cp-toggle]')).toHaveLength(5);
        expect(w.findAll('input[type="checkbox"]')).toHaveLength(0);
        w.unmount();
    });

    it('emits update:checkpoints with the flag flipped when a compact chip is clicked', async () => {
        const w = mount(HandoverCapture, { props: { density: 'compact', body: '', checkpoints: cp } });
        await w.findAll('[data-cp-toggle]')[0].trigger('click');   // vte_completed: true -> false
        expect(w.emitted('update:checkpoints').at(-1)[0].vte_completed).toBe(false);
        w.unmount();
    });

    it('tolerates a null checkpoints payload by falling back to the canonical shape', () => {
        const w = mount(HandoverCapture, { props: { density: 'full', body: '', checkpoints: null } });
        expect(w.findAll('input[type="checkbox"]').every((c) => c.element.checked === false)).toBe(true);
        w.unmount();
    });

    it('shows a stale pill when the handover was not saved today', () => {
        const w = mount(HandoverCapture, { props: { density: 'full', body: '', checkpoints: null, today: false, updatedAt: '2026-07-10T09:00:00+00:00' } });
        expect(w.text()).toContain('stale');
        w.unmount();
    });

    it('emits update:body as the note is typed', async () => {
        const w = mount(HandoverCapture, { props: { density: 'full', body: '', checkpoints: null } });
        await w.find('textarea').setValue('today note');
        expect(w.emitted('update:body').at(-1)[0]).toBe('today note');
        w.unmount();
    });

    it('hideStatus: true suppresses the status line (stale pill + last-updated/no-handover text) even with a pre-filled note', () => {
        const w = mount(HandoverCapture, {
            props: { density: 'compact', body: 'prior note', checkpoints: cp, today: false, updatedAt: null, hideStatus: true },
        });
        expect(w.text()).not.toContain('stale');
        expect(w.text()).not.toContain('No handover recorded yet');
        expect(w.text()).not.toContain('last updated');
        w.unmount();
    });

    it('hideStatus: false (default) still renders the status line', () => {
        const w = mount(HandoverCapture, {
            props: { density: 'compact', body: 'prior note', checkpoints: cp, today: false, updatedAt: null },
        });
        expect(w.text()).toContain('stale');
        expect(w.text()).toContain('No handover recorded yet');
        w.unmount();
    });
});
