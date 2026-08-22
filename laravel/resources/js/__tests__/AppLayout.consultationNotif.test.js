import { describe, it, expect } from 'vitest';

// Wave 2b — the bell must render a `consultation.assigned` entry in words and route it to the
// consultations workspace rather than the handover inbox. notifText/feedTarget are exported from
// AppLayout.vue's <script setup> via defineExpose, so they are testable without a full mount.

import { notifText, feedTarget } from '@/Layouts/notifText.js';

describe('bell rendering — consultation.assigned', () => {
    it('reads as a sentence naming the booker, the patient and the service', () => {
        const text = notifText({
            type: 'consultation.assigned',
            payload: { patient_name: 'Attribution Pt', mrn: '72000001', service: 'Cardiology', by_name: 'Dr Coordinator', event: 'created' },
        });
        expect(text).toContain('Dr Coordinator');
        expect(text).toContain('Attribution Pt');
        expect(text).toContain('Cardiology');
    });

    it('says "reassigned" when that is the event', () => {
        const text = notifText({
            type: 'consultation.assigned',
            payload: { patient_name: 'Reassign Pt', service: 'Cardiology', by_name: 'Dr Coordinator', event: 'reassigned' },
        });
        expect(text).toMatch(/reassigned/i);
    });

    it('routes a consultation notification to the consultations workspace', () => {
        expect(feedTarget({ type: 'consultation.assigned' })).toBe('/consultations');
    });

    it('leaves every other type routed to the handover inbox', () => {
        expect(feedTarget({ type: 'handover.signed' })).toBe('/handovers');
    });

    it('still renders the handover types unchanged', () => {
        expect(notifText({ type: 'handover.incomplete', payload: { patient_name: 'P', mrn: '1', from_name: 'X', to_name: 'Y' } }))
            .toContain('without a completed handover');
        expect(notifText({ type: 'security.failed_logins', payload: { count: 3, username: 'bob' } }))
            .toContain('failed login');
    });
});
