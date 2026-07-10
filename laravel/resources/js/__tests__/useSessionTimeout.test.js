import { describe, it, expect } from 'vitest';
import { useSessionTimeout } from '@/composables/useSessionTimeout.js';

// A tiny controllable clock: advance(ms) moves it forward; the composable calls clock() same as
// Date.now() would. Deterministic — no fake timers / real setInterval needed for this pure math.
function makeClock(start = 0) {
    let t = start;
    return { now: () => t, advance: (ms) => { t += ms; } };
}

describe('useSessionTimeout()', () => {
    it('both limits disabled (0) -> never warns, no countdown', () => {
        const clock = makeClock();
        const s = useSessionTimeout({ idleMinutes: 0, absMinutes: 0, now: clock.now });
        clock.advance(10 * 60_000);
        expect(s.tick()).toEqual({ secondsRemaining: null, warn: false, expired: false, reason: null });
    });

    it('idle-only: counts down from idleMinutes*60, warns inside the last 60s, expires at 0', () => {
        const clock = makeClock();
        const s = useSessionTimeout({ idleMinutes: 5, absMinutes: 0, warnBeforeSeconds: 60, now: clock.now });

        clock.advance(3 * 60_000);   // 3 of 5 minutes elapsed — well outside the warn window
        let state = s.tick();
        expect(state.warn).toBe(false);
        expect(state.expired).toBe(false);
        expect(state.reason).toBe('idle');
        expect(state.secondsRemaining).toBe(120);

        clock.advance(90_000);   // now 4:30 elapsed -> 30s left, inside the 60s warn window
        state = s.tick();
        expect(state.warn).toBe(true);
        expect(state.expired).toBe(false);
        expect(state.secondsRemaining).toBe(30);

        clock.advance(30_000);   // exactly at the limit
        state = s.tick();
        expect(state.expired).toBe(true);
        expect(state.secondsRemaining).toBe(0);
    });

    it('markActivity() resets the idle clock (but not the absolute one)', () => {
        const clock = makeClock();
        const s = useSessionTimeout({ idleMinutes: 5, absMinutes: 0, now: clock.now });

        clock.advance(4 * 60_000 + 30_000);   // 30s left — inside the warn window
        expect(s.tick().warn).toBe(true);

        s.markActivity();   // user moved the mouse — idle clock restarts
        expect(s.tick().warn).toBe(false);
        expect(s.tick().secondsRemaining).toBe(300);
    });

    it('absolute-only: counts down from absMinutes*60 regardless of markActivity()', () => {
        const clock = makeClock();
        const s = useSessionTimeout({ idleMinutes: 0, absMinutes: 10, warnBeforeSeconds: 60, now: clock.now });

        clock.advance(9 * 60_000 + 30_000);   // 30s left
        s.markActivity();   // activity must NOT push the absolute limit out
        const state = s.tick();
        expect(state.reason).toBe('absolute');
        expect(state.warn).toBe(true);
        expect(state.secondsRemaining).toBe(30);
    });

    it('both enabled: reports whichever limit fires SOONER', () => {
        const clock = makeClock();
        // idle = 30 min, absolute = 5 min -> absolute is the binding constraint from the start
        const s = useSessionTimeout({ idleMinutes: 30, absMinutes: 5, now: clock.now });
        clock.advance(4 * 60_000);
        let state = s.tick();
        expect(state.reason).toBe('absolute');
        expect(state.secondsRemaining).toBe(60);

        // now flip it: idle is about to expire (no recent activity) while absolute has ages left
        const s2 = useSessionTimeout({ idleMinutes: 2, absMinutes: 60, now: clock.now });
        clock.advance(90_000);   // +90s on top of the existing 4min clock offset is irrelevant; fresh instance uses clock.now() at construction
        state = s2.tick();
        expect(state.reason).toBe('idle');
    });

    it('setSessionStart() lets the absolute clock be restored (e.g. from sessionStorage) instead of starting at construction time', () => {
        const clock = makeClock(1_000_000);
        const s = useSessionTimeout({ idleMinutes: 0, absMinutes: 10, now: clock.now });
        // pretend the real session started 9 minutes before this composable was constructed
        s.setSessionStart(1_000_000 - 9 * 60_000);
        clock.advance(30_000);   // +30s -> 9:30 since the RESTORED start -> 30s left of the 10-minute cap
        const state = s.tick();
        expect(state.reason).toBe('absolute');
        expect(state.secondsRemaining).toBe(30);
        expect(state.warn).toBe(true);
    });

    it('getSessionStart() returns the current absolute-clock anchor', () => {
        const clock = makeClock(500);
        const s = useSessionTimeout({ now: clock.now });
        expect(s.getSessionStart()).toBe(500);
        s.setSessionStart(123);
        expect(s.getSessionStart()).toBe(123);
    });

    it('respects a custom warnBeforeSeconds', () => {
        const clock = makeClock();
        const s = useSessionTimeout({ idleMinutes: 5, absMinutes: 0, warnBeforeSeconds: 120, now: clock.now });
        clock.advance(3 * 60_000 + 10_000);   // 110s left -> inside a 120s warn window, outside a 60s one
        expect(s.tick().warn).toBe(true);
    });
});
