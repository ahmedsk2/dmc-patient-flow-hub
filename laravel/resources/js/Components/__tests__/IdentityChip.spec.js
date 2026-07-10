import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import IdentityChip from '@/Components/IdentityChip.vue';

/**
 * Wave 1 (EHC UI) — IdentityChip is the identity-tuple primitive: the SAME tuple a user picked in a
 * result row travels unchanged into any action/edit modal header (name · MRN · age·sex · location
 * · status). The load-bearing guarantees: the MRN is tabular and NEVER shortened, text rides the
 * theme-aware ink/on-* tokens, and the status chip uses the AA-verified tint pairs only.
 */
const full = { name: 'Amal Hassan', mrn: '12345678', age: 63, sex: 'Female', location: 'Ward', status: 'active' };
const chip = (props = {}) => mount(IdentityChip, { props: { ...full, ...props } });

const mrnSpan = (w) => w.get('[data-id-mrn]');

describe('IdentityChip — the identity tuple', () => {
    it('renders the full tuple: bold name, MRN, age·sex, location', () => {
        const w = chip();
        expect(w.text()).toContain('Amal Hassan');
        expect(w.text()).toContain('MRN 12345678');
        expect(w.text()).toContain('63 · F');
        expect(w.text()).toContain('Ward');
        expect(w.get('[data-id-name]').classes()).toContain('font-bold');
    });

    it('MRN is tabular-nums and carries NO shortening class anywhere', () => {
        const w = chip();
        const c = mrnSpan(w).classes();
        expect(c).toContain('nums');
        expect(c).toContain('whitespace-nowrap');
        expect(c).not.toContain('truncate');
        // belt-and-braces: the whole rendered chip never shortens any segment
        expect(w.html()).not.toContain('truncate');
    });

    it('a very long MRN still renders in full', () => {
        const w = chip({ mrn: '99887766554433221100' });
        expect(mrnSpan(w).text()).toBe('MRN 99887766554433221100');
    });

    it('draws the teal hairline border', () => {
        const c = chip().classes();
        expect(c).toContain('border');
        expect(c).toContain('border-brand-500/30');
    });

    it('omits missing segments without leaving separators behind', () => {
        const w = mount(IdentityChip, { props: { name: 'Solo Name', mrn: '1002' } });
        expect(w.text()).toContain('Solo Name');
        expect(w.text()).toContain('MRN 1002');
        expect(w.find('[data-id-agesex]').exists()).toBe(false);
        expect(w.find('[data-id-location]').exists()).toBe(false);
        expect(w.find('[data-id-status]').exists()).toBe(false);
    });

    it('age-only and sex-only both render sensibly', () => {
        expect(chip({ sex: '' }).get('[data-id-agesex]').text()).toBe('63');
        expect(chip({ age: null }).get('[data-id-agesex]').text()).toBe('F');
    });
});

describe('IdentityChip — status chip (AA tint pairs; dead/closed episodes are unmistakable)', () => {
    it.each([
        ['active', 'Active', ['bg-tint-success', 'text-on-success']],
        ['unassigned', 'Unassigned', ['bg-tint-accent', 'text-on-accent']],
        ['discharged', 'Discharged', ['bg-ink-100', 'text-ink-500']],
        ['deceased', 'Deceased', ['bg-tint-danger', 'text-on-danger']],
    ])('status "%s" renders a "%s" chip with its verified pair', (status, label, classes) => {
        const s = chip({ status }).get('[data-id-status]');
        expect(s.text()).toBe(label);
        expect(s.classes()).toEqual(expect.arrayContaining(classes));
    });

    it('no status prop → no chip at all', () => {
        expect(chip({ status: '' }).find('[data-id-status]').exists()).toBe(false);
    });

    it('never ships a raw status colour as text', () => {
        for (const status of ['active', 'unassigned', 'discharged', 'deceased']) {
            const html = chip({ status }).html();
            for (const cls of ['text-danger-600', 'text-danger-500', 'text-success-600', 'text-success-500', 'text-warning-500', 'text-accent-600']) {
                expect(html).not.toContain(cls);
            }
        }
    });
});
