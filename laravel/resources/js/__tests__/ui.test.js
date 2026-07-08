import { describe, it, expect, vi, afterEach } from 'vitest';
import {
    localToday, vFocus, xsrf, locTone, consultantOptions, FIELD,
    ADMIT_FROM_OPTIONS, DISCHARGE_DESTINATIONS, OUTCOME_STATUSES,
} from '@/lib/ui.js';

afterEach(() => { vi.useRealTimers(); });

describe('localToday()', () => {
    it('returns the LOCAL calendar date, not the UTC-previous date', () => {
        // 2026-06-14T00:30:00Z = 03:30 KSA (UTC+3) — same calendar date locally, but if the test
        // host happens to run in a UTC+ zone, toISOString().slice(0,10) could disagree. We assert
        // against the host-local YYYY-MM-DD via Intl, which is exactly what localToday() must equal.
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-14T00:30:00Z'));
        const expected = new Intl.DateTimeFormat('en-CA', {
            year: 'numeric', month: '2-digit', day: '2-digit',
        }).format(new Date());
        expect(localToday()).toBe(expected);
    });

    it('matches the en-CA local format (YYYY-MM-DD) with no mocking', () => {
        const expected = new Intl.DateTimeFormat('en-CA', {
            year: 'numeric', month: '2-digit', day: '2-digit',
        }).format(new Date());
        expect(localToday()).toBe(expected);
        expect(localToday()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });
});

describe('vFocus directive', () => {
    it('focuses the element on mount', () => {
        const el = { focus: vi.fn() };
        vFocus.mounted(el);
        expect(el.focus).toHaveBeenCalledTimes(1);
    });
});

describe('xsrf()', () => {
    afterEach(() => { document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'; });
    it('returns the decoded XSRF-TOKEN cookie value', () => {
        document.cookie = 'XSRF-TOKEN=' + encodeURIComponent('abc%def==');
        expect(xsrf()).toBe('abc%def==');
    });
    it('returns an empty string when the cookie is absent', () => {
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        expect(xsrf()).toBe('');
    });
});

describe('locTone()', () => {
    it('maps ICU → danger, ER → warning, else → brand', () => {
        expect(locTone('ICU')).toContain('text-on-danger');
        expect(locTone('ER')).toContain('text-on-warning');
        expect(locTone('Ward')).toContain('text-brand-700');
        expect(locTone(null)).toContain('text-brand-700');
    });
});

describe('FIELD + domain constants', () => {
    it('FIELD is the canonical input class string', () => {
        expect(FIELD).toContain('rounded-xl');
        expect(FIELD).toContain('border-ink-200');
        expect(FIELD).toContain('focus:border-brand-500');
    });
    it('exposes the domain vocabularies', () => {
        expect(ADMIT_FROM_OPTIONS).toContain('ER');
        expect(DISCHARGE_DESTINATIONS).toContain('Mortuary');
        expect(OUTCOME_STATUSES).toEqual(['Alive', 'Dead']);
    });
});

describe('consultantOptions()', () => {
    const list = [
        { id: 1, name: 'A', on_service: true, specialty_id: 1 },
        { id: 2, name: 'B', on_service: false, specialty_id: 1 },
        { id: 3, name: 'C', on_service: true, specialty_id: 2 },
    ];
    it('returns on-service consultants by default, preserving source order', () => {
        expect(consultantOptions(list).map((c) => c.id)).toEqual([1, 3]);
    });
    it('keepId includes an off-service consultant', () => {
        expect(consultantOptions(list, { keepId: 2 }).map((c) => c.id)).toEqual([1, 2, 3]);
    });
    it('specialtyId narrows to on-service members of that specialty', () => {
        expect(consultantOptions(list, { specialtyId: 1 }).map((c) => c.id)).toEqual([1]);
    });
    it('onServiceOnly excludes off-service even when keepId is given', () => {
        expect(consultantOptions(list, { keepId: 2, onServiceOnly: true }).map((c) => c.id)).toEqual([1, 3]);
    });
    it('tolerates a null/undefined list', () => {
        expect(consultantOptions(undefined)).toEqual([]);
    });
});
