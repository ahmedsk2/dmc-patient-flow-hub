// Shared UI helpers. Wave 2 (Item 3) seeds this file; Wave 3's lib pass extends it (xsrf, locTone,
// FIELD, domain-constant arrays, consultantOptions). Import from HERE rather than creating a
// parallel module — these were previously redeclared inline across 10+ pages.

/**
 * Returns today's date as YYYY-MM-DD in the browser's LOCAL timezone.
 *
 * Do NOT use new Date().toISOString().slice(0,10) — that gives the UTC date, which is the PREVIOUS
 * calendar day in UTC+ timezones (KSA is UTC+3) for any wall-clock time before 03:00 local. A
 * night-shift admission entered at 01:00 KSA would otherwise default to "yesterday".
 */
export function localToday() {
    const d = new Date();
    return [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, '0'),
        String(d.getDate()).padStart(2, '0'),
    ].join('-');
}

/**
 * Wave 5, Item 3: consistent "DD MMM YYYY" date rendering (e.g. "08 Jul 2026") for the raw
 * YYYY-MM-DD/ISO-datetime strings the server sends. Several pages echoed those raw strings directly
 * (Dashboard's recent-admissions date, patient-card "Admitted" lines) — inconsistent with the rest of
 * the app's formatted dates and not locale-friendly. Null/invalid-safe: never throws, never renders
 * "Invalid Date" — returns '' so a template can fall back to its own placeholder (e.g. `|| '—'`).
 *
 * Parses the 'YYYY-MM-DD[...]' shape with a plain string split rather than `new Date(str)` — the
 * latter treats a bare 'YYYY-MM-DD' as UTC midnight and would print the PREVIOUS day in negative-UTC
 * zones, the exact bug localToday() above exists to dodge. A full ISO datetime (with a 'T') keeps
 * only its date portion, so the same UTC-shift risk never applies to the calendar digits.
 *
 * @param {string|null|undefined} value  'YYYY-MM-DD' or an ISO datetime string
 * @returns {string} 'DD MMM YYYY', or '' when value is empty/null/unparseable.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function formatDate(value) {
    if (!value) return '';
    const datePart = String(value).slice(0, 10);
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(datePart);
    if (!m) return '';
    const [, y, mo, d] = m;
    const year = Number(y), month = Number(mo), day = Number(d);
    if (year === 0 || month < 1 || month > 12 || day < 1 || day > 31) return '';   // rejects 0000-00-00 etc.
    return `${String(day).padStart(2, '0')} ${MONTHS[month - 1]} ${year}`;
}

/**
 * Autofocus directive — focuses the element on mount. Used on search-page inputs (Item 6) so a
 * clinician can type immediately without a click. Previously redeclared inline in Patients/Index;
 * exported here so Patients / Consultations / Registry share ONE definition.
 */
export const vFocus = { mounted: (el) => el.focus() };

/**
 * XSRF token read from Laravel's `XSRF-TOKEN` cookie (the value the app already sends as the
 * `X-XSRF-TOKEN` header on raw fetch() writes). Replaces the three identical inline copies in
 * Patients/Index, AppLayout, and Admin/PatientMerge. Note: this is the COOKIE-based token, not a
 * meta-tag — every existing fetch() in this codebase uses the cookie, so this preserves behavior.
 */
export function xsrf() {
    return decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
}

/**
 * Domain-constant arrays. These mirror the inline copies that several modals re-declared. Where a
 * page receives the list as a SERVER PROP (e.g. Admissions/Create's `admitFrom`), that prop stays
 * the source of truth — these constants are the fallback for inline modals that had no prop.
 */
// "Admitted from" datalist options (board/queue/registry Modify modals).
export const ADMIT_FROM_OPTIONS = ['ER', 'Clinic', 'OPD', 'OR', 'ICU', 'Referral', 'Transfer', 'Direct', 'Other service'];
// Controlled "Discharged to" destination vocabulary (legacy list); a death locks to Mortuary.
export const DISCHARGE_DESTINATIONS = ['Home', 'Other Facility', 'LAMA', 'Absconded', 'Mortuary'];
// Outcome (status) vocabulary — strictly Alive/Dead; LAMA/Absconded are DESTINATIONS, not outcomes.
export const OUTCOME_STATUSES = ['Alive', 'Dead'];

