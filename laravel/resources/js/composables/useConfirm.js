import { ref } from 'vue';

// Module-level singleton: ONE pending confirmation at a time, app-wide. Any page imports
// `useConfirm()` and calls `await ask(...)` to gate a destructive/sensitive action behind the
// themed <ConfirmDialog /> registered once in AppLayout. No prop-drilling, no per-page wiring.
//
//   const { ask } = useConfirm();
//   if (await ask('Delete admission', 'Permanently remove …', 'danger')) router.delete(...)
//
// state: null when idle, else { title, body, tone, resolve }.
const state = ref(null);

export function useConfirm() {
    // Open a dialog and return a Promise that resolves true (Confirm) / false (Cancel/Esc/backdrop).
    // A second ask() REPLACES the first (no stacking) — clinical confirmations are one-at-a-time.
    const ask = (title, body, tone = 'danger') =>
        new Promise((resolve) => {
            state.value = { title, body, tone, resolve };
        });

    // Resolve the pending promise and clear the dialog. Called by ConfirmDialog's buttons / Esc.
    const answer = (ok) => {
        const r = state.value?.resolve;
        state.value = null;
        r?.(ok);
    };

    return { state, ask, answer };
}
