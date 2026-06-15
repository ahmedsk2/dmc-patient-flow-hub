// Shared UI helpers. Wave 2 (Item 3) seeds this file; Wave 3's lib pass extends it (xsrf, locTone,
// FIELD, domain-constant arrays). Import from HERE rather than creating a parallel module.

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
 * Autofocus directive — focuses the element on mount. Used on search-page inputs (Item 6) so a
 * clinician can type immediately without a click. Previously redeclared inline in Patients/Index;
 * exported here so Patients / Consultations / Registry share ONE definition.
 */
export const vFocus = { mounted: (el) => el.focus() };
