import { describe, it, expect, beforeEach } from 'vitest';
import { useModalA11y } from '@/composables/useModalA11y';

// Build a small DOM modal with three focusable buttons and wire trapRef to it.
const buildModal = (a11y) => {
    const root = document.createElement('div');
    root.innerHTML = `
        <button id="b1">one</button>
        <input id="b2" />
        <button id="b3">three</button>
    `;
    document.body.appendChild(root);
    a11y.trapRef.value = root;
    return root;
};

describe('useModalA11y', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('onOpen focuses the first focusable element inside trapRef', async () => {
        const a11y = useModalA11y();
        buildModal(a11y);
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        a11y.onOpen(opener);
        // onOpen focuses on nextTick — flush microtasks.
        await Promise.resolve();
        await new Promise((r) => setTimeout(r, 0));
        expect(document.activeElement.id).toBe('b1');
    });

    it('Tab on the last focusable wraps to the first', () => {
        const a11y = useModalA11y();
        buildModal(a11y);
        document.getElementById('b3').focus();
        a11y.onKeydown(new KeyboardEvent('keydown', { key: 'Tab' }));
        expect(document.activeElement.id).toBe('b1');
    });

    it('Shift+Tab on the first focusable wraps to the last', () => {
        const a11y = useModalA11y();
        buildModal(a11y);
        document.getElementById('b1').focus();
        a11y.onKeydown(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true }));
        expect(document.activeElement.id).toBe('b3');
    });

    it('Tab in the middle is left to the browser (no preventDefault forcing)', () => {
        const a11y = useModalA11y();
        buildModal(a11y);
        document.getElementById('b2').focus();
        const e = new KeyboardEvent('keydown', { key: 'Tab' });
        a11y.onKeydown(e);
        // focus untouched by the trap — still on the middle element.
        expect(document.activeElement.id).toBe('b2');
    });

    it('onClose returns focus to the stored opener', async () => {
        const a11y = useModalA11y();
        buildModal(a11y);
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        a11y.onOpen(opener);
        await Promise.resolve();
        await new Promise((r) => setTimeout(r, 0));
        a11y.onClose();
        expect(document.activeElement).toBe(opener);
    });

    it('onOpen({ fieldFirst:true }) focuses the first INPUT, skipping the leading button (Item 6)', async () => {
        const a11y = useModalA11y();
        buildModal(a11y);   // order: button#b1, input#b2, button#b3
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        a11y.onOpen(opener, { fieldFirst: true });
        await Promise.resolve();
        await new Promise((r) => setTimeout(r, 0));
        expect(document.activeElement.id).toBe('b2');
    });

    it('onOpen() with no options focuses the first focusable (unchanged behaviour)', async () => {
        const a11y = useModalA11y();
        buildModal(a11y);
        a11y.onOpen(undefined);
        await Promise.resolve();
        await new Promise((r) => setTimeout(r, 0));
        expect(document.activeElement.id).toBe('b1');
    });

    it('onOpen({ fieldFirst:true }) falls back to items[0] when the panel has no field', async () => {
        const a11y = useModalA11y();
        const root = document.createElement('div');
        root.innerHTML = '<button id="only">x</button>';
        document.body.appendChild(root);
        a11y.trapRef.value = root;
        a11y.onOpen(undefined, { fieldFirst: true });
        await Promise.resolve();
        await new Promise((r) => setTimeout(r, 0));
        expect(document.activeElement.id).toBe('only');
    });

    it('onOpen falls back to document.activeElement when no opener is passed', async () => {
        const a11y = useModalA11y();
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();
        buildModal(a11y);
        a11y.onOpen();   // no arg → captures current activeElement (opener)
        await Promise.resolve();
        await new Promise((r) => setTimeout(r, 0));
        a11y.onClose();
        expect(document.activeElement).toBe(opener);
    });
});
