/**
 * Wave 1 (EHC UI) — recently-opened patients for the command palette.
 *
 * Deliberately a PLAIN MODULE (no pinia, no localStorage/sessionStorage): this app runs on shared
 * clinical workstations, so nothing patient-related may outlive the tab/session. Only OPAQUE
 * admission row ids are held — never a name or MRN. Display data is re-fetched server-side on
 * palette open through the same role-scoped quick-search endpoint, so a user who lost access to a
 * patient simply stops seeing that recent. Cleared on logout (AppLayout hooks both the manual
 * sign-out and the idle auto-logout).
 */
const MAX_RECENTS = 7;

let ids = [];

/** Snapshot of the recent admission ids, most recent first. */
export function recentIds() {
    return [...ids];
}

/** Record an opened admission id (de-duped, most recent first, capped). */
export function pushRecent(id) {
    if (id === null || id === undefined) return;
    ids = [id, ...ids.filter((x) => x !== id)].slice(0, MAX_RECENTS);
}

/** Forget everything — called on logout so the next user sees a clean palette. */
export function clearRecents() {
    ids = [];
}
