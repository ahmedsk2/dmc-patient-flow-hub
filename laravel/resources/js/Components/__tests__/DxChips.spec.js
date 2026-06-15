import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DxChips from '@/Components/DxChips.vue';

const dx = [{ code: 'A00', name: 'Cholera' }, { code: 'B01', name: 'Varicella' }];

describe('DxChips', () => {
    it('renders a chip per diagnosis with code + name', () => {
        const w = mount(DxChips, { props: { diagnoses: dx } });
        expect(w.text()).toContain('A00');
        expect(w.text()).toContain('Cholera');
        expect(w.text()).toContain('B01');
    });

    it('renders nothing when the list is empty', () => {
        const w = mount(DxChips, { props: { diagnoses: [] } });
        expect(w.find('div').exists()).toBe(false);
    });

    it('hides the × buttons when not removable', () => {
        const w = mount(DxChips, { props: { diagnoses: dx } });
        expect(w.findAll('button').length).toBe(0);
    });

    it('emits remove(code) when a × is clicked in removable mode', async () => {
        const w = mount(DxChips, { props: { diagnoses: dx, removable: true } });
        const buttons = w.findAll('button');
        expect(buttons.length).toBe(2);
        await buttons[0].trigger('click');
        expect(w.emitted('remove')[0]).toEqual(['A00']);
    });
});
