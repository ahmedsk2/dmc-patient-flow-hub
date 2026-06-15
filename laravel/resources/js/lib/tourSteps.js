// Wave 2, Item 10: the onboarding-tour step list. buildSteps(user) returns a driver.js step array,
// filtered (a) by role/capability and (b) by DOM presence — a step whose data-tour anchor is absent
// (role-hidden nav, or the quick-jump if Item 2 hasn't shipped) is dropped, so the tour degrades
// gracefully. Welcome + finish are centered (no element). The finish step carries "Don't show again".

const present = (selector) =>
    typeof document !== 'undefined' && document.querySelector(selector) !== null;

/**
 * @param {object} user  auth.user — { role, is_admin, can: {...} }
 * @returns {Array} driver.js steps
 */
export function buildSteps(user = {}) {
    const isAdmin = !!user.is_admin;

    // Centered welcome (no element) — always shown.
    const welcome = {
        popover: {
            title: 'Welcome to DMC Internal Medicine',
            description: 'A quick 60-second tour of where things live. You can replay it any time from the ? button in the header.',
            side: 'over', align: 'center',
        },
    };

    // Candidate element-anchored steps, in tour order. `admin: true` ones are only offered to admins.
    const candidates = [
        {
            el: '[data-tour="nav-clinical"]',
            title: 'Clinical navigation',
            description: 'Your day-to-day workspaces — the board, new admissions, handovers, consultations and recent activity — live here.',
            side: 'right', align: 'start',
        },
        {
            el: '[data-tour="quick-jump"]',
            title: 'Jump to any patient',
            description: 'Press / from anywhere to search a patient by MRN or name and jump straight to them.',
            side: 'bottom', align: 'end',
        },
        {
            el: '[data-tour="dashboard-hero"]',
            title: 'At-a-glance KPIs',
            description: 'The live census, occupancy and key counts for the unit. Click a tile to drill into the matching board view.',
            side: 'bottom', align: 'center',
        },
        {
            el: '[data-tour="board"]',
            title: 'The patient board',
            description: 'Active patients grouped by consultant. Assign, discharge, transfer and hand over right from each card.',
            side: 'top', align: 'center',
        },
        {
            el: '[data-tour="bell"]',
            title: 'Notifications',
            description: 'Handover transfers and alerts land here. The badge shows what still needs your attention.',
            side: 'bottom', align: 'end',
        },
        {
            el: '[data-tour="nav-admin"]',
            title: 'Administration',
            description: 'Analytics & Reports, Governance & Safety, Data Management and Settings — the four admin sections.',
            side: 'right', align: 'start',
            admin: true,
        },
    ];

    const elementSteps = candidates
        // role filter: admin-only steps require an admin
        .filter((c) => (c.admin ? isAdmin : true))
        // DOM-presence filter: drop steps whose anchor isn't on the page (role-hidden / not-yet-shipped)
        .filter((c) => present(c.el))
        .map((c) => ({
            element: c.el,
            popover: { title: c.title, description: c.description, side: c.side, align: c.align },
        }));

    // Centered finish (no element) — carries the "don't show again" affordance (wired in useTour).
    const finish = {
        popover: {
            title: "You're all set",
            description: 'That\'s the tour. Replay it any time from the ? button in the header.',
            side: 'over', align: 'center',
        },
    };

    return [welcome, ...elementSteps, finish];
}
