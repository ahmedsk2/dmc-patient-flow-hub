import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock driver.js: capture the config it's instantiated with + expose a drive() spy. The factory
// returns an object whose drive() we can assert, and we keep the last config so tests can invoke
// onDestroyed (simulating finish / dismiss / "don't show again").
const { driveSpy, lastConfig } = vi.hoisted(() => ({ driveSpy: vi.fn(), lastConfig: { value: null } }));
vi.mock('driver.js', () => ({
    driver: (config) => { lastConfig.value = config; return { drive: driveSpy }; },
}));

import { isExcludedRoute } from '@/composables/useTour.js';
import { buildSteps } from '@/lib/tourSteps.js';

const admin = { role: 0, is_admin: true, can: {}, tour_completed_at: null };
const consultant = { role: 3, is_admin: false, can: {}, tour_completed_at: null };

beforeEach(() => {
    driveSpy.mockClear();
    lastConfig.value = null;
    vi.useFakeTimers();
    global.fetch = vi.fn(() => Promise.resolve({ ok: true }));
    document.cookie = 'XSRF-TOKEN=test-token';
    document.body.innerHTML = '';
    // reset the module-level `suppressed` flag by re-importing fresh per test
    vi.resetModules();
});
afterEach(() => { vi.useRealTimers(); });

// helper to get a FRESH module instance (module-level `suppressed` resets) for isolation
async function fresh() {
    vi.resetModules();
    return await import('@/composables/useTour.js');
}

describe('isExcludedRoute', () => {
    it('excludes auth/error chrome but not app routes', () => {
        expect(isExcludedRoute('/login')).toBe(true);
        expect(isExcludedRoute('/mfa/challenge')).toBe(true);
        expect(isExcludedRoute('/forgot-password')).toBe(true);
        expect(isExcludedRoute('/reset-password/abc')).toBe(true);
        expect(isExcludedRoute('/404')).toBe(true);
        expect(isExcludedRoute('/patients')).toBe(false);
        expect(isExcludedRoute('/')).toBe(false);
    });
});

describe('maybeAutoStart', () => {
    it('auto-starts when flag is null AND route is not excluded', async () => {
        const { useTour: ut } = await fresh();
        const { maybeAutoStart } = ut();
        const started = maybeAutoStart(consultant, '/patients');
        expect(started).toBe(true);
        vi.runAllTimers();
        expect(driveSpy).toHaveBeenCalledTimes(1);
    });

    it('does NOT auto-start when the flag is already set', async () => {
        const { useTour: ut } = await fresh();
        const { maybeAutoStart } = ut();
        const started = maybeAutoStart({ ...consultant, tour_completed_at: '2026-06-14T00:00:00+00:00' }, '/patients');
        expect(started).toBe(false);
        vi.runAllTimers();
        expect(driveSpy).not.toHaveBeenCalled();
    });

    it('does NOT auto-start on an excluded route', async () => {
        const { useTour: ut } = await fresh();
        const { maybeAutoStart } = ut();
        expect(maybeAutoStart(consultant, '/login')).toBe(false);
        vi.runAllTimers();
        expect(driveSpy).not.toHaveBeenCalled();
    });

    it('does NOT auto-start with no user', async () => {
        const { useTour: ut } = await fresh();
        const { maybeAutoStart } = ut();
        expect(maybeAutoStart(null, '/patients')).toBe(false);
    });
});

describe('completeTour', () => {
    it('POSTs to /tour/complete and suppresses further auto-start in the session', async () => {
        const { useTour: ut } = await fresh();
        const { completeTour, maybeAutoStart } = ut();
        await completeTour();
        expect(global.fetch).toHaveBeenCalledWith('/tour/complete', expect.objectContaining({ method: 'POST' }));
        // now an auto-start attempt is suppressed even though the flag is null
        expect(maybeAutoStart(consultant, '/patients')).toBe(false);
    });
});

describe('startTour', () => {
    it('replay (auto=false) runs WITHOUT POSTing, even on teardown', async () => {
        const { useTour: ut } = await fresh();
        const { startTour } = ut();
        startTour(consultant, { auto: false });
        expect(driveSpy).toHaveBeenCalledTimes(1);
        // simulate the user closing the replay → onDestroyed must NOT post
        lastConfig.value.onDestroyed?.();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('auto tour teardown (finish / dismiss / don\'t-show) POSTs the flag', async () => {
        const { useTour: ut } = await fresh();
        const { startTour } = ut();
        startTour(consultant, { auto: true });
        expect(lastConfig.value.doneBtnText).toBe("Don't show again");
        lastConfig.value.onDestroyed?.();   // any exit of the auto tour
        expect(global.fetch).toHaveBeenCalledWith('/tour/complete', expect.objectContaining({ method: 'POST' }));
    });
});

describe('buildSteps role + DOM filtering', () => {
    it('drops the admin-only Administration step for a non-admin', () => {
        document.body.innerHTML = '<div data-tour="nav-clinical"></div><div data-tour="nav-admin"></div>';
        const steps = buildSteps(consultant);
        const els = steps.map((s) => s.popover?.title);
        expect(els).toContain('Clinical navigation');
        expect(els).not.toContain('Administration');   // admin step filtered out for a consultant
    });

    it('includes the Administration step for an admin when its anchor is present', () => {
        document.body.innerHTML = '<div data-tour="nav-clinical"></div><div data-tour="nav-admin"></div>';
        const steps = buildSteps(admin);
        expect(steps.map((s) => s.popover?.title)).toContain('Administration');
    });

    it('drops steps whose data-tour anchor is absent from the DOM', () => {
        document.body.innerHTML = '<div data-tour="nav-clinical"></div>';   // no quick-jump / board / bell
        const steps = buildSteps(admin);
        const titles = steps.map((s) => s.popover?.title);
        expect(titles).toContain('Clinical navigation');
        expect(titles).not.toContain('Jump to any patient');   // absent anchor → dropped
        expect(titles).not.toContain('The patient board');
    });

    it('always includes a centered welcome + finish step (no element)', () => {
        document.body.innerHTML = '';
        const steps = buildSteps(consultant);
        expect(steps.length).toBeGreaterThanOrEqual(2);
        expect(steps[0].element).toBeUndefined();
        expect(steps[steps.length - 1].element).toBeUndefined();
    });
});
