import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PatientFlags from '@/Components/PatientFlags.vue';

const mountFlags = (patient, props = {}) =>
    mount(PatientFlags, { props: { patient, readmitWindow: 3, ...props } });

// Mount with exactly ONE flag raised, so `.get('span')` is unambiguously that badge.
const only = (flag, extra = {}, props = {}) => mountFlags({ [flag]: true, ...extra }, props);

// The raw status colours. Every one of these FAILS WCAG AA (4.5:1) as <=14px normal-weight text on
// its own `*-100` tint — proven by scripts/contrast.mjs: warning-500/warning-100 = 2.15:1,
// info-500/info-100 = 3.24:1, danger-600/danger-100 = 4.39:1. The negative assertions below are the
// regression guard: they are what stops the pre-Wave-0 colours creeping back in. `accent` is
// deliberately ABSENT from this list — it has no `on-*`/`tint-*` pair, so the Long-term badge is a
// known, reported exception rather than a silently-tolerated one (see its own test).
const RAW_STATUS_TEXT = [
    'text-info-500', 'text-info-600', 'text-success-500', 'text-success-600',
    'text-warning-500', 'text-warning-600', 'text-danger-500', 'text-danger-600',
];
// `*-100` fills are theme-INVARIANT literals, so on a dark page they render a light peach/blue pill
// on a near-black card (warning-100 vs the dark card = 14.53:1 — it screams). The `tint-*` fills are
// theme-aware and settle to a subtle chip (1.21:1). Guard the fill as well as the text.
const RAW_STATUS_FILL = ['bg-info-100', 'bg-success-100', 'bg-warning-100', 'bg-danger-100'];

describe('PatientFlags (badge variant)', () => {
    it('renders the badges that are set', () => {
        const w = mountFlags({ is_new: true, is_tb: true, is_longterm: false, is_readmission: false });
        expect(w.text()).toContain('New');
        expect(w.text()).toContain('TB');
        expect(w.text()).not.toContain('Long-term');
    });
    it('shows the readmit window in the Readmit badge', () => {
        const w = mountFlags({ is_readmission: true }, { readmitWindow: 7 });
        expect(w.text()).toContain('Readmit ≤7d');
    });
    it('shows the Discharged date chip (badge only) and hides Disch-still-in', () => {
        const w = mountFlags({ discharged: true, discharge_date: '2026-06-01', medically_discharged: true });
        expect(w.text()).toContain('Discharged 2026-06-01');
        expect(w.text()).not.toContain('Disch. still in');
    });
    it('shows Disch-still-in when medically (not fully) discharged', () => {
        const w = mountFlags({ medically_discharged: true });
        expect(w.text()).toContain('Disch. still in');
    });
    it('renders nothing when no flags are set', () => {
        const w = mountFlags({});
        expect(w.text()).toBe('');
    });
});

describe('PatientFlags (plain variant)', () => {
    it('renders plain text spans (no pill background) and no Discharged-date chip', () => {
        const w = mountFlags({ is_new: true, discharged: true, discharge_date: '2026-06-01', medically_discharged: true }, { variant: 'plain' });
        expect(w.text()).toContain('New');
        expect(w.text()).not.toContain('Discharged 2026-06-01');
        // plain variant uses font-semibold text spans, not rounded-full pills
        expect(w.html()).not.toContain('rounded-full');
    });
    it('shows Disch-still-in in plain variant', () => {
        const w = mountFlags({ medically_discharged: true }, { variant: 'plain' });
        expect(w.text()).toContain('Disch. still in');
    });
});

