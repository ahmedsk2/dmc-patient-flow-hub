import { toValue } from 'vue';

/**
 * useUnsavedGuard — gates a modal/form close behind the existing useConfirm() discard-confirm when
 * there are unsaved changes (Wave 3, Item 1). `isDirty` may be a ref, computed, or plain function —
 * toValue() reads all three — so callers can pass whatever their own dirty-tracking already
 * produces (an Inertia form's `.isDirty`, or a hand-rolled snapshot comparison).
 *
 * `guardedClose(closeFn)` is the single entry point: clean state calls closeFn() immediately (no
 * dialog, no behavior change from before this existed); dirty state awaits the SAME useConfirm()
 * `ask()` every other destructive/blocking confirm in the app uses (so it looks and behaves like
 * every other confirm the user has seen), and only invokes closeFn on an explicit "yes, discard".
 *
 * `ask` is injected rather than imported here so this composable stays a pure function of its
 * inputs — trivially testable without touching the useConfirm() singleton — and so BaseModal /
 * host components all route through the ONE ask() instance already registered in AppLayout.
 *
 * @param {import('vue').Ref|import('vue').ComputedRef|Function} isDirty
 * @param {Function} ask   the useConfirm().ask() function — (title, body, tone) => Promise<boolean>
 */
export function useUnsavedGuard(isDirty, ask) {
    async function guardedClose(closeFn) {
        if (!toValue(isDirty)) { closeFn(); return; }
        const discard = await ask(
            'Discard changes?',
            'You have unsaved changes. Leaving now will discard them.',
            'danger',
        );
        if (discard) closeFn();
    }
    return { guardedClose };
}
