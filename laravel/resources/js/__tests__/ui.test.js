import { describe, it, expect, vi, afterEach } from 'vitest';
import {
    localToday, vFocus, xsrf, locTone, consultantOptions, FIELD,
    ADMIT_FROM_OPTIONS, DISCHARGE_DESTINATIONS, OUTCOME_STATUSES, guardSubmit, formatDate,
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

// Wave 5, Item 3: consistent DD MMM YYYY date rendering (null/invalid-safe — never throws, never
// prints "Invalid Date"). Parses the 'YYYY-MM-DD' shape the server sends directly (no Date() parsing
// of the plain date string, which would apply the BROWSER's timezone and can roll the day backward —
// same class of bug localToday() above exists to avoid).
describe('formatDate()', () => {
    it('formats an ISO YYYY-MM-DD date as DD MMM YYYY', () => {
        expect(formatDate('2026-07-08')).toBe('08 Jul 2026');
    });
    it('formats an ISO datetime (date + time) the same way', () => {
        expect(formatDate('2026-01-05T14:30:00Z')).toBe('05 Jan 2026');
    });
    it('pads single-digit days', () => {
        expect(formatDate('2026-12-01')).toBe('01 Dec 2026');
    });
    it('returns an empty string for null/undefined/empty input', () => {
        expect(formatDate(null)).toBe('');
        expect(formatDate(undefined)).toBe('');
        expect(formatDate('')).toBe('');
    });
    it('returns an empty string for an unparseable value (never "Invalid Date")', () => {
        expect(formatDate('not-a-date')).toBe('');
        expect(formatDate('0000-00-00')).toBe('');   // legacy MySQL zero-date
    });
    // Prod-readiness fix: days-in-month is now validated (leap-year aware), not just the crude 1-31
    // range check above. Pure arithmetic — no `new Date()` — per the same reasoning as localToday():
    // a Date-based check would run in the BROWSER's timezone and could roll the parsed calendar date.
    it('rejects an impossible day for the given month (30/31-day months)', () => {
        expect(formatDate('2026-02-31')).toBe('');   // Feb never has 31 days
        expect(formatDate('2026-04-31')).toBe('');   // Apr has 30 days
    });
    it('accepts Feb 29 in a leap year', () => {
        expect(formatDate('2024-02-29')).toBe('29 Feb 2024');   // 2024 % 4 == 0, % 100 != 0
    });
    it('rejects Feb 29 in a non-leap year', () => {
        expect(formatDate('2023-02-29')).toBe('');
    });
});

// Wave 3, Item 5: double-submit guard. `:disabled="form.processing"` is the primary UI guard;
// guardSubmit is the belt-and-suspenders backstop for a race where a keyboard Enter (or a second
// click before Vue re-renders the disabled attribute) fires the handler again while the first
// request is still in flight.
describe('guardSubmit()', () => {
    it('calls fn when the form is not processing', () => {
        const form = { processing: false };
        const fn = vi.fn();
        guardSubmit(form, fn)('a', 'b');
        expect(fn).toHaveBeenCalledWith('a', 'b');
    });

    it('no-ops when the form is already processing', () => {
        const form = { processing: true };
        const fn = vi.fn();
        guardSubmit(form, fn)();
        expect(fn).not.toHaveBeenCalled();
    });

    it('checks processing at CALL time, not at wrap time', () => {
        const form = { processing: false };
        const fn = vi.fn();
        const guarded = guardSubmit(form, fn);
        form.processing = true;   // flips after wrapping, before calling
        guarded();
        expect(fn).not.toHaveBeenCalled();
    });
});
