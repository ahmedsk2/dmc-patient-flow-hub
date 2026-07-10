// Wave 5, Item 4 (Security P1): pure countdown math for the idle/absolute session-timeout warning.
// AppLayout owns the DOM (the warning panel, the 1s tick timer, the activity-event listeners) — this
// module only computes "how many seconds are left, and should we warn", so the arithmetic is testable
// without mounting a component or touching real timers.
//
// idleMinutes / absMinutes: 0 disables that limit — mirrors App\Http\Middleware\SessionTimeout, the
// AUTHORITATIVE server-side enforcer (this is a UX convenience only; a missed tick here just means a
// slightly-late warning, never a security gap — the server logs the user out regardless).
//
// Whichever limit would fire SOONER drives the countdown ("reason": 'idle' | 'absolute' | null when
// both limits are disabled). `now` is an injectable clock (defaults to Date.now) so tests can advance
// time deterministically instead of racing real timers.
export function useSessionTimeout({ idleMinutes = 0, absMinutes = 0, warnBeforeSeconds = 60, now = () => Date.now() } = {}) {
    let lastActivityAt = now();
    let sessionStartedAt = now();

    return {
        // Call on any user-activity event (mousemove/keydown/click/scroll) — resets the IDLE clock only.
        // The absolute clock is deliberately NOT reset by activity: that is the whole point of an
        // absolute session cap.
        markActivity() { lastActivityAt = now(); },

        // Call once when the absolute session's start becomes known (e.g. restored from
        // sessionStorage on mount, seeded to `now()` the first time a session is observed).
        setSessionStart(ts) { sessionStartedAt = ts; },

        // Read-only snapshot, useful for persisting sessionStartedAt across a remount.
        getSessionStart() { return sessionStartedAt; },

        // Compute the current state. Returns:
        //   secondsRemaining  number|null  seconds until the SOONER limit fires (null = both disabled)
        //   warn              boolean      true once inside the warnBeforeSeconds window
        //   expired           boolean      true once the sooner limit has actually elapsed
        //   reason            'idle'|'absolute'|null   which limit is the binding one right now
        tick() {
            const nowMs = now();
            const idleLimitMs = idleMinutes > 0 ? idleMinutes * 60000 : null;
            const absLimitMs = absMinutes > 0 ? absMinutes * 60000 : null;
            const idleRemaining = idleLimitMs !== null ? idleLimitMs - (nowMs - lastActivityAt) : null;
            const absRemaining = absLimitMs !== null ? absLimitMs - (nowMs - sessionStartedAt) : null;

            const candidates = [idleRemaining, absRemaining].filter((v) => v !== null);
            if (!candidates.length) return { secondsRemaining: null, warn: false, expired: false, reason: null };

            const minRemaining = Math.min(...candidates);
            const reason = idleRemaining === minRemaining ? 'idle' : 'absolute';

            return {
                secondsRemaining: Math.max(0, Math.ceil(minRemaining / 1000)),
                warn: minRemaining <= warnBeforeSeconds * 1000,
                expired: minRemaining <= 0,
                reason,
            };
        },
    };
}
