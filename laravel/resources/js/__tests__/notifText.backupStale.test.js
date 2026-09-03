import { describe, it, expect } from 'vitest';

// DATA-02 — the bell must render a `backup.stale` entry (raised by `php artisan backup:verify`)
// in plain words that say WHY, and route it to the Control Panel rather than the handover inbox.

import { notifText, feedTarget } from '@/Layouts/notifText.js';

describe('bell rendering — backup.stale', () => {
    it('says how old a stale backup is and what the limit was', () => {
        const text = notifText({ type: 'backup.stale', payload: { reason: 'stale', age_hours: 30.5, max_age_hours: 26 } });
        expect(text).toMatch(/^Database backup:/);
        expect(text).toContain('30.5h old');
        expect(text).toContain('limit 26h');
    });

    it('distinguishes missing / error / unconfigured', () => {
        expect(notifText({ type: 'backup.stale', payload: { reason: 'missing' } })).toContain('missing from the backup bucket');
        expect(notifText({ type: 'backup.stale', payload: { reason: 'error' } })).toContain('storage error');
        expect(notifText({ type: 'backup.stale', payload: { reason: 'unconfigured' } })).toContain('not configured');
    });

    it('never falls through to the handover wording, even with an unknown reason or no payload', () => {
        for (const n of [{ type: 'backup.stale', payload: { reason: 'weird' } }, { type: 'backup.stale' }]) {
            const text = notifText(n);
            expect(text).toMatch(/^Database backup:/);
            expect(text).not.toMatch(/handed over/);
        }
    });

    it('routes to the Control Panel and leaves the other types untouched', () => {
        expect(feedTarget({ type: 'backup.stale' })).toBe('/control');
        expect(feedTarget({ type: 'consultation.assigned' })).toBe('/consultations');
        expect(feedTarget({ type: 'handover.signed' })).toBe('/handovers');
    });
});
