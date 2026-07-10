import { describe, it, expect, vi } from 'vitest';
import { ref } from 'vue';
import { useTabs } from '@/composables/useTabs';

// Synthetic keyboard event. The composable reads only `.key` and calls `.preventDefault()`, so a
// bare object stands in for a real KeyboardEvent and lets each test inspect the prevented state.
const key = (k) => ({ key: k, preventDefault: vi.fn() });

const IDS = ['summary', 'meds', 'notes'];

describe('useTabs', () => {
    it('starts on the first id when no initial is given', () => {
        expect(useTabs(IDS).active.value).toBe('summary');
    });

    it('starts on { initial } when it names a known id', () => {
        expect(useTabs(IDS, { initial: 'meds' }).active.value).toBe('meds');
    });

    it('falls back to the first id when initial is unknown', () => {
        expect(useTabs(IDS, { initial: 'ghost' }).active.value).toBe('summary');
    });

    it('select() moves the active id', () => {
        const t = useTabs(IDS);
        t.select('notes');
        expect(t.active.value).toBe('notes');
    });

    it('select() ignores an id that is not in the list', () => {
        const t = useTabs(IDS);
        t.select('ghost');
        expect(t.active.value).toBe('summary');
    });

    // Automatic activation (W3C APG's simpler variant): the arrow keys move SELECTION directly,
    // not just focus — there is no separate Enter/Space step.
    it('ArrowRight selects the next id and wraps from the last back to the first', () => {
        const t = useTabs(IDS);
        t.onKeydown(key('ArrowRight'));
        expect(t.active.value).toBe('meds');
        t.onKeydown(key('ArrowRight'));
        expect(t.active.value).toBe('notes');
        t.onKeydown(key('ArrowRight'));
        expect(t.active.value).toBe('summary'); // wrapped
    });

    it('ArrowLeft selects the previous id and wraps from the first back to the last', () => {
        const t = useTabs(IDS);
        t.onKeydown(key('ArrowLeft'));
        expect(t.active.value).toBe('notes'); // wrapped
        t.onKeydown(key('ArrowLeft'));
        expect(t.active.value).toBe('meds');
    });

    it('Home jumps to the first id, End to the last', () => {
        const t = useTabs(IDS, { initial: 'meds' });
        t.onKeydown(key('Home'));
        expect(t.active.value).toBe('summary');
        t.onKeydown(key('End'));
        expect(t.active.value).toBe('notes');
    });

    // The DOM half of roving tabindex is the caller's job: the return value tells the component
    // which tab element to put real focus on.
    it('onKeydown returns the newly selected id so the caller can move DOM focus', () => {
        const t = useTabs(IDS);
        expect(t.onKeydown(key('End'))).toBe('notes');
        expect(t.onKeydown(key('ArrowLeft'))).toBe('meds');
        expect(t.onKeydown(key('Home'))).toBe('summary');
    });

    it('default-prevents handled keys; leaves unrelated keys alone (returns null, no move)', () => {
        const t = useTabs(IDS);
        const right = key('ArrowRight');
        t.onKeydown(right);
        expect(right.preventDefault).toHaveBeenCalledTimes(1);

        // Tab must keep leaving the tablist, and vertical arrows are not part of the horizontal
        // model — both fall through untouched.
        for (const k of ['Tab', 'ArrowDown', 'ArrowUp', 'Enter', ' ']) {
            const e = key(k);
            expect(t.onKeydown(e)).toBeNull();
            expect(e.preventDefault).not.toHaveBeenCalled();
        }
        expect(t.active.value).toBe('meds');
    });

    it('guards an empty id list: null active, every call is a safe no-op', () => {
        const t = useTabs([]);
        expect(t.active.value).toBeNull();
        expect(() => t.select('anything')).not.toThrow();
        expect(t.onKeydown(key('ArrowRight'))).toBeNull();
        expect(t.onKeydown(key('Home'))).toBeNull();
        expect(t.active.value).toBeNull();
    });

    // Tabs.vue passes a computed of ids; the keyboard model must follow the CURRENT value, not a
    // snapshot taken at setup time.
    it('accepts a reactive ids source and follows its current value', () => {
        const ids = ref(['a', 'b']);
        const t = useTabs(ids);
        t.onKeydown(key('End'));
        expect(t.active.value).toBe('b');
        ids.value = ['a', 'b', 'c'];
        t.onKeydown(key('End'));
        expect(t.active.value).toBe('c');
    });
});
