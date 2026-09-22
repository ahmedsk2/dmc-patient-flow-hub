import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * ActivityPanel — the per-admission audit timeline on the edit screen (Phase 2, Item 2). It renders
 * audit_log rows the server hands it, so it is the one place a clinician READS the trail. Until
 * 2026-09-22 no spec loaded it (page specs vi.mock() it) and vitest 3 misreported it as 100% covered.
 * The contract:
 *   • an explicit empty state; every known action gets its human label, an unknown one its raw name;
 *   • destructive actions (delete / reverse / undo) read red, PHI reads (registry.*) amber, the rest
 *     neutral — on both the label and the timeline dot;
 *   • a missing actor reads "System";
 *   • details: {from,to} as a before → after diff, {added,removed} diagnoses as +/- chips, anything
 *     else as a plain value (booleans yes/no, empty values "—", objects as JSON);
 *   • audit details are DATA, rendered as text: a value that looks like markup must never become
 *     markup.
 */
import ActivityPanel from '@/Components/ActivityPanel.vue';

const item = (over = {}) => ({ id: 1, action: 'admission.create', actor: 'Dr Salem', at: '2026-09-22T08:05:00Z', details: null, ...over });
const mountWith = (items) => mount(ActivityPanel, { props: { items } });

describe('empty state', () => {
    it('says there is no recorded activity when there are no items', () => {
        expect(mountWith([]).text()).toContain('No recorded activity for this admission.');
        expect(mount(ActivityPanel).text()).toContain('No recorded activity for this admission.');
    });
});

describe('labels, actors and times', () => {
    it('labels known actions and falls back to the raw action name', () => {
        const w = mountWith([
            item({ id: 1, action: 'admission.complete_discharge' }),
            item({ id: 2, action: 'consultation.signoff' }),
            item({ id: 3, action: 'something.new' }),
        ]);
        const text = w.text();
        expect(text).toContain('Discharge completed');
        expect(text).toContain('Consultation signed off');
        expect(text).toContain('something.new');
    });

    it('shows the actor, or "System" when there is none', () => {
        const w = mountWith([item({ id: 1, actor: 'Dr Salem' }), item({ id: 2, actor: null })]);
        expect(w.text()).toContain('Dr Salem');
        expect(w.text()).toContain('System');
    });

    it('prints a time for a timestamp and nothing for a missing one', () => {
        const w = mountWith([item({ id: 1 }), item({ id: 2, at: null })]);
        const times = w.findAll('li .nums').map((s) => s.text());
        expect(times[0]).not.toBe('');
        expect(times[1]).toBe('');
    });
});

describe('tone', () => {
    const toneOf = (action) => {
        const w = mountWith([item({ action })]);
        return { label: w.get('li .font-semibold').classes(), dot: w.get('li [aria-hidden="true"]').classes() };
    };

    it('marks deletions, reversals and undos as destructive', () => {
        for (const action of ['admission.delete', 'consultation.reverse_signoff', 'admission.reverse_discharge', 'admission.undo_medical_discharge']) {
            const t = toneOf(action);
            expect(t.label, action).toContain('text-on-danger');
            expect(t.dot, action).toContain('bg-danger-500');
        }
    });

    it('marks a registry record open as a PHI read', () => {
        const t = toneOf('registry.open');
        expect(t.label).toContain('text-on-warning');
        expect(t.dot).toContain('bg-warning-500');
    });

    it('leaves ordinary actions neutral', () => {
        const t = toneOf('admission.assign');
        expect(t.label).toContain('text-ink-700');
        expect(t.dot).toContain('bg-brand-500');
    });
});

describe('details', () => {
    it('renders no details disclosure when there is nothing to show', () => {
        const w = mountWith([
            item({ id: 1, details: null }),
            item({ id: 2, details: {} }),
            item({ id: 3, details: 'a string, not an object' }),
        ]);
        expect(w.findAll('details')).toHaveLength(0);
    });

    it('shows a field change as before → after', () => {
        const w = mountWith([item({ details: { bed_number: { from: '12A', to: '14B' } } })]);
        const dd = w.get('dd');
        expect(w.get('dt').text()).toBe('bed number');          // underscores become spaces
        expect(dd.get('.line-through').text()).toBe('12A');
        expect(dd.text()).toContain('14B');
    });

    it('renders empty "from"/"to" as a dash and booleans as yes/no', () => {
        const w = mountWith([item({ details: { consultant: { from: null, to: 'Dr B' }, is_longterm: { from: false, to: true } } })]);
        const dds = w.findAll('dd').map((d) => d.text());
        expect(dds[0]).toContain('—');
        expect(dds[0]).toContain('Dr B');
        expect(dds[1]).toContain('no');
        expect(dds[1]).toContain('yes');
    });

    it('shows diagnosis changes as added and removed chips', () => {
        const w = mountWith([item({ details: { diagnoses: { added: ['E11', 'I10'], removed: ['J18'] } } })]);
        const chips = w.findAll('dd span').map((s) => s.text());
        expect(chips).toEqual(['+E11', '+I10', 'J18']);
        expect(w.findAll('dd span.line-through').map((s) => s.text())).toEqual(['J18']);
    });

    it('shows other values plainly, objects as JSON and empty strings as a dash', () => {
        const w = mountWith([item({ details: { reason: 'Transfer to CCU', count: 0, extra: { a: 1 }, note: '' } })]);
        const dds = w.findAll('dd').map((d) => d.text());
        expect(dds).toEqual(['Transfer to CCU', '0', '{"a":1}', '—']);
    });

    it('renders a value that looks like markup as text, never as markup', () => {
        const w = mountWith([item({
            actor: '<b>not bold</b>',
            details: { reason: '<img src=x onerror="alert(1)">', bed: { from: '<script>x</script>', to: '1' } },
        })]);
        expect(w.find('img').exists()).toBe(false);
        expect(w.find('script').exists()).toBe(false);
        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toContain('<img src=x onerror="alert(1)">');
        expect(w.text()).toContain('<b>not bold</b>');
    });
});
