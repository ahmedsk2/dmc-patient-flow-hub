import { describe, it, expect, beforeEach } from 'vitest';
import { useConfirm } from '@/composables/useConfirm';

describe('useConfirm', () => {
    beforeEach(() => {
        // Reset the module singleton between tests by answering any pending dialog.
        const { state, answer } = useConfirm();
        if (state.value) answer(false);
    });

    it('ask() returns a Promise and sets the dialog state', () => {
        const { ask, state } = useConfirm();
        const p = ask('Title', 'Body', 'danger');
        expect(p).toBeInstanceOf(Promise);
        expect(state.value).toMatchObject({ title: 'Title', body: 'Body', tone: 'danger' });
    });

    it('defaults tone to danger', () => {
        const { ask, state } = useConfirm();
        ask('T', 'B');
        expect(state.value.tone).toBe('danger');
    });

    it('answer(true) resolves the promise with true and clears state', async () => {
        const { ask, answer, state } = useConfirm();
        const p = ask('T', 'B');
        answer(true);
        await expect(p).resolves.toBe(true);
        expect(state.value).toBe(null);
    });

    it('answer(false) resolves the promise with false', async () => {
        const { ask, answer } = useConfirm();
        const p = ask('T', 'B');
        answer(false);
        await expect(p).resolves.toBe(false);
    });

    it('a second ask() replaces the first (no stacking)', async () => {
        const { ask, answer, state } = useConfirm();
        ask('First', 'B1');
        const second = ask('Second', 'B2', 'neutral');
        // Only one dialog is tracked; it is the second one.
        expect(state.value).toMatchObject({ title: 'Second', tone: 'neutral' });
        answer(true);
        await expect(second).resolves.toBe(true);
    });

    it('shares one singleton state across separate useConfirm() calls', () => {
        const a = useConfirm();
        const b = useConfirm();
        a.ask('Shared', 'B');
        expect(b.state.value).toMatchObject({ title: 'Shared' });
        b.answer(false);
    });
});
