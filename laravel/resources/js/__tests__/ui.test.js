import { describe, it, expect, vi, afterEach } from 'vitest';
import { localToday, vFocus } from '@/lib/ui.js';

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
