import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SearchableSelect from '@/Components/SearchableSelect.vue';

const many = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, name: `Dr. Person ${i + 1}` }));
const few = [{ id: 1, name: 'Alpha' }, { id: 2, name: 'Beta' }];

describe('SearchableSelect', () => {
    it('renders a plain native select at or below the threshold', () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: few } });
        expect(w.find('select').exists()).toBe(true);
        expect(w.find('input[role="combobox"]').exists()).toBe(false);
        w.unmount();
    });

    it('renders a combobox above the threshold', () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: many } });
        expect(w.find('input[role="combobox"]').exists()).toBe(true);
        w.unmount();
    });

    it('filters by case-insensitive SUBSTRING, not prefix', async () => {
        const opts = [...many, { id: 99, name: 'Khalid Alizadeh' }];
        const w = mount(SearchableSelect, { props: { modelValue: '', options: opts } });
        await w.find('input[role="combobox"]').trigger('focus');
        await w.find('input[role="combobox"]').setValue('ali');
        const items = w.findAll('[role="option"]').map((li) => li.text());
        expect(items).toContain('Khalid Alizadeh');            // matched mid-word
        expect(items.every((t) => t.toLowerCase().includes('ali'))).toBe(true);
        w.unmount();
    });

    it('emits the option id on selection', async () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: many } });
        await w.find('input[role="combobox"]').trigger('focus');
        await w.findAll('[role="option"]')[2].trigger('mousedown');
        expect(w.emitted('update:modelValue').at(-1)[0]).toBe(3);
        w.unmount();
    });

    it('shows a no-matches hint when nothing matches', async () => {
        const w = mount(SearchableSelect, { props: { modelValue: '', options: many } });
        await w.find('input[role="combobox"]').trigger('focus');
        await w.find('input[role="combobox"]').setValue('zzzzz');
        expect(w.text()).toContain('No matches');
        w.unmount();
    });

    it('puts id and aria-describedby on the real control in BOTH branches', () => {
        const few = [{ id: 1, name: 'Alpha' }, { id: 2, name: 'Beta' }];
        const many = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, name: `Dr. Person ${i + 1}` }));

        const nativeW = mount(SearchableSelect, { props: { modelValue: '', options: few, id: 'pick-1', ariaDescribedby: 'pick-1-err' } });
        const sel = nativeW.find('select');
        expect(sel.attributes('id')).toBe('pick-1');
        expect(sel.attributes('aria-describedby')).toBe('pick-1-err');
        nativeW.unmount();

        // the branch that production actually renders for a real consultant list
        const comboW = mount(SearchableSelect, { props: { modelValue: '', options: many, id: 'pick-2', ariaDescribedby: 'pick-2-err' } });
        const input = comboW.find('input[role="combobox"]');
        expect(input.attributes('id')).toBe('pick-2');
        expect(input.attributes('aria-describedby')).toBe('pick-2-err');
        // and NOT on the wrapper
        expect(comboW.find('div').attributes('id')).toBeUndefined();
        comboW.unmount();
    });
});
