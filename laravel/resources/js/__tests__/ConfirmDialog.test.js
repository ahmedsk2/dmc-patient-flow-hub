import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { useConfirm } from '@/composables/useConfirm';

// ConfirmDialog teleports to <body>; mount with attachTo so the teleport target exists and the
// rendered nodes are queryable via document.
const mountDialog = () => mount(ConfirmDialog, { attachTo: document.body });

const dialogEl = () => document.querySelector('[role="dialog"]');

describe('ConfirmDialog.vue', () => {
    let wrapper;
    beforeEach(() => {
        const { state, answer } = useConfirm();
        if (state.value) answer(false);
        wrapper = mountDialog();
    });
    afterEach(() => {
        const { state, answer } = useConfirm();
        if (state.value) answer(false);
        wrapper?.unmount();
        document.body.innerHTML = '';
    });

    it('renders nothing while idle', () => {
        expect(dialogEl()).toBe(null);
    });

    it('renders role="dialog" and aria-modal when a confirmation is pending', async () => {
        const { ask } = useConfirm();
        ask('Delete admission', 'Remove the episode for X (MRN 1).', 'danger');
        await wrapper.vm.$nextTick();
        const d = dialogEl();
        expect(d).not.toBe(null);
        expect(d.getAttribute('aria-modal')).toBe('true');
    });

    it('aria-labelledby points to the h3 title id', async () => {
        const { ask } = useConfirm();
        ask('Change patient identity', 'body', 'danger');
        await wrapper.vm.$nextTick();
        const d = dialogEl();
        const labelledby = d.getAttribute('aria-labelledby');
        const h3 = document.getElementById(labelledby);
        expect(h3).not.toBe(null);
        expect(h3.tagName).toBe('H3');
        expect(h3.textContent).toContain('Change patient identity');
    });

    it('renders the title and the consequence body', async () => {
        const { ask } = useConfirm();
        ask('Delete admission', 'Permanently remove the episode for Jane (MRN 99).');
        await wrapper.vm.$nextTick();
        const d = dialogEl();
        expect(d.textContent).toContain('Delete admission');
        expect(d.textContent).toContain('MRN 99');
    });

    it('Cancel button resolves the promise with false', async () => {
        const { ask, state } = useConfirm();
        const p = ask('T', 'B');
        await wrapper.vm.$nextTick();
        document.querySelector('[data-test="cd-cancel"]').click();
        await expect(p).resolves.toBe(false);
        expect(state.value).toBe(null);
    });

    it('Confirm button resolves the promise with true', async () => {
        const { ask } = useConfirm();
        const p = ask('T', 'B');
        await wrapper.vm.$nextTick();
        document.querySelector('[data-test="cd-confirm"]').click();
        await expect(p).resolves.toBe(true);
    });

    it('Escape resolves with false (cancel)', async () => {
        const { ask } = useConfirm();
        const p = ask('T', 'B');
        await wrapper.vm.$nextTick();
        document.querySelector('[data-test="cd-panel"]')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await expect(p).resolves.toBe(false);
    });

    it('backdrop click resolves with false (cancel)', async () => {
        const { ask } = useConfirm();
        const p = ask('T', 'B');
        await wrapper.vm.$nextTick();
        const backdrop = document.querySelector('[data-test="cd-backdrop"]');
        // Simulate a self-click on the backdrop (target === backdrop).
        backdrop.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await expect(p).resolves.toBe(false);
    });

    it('Cancel is the first tab stop and Confirm the last (Tab wraps to Cancel)', async () => {
        const { ask } = useConfirm();
        ask('T', 'B');
        await wrapper.vm.$nextTick();
        const cancel = document.querySelector('[data-test="cd-cancel"]');
        const confirm = document.querySelector('[data-test="cd-confirm"]');
        const panel = document.querySelector('[data-test="cd-panel"]');
        // Tab from the last element (Confirm) wraps back to the first (Cancel).
        confirm.focus();
        panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
        expect(document.activeElement).toBe(cancel);
        // Shift+Tab from the first element (Cancel) wraps to the last (Confirm).
        cancel.focus();
        panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }));
        expect(document.activeElement).toBe(confirm);
    });

    it('danger tone styles the confirm button red, neutral styles it brand', async () => {
        const { ask, answer } = useConfirm();
        ask('T', 'B', 'danger');
        await wrapper.vm.$nextTick();
        expect(document.querySelector('[data-test="cd-confirm"]').className).toContain('danger');
        answer(false);
        await wrapper.vm.$nextTick();
        ask('T', 'B', 'neutral');
        await wrapper.vm.$nextTick();
        expect(document.querySelector('[data-test="cd-confirm"]').className).toContain('brand');
    });

    // W0-T3l. `hover:bg-danger-700` went live when cd1f710 declared that colour step — silently, in
    // a file the commit never opened. This is THE destructive-confirm button (every hard delete in
    // the app funnels through it), so its hover state is pinned here rather than left to chance:
    //   rest  white on danger-600 #c1302d = 5.63:1
    //   hover white on danger-700 #9f2724 = 7.53:1   (contrast RISES; a fill hover never touches the
    //                                                 label, unlike an opacity fade)
    // An `opacity` hover would composite the LABEL too and is excluded: WCAG 1.4.3 exempts `disabled`
    // controls, never `hover`. Ratios computed by scripts/contrast.mjs.
    const confirmClasses = () => document.querySelector('[data-test="cd-confirm"]').className.split(/\s+/);

    it('destructive confirm: solid danger-600 fill hovering to a real, darker danger-700 fill', async () => {
        const { ask } = useConfirm();
        ask('Delete admission', 'This cannot be undone.', 'danger');
        await wrapper.vm.$nextTick();
        const c = confirmClasses();
        expect(c).toEqual(expect.arrayContaining(['bg-danger-600', 'text-white', 'hover:bg-danger-700']));
        expect(c.some((x) => x.startsWith('hover:opacity-'))).toBe(false);
        expect(c).not.toContain('hover:bg-danger-600');   // a no-op hover: rest and hover identical
    });

    it('neutral confirm: theme-stable brand-solid fill hovering to brand-solid-hover, never the danger fill', async () => {
        const { ask } = useConfirm();
        ask('Proceed', 'body', 'neutral');
        await wrapper.vm.$nextTick();
        const c = confirmClasses();
        expect(c).toEqual(expect.arrayContaining(['bg-brand-solid', 'text-white', 'hover:bg-brand-solid-hover']));
        expect(c).not.toContain('hover:bg-danger-700');
        expect(c.some((x) => x.startsWith('hover:opacity-'))).toBe(false);
    });
});
