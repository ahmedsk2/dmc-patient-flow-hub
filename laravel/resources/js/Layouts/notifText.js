/**
 * Bell copy + click destination, one function per concern, extracted from AppLayout.vue so the
 * per-type rendering is unit-testable. Behaviour for every pre-existing type is unchanged.
 */
export function notifText(n) {
    const p = n.payload || {};
    // Phase 4 — Item 3: failed-login burst alert
    if (n.type === 'security.failed_logins') {
        return `Security: ${p.count || ''} failed login attempt(s) for "${p.username || 'unknown'}"${p.ip ? ` from ${p.ip}` : ''}`;
    }
    // Phase 4 — Item 6: daily data-quality digest
    if (n.type === 'dq.daily_report') {
        const total = (p.over_los || 0) + (p.no_dx || 0) + (p.bad_dates || 0) + (p.orphan_dx || 0) + (p.double_open || 0);
        return `Data quality: ${total} item(s) need review (see the Data Quality page)`;
    }
    // HO-T7: persistent "incomplete handover" reminder
    if (n.type === 'handover.incomplete') {
        return `${p.patient_name || 'A patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''} was reassigned from Dr. ${p.from_name || '—'} without a completed handover — complete it.`;
    }
    // Wave 2b: a coordinator booked (or moved) a consult into your book
    if (n.type === 'consultation.assigned') {
        const verb = p.event === 'reassigned' ? 'reassigned' : 'booked';
        return `${p.by_name || 'A coordinator'} ${verb} a ${p.service || 'consultation'} consult for ${p.patient_name || 'a patient'} to you`;
    }
    if (p.count) return `Dr. ${p.from_name || 'A consultant'} handed over ${p.count} patient(s) to you`;
    return `Dr. ${p.from_name || 'A consultant'} handed over ${p.patient_name || 'a patient'}${p.mrn ? ` (MRN ${p.mrn})` : ''}`;
}

/** Where clicking a feed entry goes. Consultation entries belong to the consultations workspace. */
export function feedTarget(n) {
    return n.type === 'consultation.assigned' ? '/consultations' : '/handovers';
}
