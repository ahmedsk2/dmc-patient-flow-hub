// Wave 2, Item 10: first-login onboarding tour controller (driver.js).
//   • startTour()       — build role/DOM-filtered steps, instantiate driver.js, drive it (replay-safe).
//   • completeTour()    — POST /tour/complete (server per-user flag) + suppress for this session.
//   • maybeAutoStart()  — auto-start ONCE per load when the server flag is null AND the route isn't
//                         excluded (/login, /mfa*, /forgot*, /reset*, error pages).
// Any EXIT of the AUTO tour (finish / "don't show again" / dismiss) sets the flag so it never nags;
// the header "?" replay (startTour with auto=false) never touches the flag.

import { driver } from 'driver.js';
import { buildSteps } from '@/lib/tourSteps.js';

// session-scoped guard so an in-progress / just-finished auto tour doesn't re-fire on the next
// Inertia visit within the same page life. Module-level (one per loaded app instance).
let suppressed = false;

const xsrf = () =>
    decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');

// Routes where the tour must never auto-start (pre-auth / error chrome).
const EXCLUDED = [/^\/login/, /^\/mfa/, /^\/forgot/, /^\/reset/, /^\/(400|401|403|404|413|419|429|500|503)\b/];
export const isExcludedRoute = (path) => EXCLUDED.some((re) => re.test(path || ''));

export function useTour() {
    // POST the "seen" flag (fire-and-forget) + suppress further auto-starts this session.
    const completeTour = () => {
        suppressed = true;
        return fetch('/tour/complete', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        }).catch(() => { /* preference write — never block the UI on a failure */ });
    };

    // Build + run the tour. auto=true (the first-login run) sets the flag on ANY exit; auto=false
    // (the ? replay) just runs. Returns the driver instance (or null when there are no steps).
    const startTour = (user = {}, { auto = false } = {}) => {
        const steps = buildSteps(user);
        // welcome + finish are always present; if somehow only those exist that's still a valid tour.
        const drv = driver({
            showProgress: true,
            allowClose: true,
            overlayOpacity: 0.6,
            popoverClass: 'dmc-tour',
            nextBtnText: 'Next',
            prevBtnText: 'Back',
            doneBtnText: auto ? "Don't show again" : 'Done',
            steps,
            // Any teardown of the AUTO tour counts as "seen": finishing, the done/don't-show button,
            // Esc, ✕, or backdrop click all route through onDestroyed.
            onDestroyed: () => { if (auto) completeTour(); },
        });
        drv.drive();
        return drv;
    };

    // Called on AppLayout mount. Start the auto-tour iff: not already suppressed this session, the
    // server flag is null (never seen), and the current route isn't excluded. The short paint delay
    // lets data-tour anchors mount before driver.js measures them.
    const maybeAutoStart = (user, path) => {
        if (suppressed) return false;
        if (!user || user.tour_completed_at != null) return false;
        if (isExcludedRoute(path)) return false;
        setTimeout(() => { if (!suppressed) startTour(user, { auto: true }); }, 400);
        return true;
    };

    return { startTour, completeTour, maybeAutoStart };
}
