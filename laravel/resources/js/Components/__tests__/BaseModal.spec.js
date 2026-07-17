import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

// useConfirm mocked so the dirty-guard tests control ask() directly, without needing the real
// ConfirmDialog UI (which lives outside BaseModal, in AppLayout).
const { ask } = vi.hoisted(() => ({ ask: vi.fn() }));
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ ask }) }));

import BaseModal from '@/Components/BaseModal.vue';

beforeEach(() => { ask.mockReset(); });

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

    it('supports a wide size (~75%) for large forms', () => {
        const w = mountModal({ size: 'wide' });
        expect(w.find('[role="dialog"]').classes()).toContain('max-w-4xl');
        w.unmount();
    });
});

// Wave 3, Item 2: an optional `dirty` prop gates Esc / backdrop-click / the X-close button behind
// the SAME useConfirm() discard-confirm every other destructive action uses. Backward compatible:
// a caller that never passes `dirty` (default false) gets EXACTLY today's behavior — no ask() call,
// no behavior change — proven by the pre-existing tests above (which never pass `dirty`).
describe('BaseModal — dirty prop (unsaved-changes guard)', () => {
    it('clean modal (dirty=false, the default): closes on Escape WITHOUT asking', async () => {
        const w = mountModal();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await Promise.resolve();
        expect(ask).not.toHaveBeenCalled();
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });

    it('dirty modal: Escape asks before closing, and does NOT close until the promise resolves', async () => {
        let resolveAsk;
        ask.mockReturnValue(new Promise((r) => { resolveAsk = r; }));
        const w = mountModal({ dirty: true });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await Promise.resolve();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(w.emitted('close')).toBeFalsy();   // still pending — no premature close
        resolveAsk(true);
        await Promise.resolve(); await Promise.resolve();
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });

    it('dirty modal: declining the confirm keeps the modal open (no close emitted)', async () => {
        ask.mockResolvedValue(false);
        const w = mountModal({ dirty: true });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(w.emitted('close')).toBeFalsy();
        w.unmount();
    });

    it('dirty modal: backdrop click also routes through the confirm', async () => {
        ask.mockResolvedValue(true);
        const w = mountModal({ dirty: true });
        await w.find('.fixed.inset-0').trigger('click');
        await Promise.resolve(); await Promise.resolve();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });

    it('dirty modal: the X close button also routes through the confirm', async () => {
        ask.mockResolvedValue(true);
        const w = mountModal({ dirty: true });
        await w.get('button[aria-label="Close"]').trigger('click');
        await Promise.resolve(); await Promise.resolve();
        expect(ask).toHaveBeenCalledTimes(1);
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });

    it('clean modal: backdrop click and X-button close silently, no ask() at all', async () => {
        const w = mountModal({ dirty: false });
        await w.find('.fixed.inset-0').trigger('click');
        expect(ask).not.toHaveBeenCalled();
        expect(w.emitted('close')).toBeTruthy();
        w.unmount();
    });
});