/**
 * Maps a patient's current_location to its pill token classes. Single source of truth for the four
 * inlined copies (Patients/Index, Admissions/Index, ActiveList, Dashboard). ICU=danger, ER=warning,
 * everything else (Ward/—)=brand.
 */
export function locTone(loc) {
    return loc === 'ICU' ? 'bg-tint-danger text-on-danger'
        : loc === 'ER' ? 'bg-tint-warning text-on-warning'
        : 'bg-brand-100 text-brand-700';
}

/**
 * Canonical form-input class string. Matches IcdTypeahead's default inputClass and the Admissions
 * `fld` const. Components import this constant (avoids Tailwind purge concerns with dynamic class
 * assembly); the equivalent `.field` utility in app.css is the same string for non-component HTML.
 */
export const FIELD = 'w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500';

/**
 * KPI delta-chip token pair (W4). The chip's colour is driven by the KPI's own POLARITY plus the
 * delta DIRECTION — NOT by the raw number: an increase on a 'bad' KPI (mortality, readmissions,
 * boarding, occupancy-high, avg-LOS-up) is danger; an increase on a 'good' KPI (discharges) is
 * success; a 'neutral' KPI (census, admissions) is always ink; a flat delta is always ink. Meaning
 * is ALSO carried by the arrow glyph + number in the label, so colour is never the only signal
 * (WCAG 1.4.1). Uses the AA-verified, theme-aware `bg-tint-*` + `text-on-*` pairs — never
 * `text-{status}-{n}`, which the a11y-status-text guard forbids on the theme-aware surfaces.
 *
 * @param {'good'|'bad'|'neutral'} polarity   whether a rise in this KPI is good, bad, or neither
 * @param {'up'|'down'|'flat'}     direction  the delta's direction
 */
export function deltaChipClass(polarity, direction) {
    if (polarity === 'neutral' || direction === 'flat') return 'bg-ink-100 text-ink-500';
    const up = direction === 'up';
    if (polarity === 'good') return up ? 'bg-tint-success text-on-success' : 'bg-tint-danger text-on-danger';
    if (polarity === 'bad') return up ? 'bg-tint-danger text-on-danger' : 'bg-tint-success text-on-success';
    return 'bg-ink-100 text-ink-500';
}

/**
 * guardSubmit — double-submit backstop (Wave 3, Item 5). `:disabled="form.processing"` on the
 * submit button is the PRIMARY guard; this wraps the submit handler itself so a race (a keyboard
 * Enter, or a second click landing before Vue re-renders the disabled attribute) still can't fire
 * the request twice. Checks `form.processing` at CALL time, not at wrap time, so it stays correct
 * across the form's whole lifetime.
 *
 * @param {{ processing: boolean }} form   an Inertia useForm() instance (or anything processing-shaped)
 * @param {Function} fn                    the real submit handler
 */
export function guardSubmit(form, fn) {
    return (...args) => { if (form.processing) return; return fn(...args); };
}

/**
 * Returns a filtered consultant list for a dropdown — names the "on-service, plus keep the current
 * assignee even when off-service, optionally narrow by specialty" rule that was re-expressed across
 * Patients / Admissions / Registry. PRESERVES the existing (unsorted) order of the source list so
 * the rendered <option> order is unchanged.
 *
 * @param {Array}  consultants            full list from page props ({id, name, on_service, specialty_id})
 * @param {Object} [opts]
 * @param {number|string|null} opts.keepId         always include this id even if off-service
 * @param {number|null}        opts.specialtyId    if set, narrow to this specialty (on-service members)
 * @param {boolean}            opts.onServiceOnly  if true, exclude off-service (and never keep keepId)
 */
export function consultantOptions(consultants, { keepId = null, specialtyId = null, onServiceOnly = false } = {}) {
    return (consultants || []).filter((c) => {
        if (onServiceOnly) return c.on_service;
        if (specialtyId != null) return c.specialty_id === specialtyId && c.on_service;
        return c.on_service || c.id === keepId;
    });
}
