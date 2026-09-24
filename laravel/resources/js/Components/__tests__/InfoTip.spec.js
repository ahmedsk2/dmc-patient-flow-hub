import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h, nextTick } from 'vue';

import InfoTip from '@/Components/InfoTip.vue';

// The bubble is teleported to <body>, so these tests query document, not the wrapper.
const bubble = () => document.body.querySelector('[data-infotip-bubble]');
const mountTip = (props = {}) =>
    mount(InfoTip, { props: { text: 'Deaths divided by discharges in this range.', label: 'Mortality', ...props }, attachTo: document.body });

let w;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('InfoTip', () => {
    it('renders a real button with an accessible name and no bubble until asked', () => {
        w = mountTip();
        const b = w.get('button[data-infotip]');
        expect(b.attributes('type')).toBe('button');
        expect(b.attributes('aria-label')).toBe('More information: Mortality');
        expect(b.attributes('aria-expanded')).toBe('false');
        expect(b.attributes('aria-describedby')).toBeUndefined();
        expect(b.text()).toBe('!');
        expect(bubble()).toBeNull();
    });

    it('falls back to a generic accessible name without a label', () => {
        w = mountTip({ label: '' });
        expect(w.get('button').attributes('aria-label')).toBe('More information');
    });

    it('shows the text on hover and hides it when the pointer leaves', async () => {
        w = mountTip();
        await w.get('button').trigger('mouseenter');
        await nextTick();
        expect(bubble()?.textContent).toBe('Deaths divided by discharges in this range.');
        expect(bubble()?.getAttribute('role')).toBe('tooltip');
        expect(w.get('button').attributes('aria-describedby')).toBe(bubble().id);
        await w.get('button').trigger('mouseleave');
        await nextTick();
        expect(bubble()).toBeNull();
    });

    it('shows on keyboard focus and closes on Escape', async () => {
        w = mountTip();
        await w.get('button').trigger('focus');
        await nextTick();
        expect(bubble()).not.toBeNull();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(bubble()).toBeNull();
    });

    it('a click pins it open through mouseleave/blur; a second click or an outside tap closes it', async () => {
        w = mountTip();
        const b = w.get('button');
        await b.trigger('click');
        await nextTick();
        expect(bubble()).not.toBeNull();
        expect(b.attributes('aria-expanded')).toBe('true');
        await b.trigger('mouseleave');
        await b.trigger('blur');
        await nextTick();
        expect(bubble()).not.toBeNull();   // pinned: stays for a phone user who tapped it
        await b.trigger('click');
        await nextTick();
        expect(bubble()).toBeNull();

        await b.trigger('click');
        await nextTick();
        expect(bubble()).not.toBeNull();
        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        await nextTick();
        expect(bubble()).toBeNull();
    });

    it('a click on the mark does not bubble to a clickable parent (row, card, label)', async () => {
        let parentClicks = 0;
        const Parent = {
            render: () => h('div', { onClick: () => { parentClicks += 1; } }, [h(InfoTip, { text: 'x', label: 'y' })]),
        };
        w = mount(Parent, { attachTo: document.body });
        await w.get('button[data-infotip]').trigger('click');
        expect(parentClicks).toBe(0);
    });

    it('never renders the text as HTML', async () => {
        w = mountTip({ text: '<img src=x onerror=alert(1)> plain' });
        await w.get('button').trigger('mouseenter');
        await nextTick();
        expect(bubble().querySelector('img')).toBeNull();
        expect(bubble().textContent).toContain('<img');
    });

    it('is hidden in print', () => {
        w = mountTip();
        expect(w.element.className).toContain('print:hidden');
    });
});