// ---------------------------------------------------------------------------------------------
// WCAG AA (W0-T3d). The badges shipped the raw `*-500`/`*-600` status colours as <=10px semibold
// text on their own `*-100` tint. WCAG 1.4.3's large-text 3:1 allowance needs 24px (or 18.66px
// bold), so 4.5:1 is the bar and every one of them missed it. Migrated to the theme-aware
// `bg-tint-*` + `text-on-*` pairs that scripts/contrast.mjs certifies at 5.14:1 -> 8.25:1 in BOTH
// themes. FlowAlert.vue is the reference consumer; these mirror its guard.
// ---------------------------------------------------------------------------------------------
describe('PatientFlags — AA-safe colour tokens', () => {
    it.each([
        ['is_new', 'bg-tint-info', 'text-on-info'],                 // was info-500/info-100     3.24:1
        ['is_readmission', 'bg-tint-warning', 'text-on-warning'],   // was warning-500/warning-100 2.15:1
        ['is_tb', 'bg-tint-danger', 'text-on-danger'],              // was danger-600/danger-100  4.39:1
        ['medically_discharged', 'bg-tint-warning', 'text-on-warning'],
    ])('badge %s uses the %s + %s AA-verified pair', (flag, fill, text) => {
        expect(only(flag).get('span').classes()).toEqual(expect.arrayContaining([fill, text]));
    });

    // Already AA (ink-500 on ink-100 = 4.62:1 light / 6.63:1 dark) and already theme-aware, because
    // the ink scale inverts under .dark. Locked so it is not "helpfully" swept into a tint.
    it('the Discharged chip keeps its theme-aware neutral ink pair', () => {
        const c = only('discharged', { discharge_date: '2026-06-01' }).get('span').classes();
        expect(c).toEqual(expect.arrayContaining(['bg-ink-100', 'text-ink-500']));
    });

    // KNOWN, DELIBERATE EXCEPTION. `accent` has no on-*/tint-* pair, and inventing one is a design
    // decision, not a mechanical migration. text-accent-600 on bg-accent-300/40 composites to
    // 2.90:1 (light, over the white card) and 1.67:1 (dark, over #13201f) — it FAILS both. Left as
    // found and reported upward. This test documents the exception so it cannot rot into an
    // accident; delete it when an `on-accent` token lands.
    it('Long-term keeps accent (no on-*/tint-* pair exists) — a reported, deliberate exception', () => {
        const c = only('is_longterm').get('span').classes();
        expect(c).toEqual(expect.arrayContaining(['bg-accent-300/40', 'text-accent-600']));
    });

    it.each(['badge', 'plain'])('%s variant: plain-text flags use on-* tokens', (variant) => {
        expect(only('is_new', {}, { variant }).get('span').classes()).toContain('text-on-info');
        expect(only('is_readmission', {}, { variant }).get('span').classes()).toContain('text-on-warning');
        expect(only('is_tb', {}, { variant }).get('span').classes()).toContain('text-on-danger');
    });

    // The regression guard. Mirrors FlowAlert.spec.js's `not.toContain('text-warning-500')`.
    it.each(['badge', 'plain'])('%s variant: no raw status colour survives as text', (variant) => {
        const html = mountFlags(
            { is_new: true, is_readmission: true, is_longterm: true, is_tb: true, medically_discharged: true },
            { variant },
        ).html();
        for (const cls of RAW_STATUS_TEXT) expect(html).not.toContain(cls);
    });

    // ...including on the Discharged-date branch, which hides `Disch. still in` (v-else-if).
    it('badge variant: no raw status colour on the Discharged-date branch either', () => {
        const html = mountFlags({ is_new: true, is_tb: true, discharged: true, discharge_date: '2026-06-01' }).html();
        for (const cls of RAW_STATUS_TEXT) expect(html).not.toContain(cls);
    });

    // The theme-invariant `*-100` fills are what put a light peach pill on a dark card. Guard them.
    it('badge variant: pill fills are theme-aware tints, not the invariant *-100 literals', () => {
        const html = mountFlags({ is_new: true, is_readmission: true, is_tb: true, medically_discharged: true }).html();
        for (const cls of RAW_STATUS_FILL) expect(html).not.toContain(cls);
    });
});

// A colour-token migration must not disturb WHICH badges render or in WHAT order.
describe('PatientFlags — badge order + conditions are unchanged by the token migration', () => {
    const labels = (w) => w.findAll('span').map((s) => s.text());

    it('badge variant renders New, Readmit, Long-term, TB, Disch-still-in in that order', () => {
        expect(labels(mountFlags({ is_new: true, is_readmission: true, is_longterm: true, is_tb: true, medically_discharged: true })))
            .toEqual(['New', 'Readmit ≤3d', 'Long-term', 'TB', 'Disch. still in']);
    });
    it('badge variant swaps Disch-still-in for the Discharged chip, keeping position', () => {
        expect(labels(mountFlags({ is_new: true, is_readmission: true, is_longterm: true, is_tb: true, discharged: true, discharge_date: '2026-06-01', medically_discharged: true })))
            .toEqual(['New', 'Readmit ≤3d', 'Long-term', 'TB', 'Discharged 2026-06-01']);
    });
    it('plain variant keeps the same order and still omits the Discharged chip', () => {
        expect(labels(mountFlags({ is_new: true, is_readmission: true, is_longterm: true, is_tb: true, discharged: true, discharge_date: '2026-06-01', medically_discharged: true }, { variant: 'plain' })))
            .toEqual(['New', 'Readmit ≤3d', 'Long-term', 'TB', 'Disch. still in']);
    });
});
