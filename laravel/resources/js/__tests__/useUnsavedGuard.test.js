import { describe, it, expect, vi } from 'vitest';
import { ref, computed } from 'vue';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';

// useUnsavedGuard gates a modal/form close behind the existing useConfirm() ask() when there are
// unsaved changes (Wave 3, Item 1). `ask` is injected (not imported internally) so callers reuse
// the SAME singleton confirm dialog the rest of the app already uses.

describe('useUnsavedGuard', () => {
    it('clean (isDirty false): guardedClose() closes immediately without asking', async () => {
        const isDirty = ref(false);
        const ask = vi.fn();
        const closeFn = vi.fn();
        const { guardedClose } = useUnsavedGuard(isDirty, ask);
        await guardedClose(closeFn);
        expect(ask).not.toHaveBeenCalled();
        expect(closeFn).toHaveBeenCalledTimes(1);
    });

    it('dirty + user confirms discard: asks (danger tone) then closes', async () => {
        const isDirty = ref(true);
        const ask = vi.fn(() => Promise.resolve(true));
        const closeFn = vi.fn();
        const { guardedClose } = useUnsavedGuard(isDirty, ask);
        await guardedClose(closeFn);
        expect(ask).toHaveBeenCalledTimes(1);
        expect(ask.mock.calls[0][2]).toBe('danger');
        expect(closeFn).toHaveBeenCalledTimes(1);
    });

    it('dirty + user keeps editing (declines): asks but does NOT close', async () => {
        const isDirty = ref(true);
        const ask = vi.fn(() => Promise.resolve(false));
        const closeFn = vi.fn();
        const { guardedClose } = useUnsavedGuard(isDirty, ask);
        await guardedClose(closeFn);
        expect(ask).toHaveBeenCalledTimes(1);
        expect(closeFn).not.toHaveBeenCalled();
    });

    it('accepts a computed isDirty', async () => {
        const dirty = ref(true);
        const isDirty = computed(() => dirty.value);
        const ask = vi.fn(() => Promise.resolve(true));
        const closeFn = vi.fn();
        const { guardedClose } = useUnsavedGuard(isDirty, ask);
        await guardedClose(closeFn);
        expect(closeFn).toHaveBeenCalled();
    });

    it('accepts a plain function getter for isDirty', async () => {
        const isDirty = () => true;
        const ask = vi.fn(() => Promise.resolve(true));
        const closeFn = vi.fn();
        const { guardedClose } = useUnsavedGuard(isDirty, ask);
        await guardedClose(closeFn);
        expect(ask).toHaveBeenCalledTimes(1);
        expect(closeFn).toHaveBeenCalled();
    });

    it('the confirm body is constructive, not a bare warning', async () => {
        const ask = vi.fn(() => Promise.resolve(false));
        const { guardedClose } = useUnsavedGuard(ref(true), ask);
        await guardedClose(vi.fn());
        const [title, body] = ask.mock.calls[0];
        expect(title).toMatch(/discard/i);
        expect(body.length).toBeGreaterThan(10);
    });
});
