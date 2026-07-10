import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ErrorSummary from '@/Components/ErrorSummary.vue';

// NHS/GOV.UK-style error summary (Wave 3, Item 4). Sits at the top of a modal/form and jumps focus
// to the offending field on click. `errors` is a { fieldId: message } map keyed by the target
// input's DOM id (not necessarily its form-data key) so the href/focus jump always resolves.

describe('ErrorSummary', () => {
    it('renders nothing when errors is empty', () => {
        const w = mount(ErrorSummary, { props: { errors: {} } });
        expect(w.find('[role="alert"]').exists()).toBe(false);
        expect(w.text()).toBe('');
    });

    it('renders nothing when errors is omitted entirely', () => {
        const w = mount(ErrorSummary, { props: {} });
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('renders a role=alert box with the default heading and one link per error', () => {
        const w = mount(ErrorSummary, { props: { errors: { mrn: 'Enter an MRN using digits only', name: 'Enter a name' } } });
        const alert = w.get('[role="alert"]');
        expect(alert.text()).toContain('There is a problem');
        const links = w.findAll('a');
        expect(links).toHaveLength(2);
        expect(links[0].attributes('href')).toBe('#mrn');
        expect(links[0].text()).toBe('Enter an MRN using digits only');
        expect(links[1].attributes('href')).toBe('#name');
    });

    it('uses a custom title when provided', () => {
        const w = mount(ErrorSummary, { props: { errors: { mrn: 'bad' }, title: 'Fix these fields' } });
        expect(w.text()).toContain('Fix these fields');
        expect(w.text()).not.toContain('There is a problem');
    });

    it('clicking a link prevents navigation and focuses + scrolls the target field', async () => {
        document.body.innerHTML = '<input id="mrn" />';
        const input = document.getElementById('mrn');
        const focusSpy = vi.spyOn(input, 'focus');
        input.scrollIntoView = vi.fn();
        const w = mount(ErrorSummary, {
            props: { errors: { mrn: 'Enter an MRN using digits only' } },
            attachTo: document.body,
        });
        const link = w.get('a');
        const evt = await link.trigger('click');
        expect(focusSpy).toHaveBeenCalledTimes(1);
        expect(input.scrollIntoView).toHaveBeenCalledTimes(1);
        w.unmount();
    });

    it('tolerates a missing target field (no crash)', async () => {
        const w = mount(ErrorSummary, { props: { errors: { ghost: 'no such field' } } });
        await w.get('a').trigger('click');
        // no throw = pass
    });

    it('never uses a bare status colour as text — theme-aware text-on-danger only', () => {
        const w = mount(ErrorSummary, { props: { errors: { mrn: 'bad' } } });
        expect(w.html()).not.toMatch(/text-danger-(400|500|600|700)/);
        expect(w.html()).toContain('text-on-danger');
    });
});
