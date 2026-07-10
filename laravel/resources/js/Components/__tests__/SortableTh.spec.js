import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SortableTh from '@/Components/SortableTh.vue';

// Wave 2 — Item 3: a sortable column header. The clickable surface is a REAL <button> inside a
// <th scope="col">, and the <th> itself carries the live aria-sort so assistive tech announces
// the active column/direction without relying on the visual glyph alone.
const mountTh = (props = {}) => mount(SortableTh, {
    props: { label: 'Patient', sortKey: 'name', current: { key: null, dir: null }, ...props },
});

describe('SortableTh', () => {
    it('renders a scope="col" th wrapping a real type="button" carrying the label', () => {
        const w = mountTh();
        expect(w.find('th').attributes('scope')).toBe('col');
        const btn = w.find('button');
        expect(btn.exists()).toBe(true);
        expect(btn.attributes('type')).toBe('button');
        expect(btn.text()).toContain('Patient');
    });

    it('aria-sort is "none" when this column is not the active sort', () => {
        expect(mountTh({ current: { key: null, dir: null } }).find('th').attributes('aria-sort')).toBe('none');
        expect(mountTh({ current: { key: 'other', dir: 'asc' } }).find('th').attributes('aria-sort')).toBe('none');
    });

    it('aria-sort reflects the active column\'s direction', () => {
        expect(mountTh({ current: { key: 'name', dir: 'asc' } }).find('th').attributes('aria-sort')).toBe('ascending');
        expect(mountTh({ current: { key: 'name', dir: 'desc' } }).find('th').attributes('aria-sort')).toBe('descending');
    });

    it('a different active column does not mark THIS header sorted', () => {
        const w = mountTh({ sortKey: 'admit_date', current: { key: 'name', dir: 'asc' } });
        expect(w.find('th').attributes('aria-sort')).toBe('none');
    });

    it('clicking cycles none -> desc -> asc -> none, emitting {key, dir} each time', async () => {
        const w = mountTh({ current: { key: null, dir: null } });
        await w.find('button').trigger('click');
        expect(w.emitted('sort')[0]).toEqual([{ key: 'name', dir: 'desc' }]);

        await w.setProps({ current: { key: 'name', dir: 'desc' } });
        await w.find('button').trigger('click');
        expect(w.emitted('sort')[1]).toEqual([{ key: 'name', dir: 'asc' }]);

        await w.setProps({ current: { key: 'name', dir: 'asc' } });
        await w.find('button').trigger('click');
        expect(w.emitted('sort')[2]).toEqual([{ key: 'name', dir: null }]);
    });

    it('clicking a currently-inactive column starts at desc regardless of another column being active', async () => {
        const w = mountTh({ sortKey: 'date', current: { key: 'name', dir: 'asc' } });
        await w.find('button').trigger('click');
        expect(w.emitted('sort')[0]).toEqual([{ key: 'date', dir: 'desc' }]);
    });

    it('shows an aria-hidden sort glyph (never the sole indicator — aria-sort carries the semantics)', () => {
        expect(mountTh().find('[aria-hidden="true"]').exists()).toBe(true);
    });
});
