import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BaseModal from '@/Components/BaseModal.vue';

const mountModal = (props = {}, slots = {}) =>
    mount(BaseModal, {
        props: { open: true, title: 'Test dialog', ...props },
        slots: { default: '<p class="body">hello</p>', ...slots },
        attachTo: document.body,
    });

describe('BaseModal', () => {
    it('renders nothing when open=false', () => {
        const w = mount(BaseModal, { props: { open: false, title: 'X' } });
        expect(w.find('[role="dialog"]').exists()).toBe(false);
    });

    it('renders the dialog with aria-modal + labelled title when open', () => {
        const w = mountModal();
        const dialog = w.find('[role="dialog"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.attributes('aria-modal')).toBe('true');
        const id = dialog.attributes('aria-labelledby');
        expect(id).toBeTruthy();
        const heading = w.get(`#${id}`);
        expect(heading.text()).toBe('Test dialog');
        expect(w.find('.body').exists()).toBe(true);
        w.unmount();
    });

    it('renders the subtitle when provided', () => {
        const w = mountModal({ subtitle: 'MRN 123' });
        expect(w.text()).toContain('MRN 123');
        w.unmount();
    });

    it('emits close on Escape (only while open)', async () => {
        const w = mountModal();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await Promise.resolve();
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });

    it('emits close on backdrop click', async () => {
        const w = mountModal();
        await w.find('.fixed.inset-0').trigger('click');
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });

    it('emits close on the X button', async () => {
        const w = mountModal();
        await w.get('button[aria-label="Close"]').trigger('click');
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });

    it('hides the X button when closable=false', () => {
        const w = mountModal({ closable: false });
        expect(w.find('button[aria-label="Close"]').exists()).toBe(false);
        w.unmount();
    });

    it('removes the window keydown listener on unmount', () => {
        const add = vi.spyOn(window, 'addEventListener');
        const remove = vi.spyOn(window, 'removeEventListener');
        const w = mountModal();
        const handler = add.mock.calls.find((c) => c[0] === 'keydown')?.[1];
        expect(handler).toBeTypeOf('function');
        w.unmount();
        expect(remove).toHaveBeenCalledWith('keydown', handler);
        add.mockRestore();
        remove.mockRestore();
    });

    it('maps size → max-width class', () => {
        const w = mountModal({ size: '2xl' });
        expect(w.find('[role="dialog"]').classes()).toContain('max-w-2xl');
        w.unmount();
    });
});
