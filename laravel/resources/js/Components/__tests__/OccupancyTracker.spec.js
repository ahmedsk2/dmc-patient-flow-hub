import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import OccupancyTracker from '@/Components/OccupancyTracker.vue';

// Two calm units by default: Ward 36/50 = 72%, ICU 6/8 = 75% — both below the 0.85 warning floor.
const UNITS = [
    { key: 'ward', label: 'Ward', occupied: 36, total: 50 },
    { key: 'icu', label: 'ICU', occupied: 6, total: 8 },
];

const mountTracker = (props = {}) => mount(OccupancyTracker, { props: { units: UNITS, ...props } });
const unitRows = (w) => w.findAll('[data-unit-row]');
const rowByKey = (w, key) => w.find(`[data-key="${key}"]`);
const fillOf = (row) => row.find('[data-fill]');

describe('OccupancyTracker', () => {
    it('renders one row per unit, each with its label and "N / M" bed text', () => {
        const w = mountTracker();
        expect(unitRows(w)).toHaveLength(2);
        expect(rowByKey(w, 'ward').text()).toContain('Ward');
        expect(rowByKey(w, 'ward').text()).toContain('36 / 50');
        expect(rowByKey(w, 'icu').text()).toContain('ICU');
        expect(rowByKey(w, 'icu').text()).toContain('6 / 8');
    });

    // Below the warning floor the bar must stay CALM — never carry a warning/critical fill — and the
    // aria-label must say so in words (colour is never the only signal; see FlowAlert/PatientFlags).
    it('keeps a unit below the warning threshold calm (no warning/critical fill; label says normal)', () => {
        const row = rowByKey(mountTracker(), 'ward');
        const cls = fillOf(row).classes();
        expect(cls).not.toContain('bg-tint-warning');
        expect(cls).not.toContain('bg-tint-danger');
        const aria = row.attributes('aria-label');
        expect(aria).toContain('36 of 50 beds occupied');
        expect(aria).toContain('72%');
        expect(aria).toContain('within normal range');
        expect(row.text()).toContain('Normal');
    });

    // At/above `warning` but below `critical`: amber tier.
    it('marks a unit at/above the warning threshold (but below critical) with the warning token', () => {
        // 44/50 = 88% — >= 0.85, < 0.95.
        const row = rowByKey(mountTracker({ units: [{ key: 'ward', label: 'Ward', occupied: 44, total: 50 }] }), 'ward');
        const cls = fillOf(row).classes();
        expect(cls).toContain('bg-tint-warning');
        expect(cls).not.toContain('bg-tint-danger');
        expect(row.attributes('aria-label')).toContain('at warning threshold');
        expect(row.text()).toContain('Warning');
    });

    // At/above `critical`: red tier, via a THEME-AWARE token (bg-tint-danger) on the bar — never a
    // raw text-danger-* colour used as text (the a11y-status-text guard forbids that family).
    it('marks a unit at/above the critical threshold with the theme-aware danger token (never text-danger-*)', () => {
        // 48/50 = 96% — >= 0.95.
        const row = rowByKey(mountTracker({ units: [{ key: 'ward', label: 'Ward', occupied: 48, total: 50 }] }), 'ward');
        const cls = fillOf(row).classes();
        expect(cls).toContain('bg-tint-danger');
        expect(cls.join(' ')).not.toMatch(/\btext-danger-\d/);
        expect(row.attributes('aria-label')).toContain('at or over critical threshold');
        expect(row.text()).toContain('Critical');
    });

    // total = 0 → empty state, no bar, and crucially NO NaN leaking into the text or the label.
    it('shows an empty state for total=0 without dividing by zero (no NaN)', () => {
        const w = mountTracker({ units: [{ key: 'ward', label: 'Ward', occupied: 0, total: 0 }] });
        const row = rowByKey(w, 'ward');
        expect(row.text()).toContain('No beds configured');
        expect(fillOf(row).exists()).toBe(false);
        expect(row.attributes('aria-label')).toContain('no beds configured');
        expect(w.text()).not.toContain('NaN');
        expect(w.html()).not.toContain('NaN');
    });

    // occupied > total (over capacity): the BAR clamps to 100%, but the printed numbers/% stay exact.
    it('clamps the bar to 100% when occupied>total while showing the real numbers', () => {
        const row = rowByKey(mountTracker({ units: [{ key: 'ward', label: 'Ward', occupied: 55, total: 50 }] }), 'ward');
        expect(row.text()).toContain('55 / 50');
        expect(row.text()).toContain('110%');
        expect(fillOf(row).attributes('data-width')).toBe('100');
        // Over capacity is unambiguously critical.
        expect(fillOf(row).classes()).toContain('bg-tint-danger');
    });

    it('adds an overall/total row only when there are >=2 units', () => {
        const w = mountTracker(); // (36+6)/(50+8) = 42/58
        const overall = w.find('[data-overall-row]');
        expect(overall.exists()).toBe(true);
        expect(overall.text()).toContain('42 / 58');
        // A single unit has nothing to total — the row is suppressed.
        const single = mountTracker({ units: [UNITS[0]] });
        expect(single.find('[data-overall-row]').exists()).toBe(false);
        expect(unitRows(single)).toHaveLength(1);
    });

    // The caller may pass thresholds derived from `settings`; they must actually drive the tiers.
    it('honours caller-supplied thresholds (settings-derived) over the defaults', () => {
        // warning 0.50 / critical 0.60 → the default-calm 72% Ward is now critical.
        const w = mountTracker({ thresholds: { warning: 0.5, critical: 0.6 } });
        expect(fillOf(rowByKey(w, 'ward')).classes()).toContain('bg-tint-danger');
    });

    it('defaults thresholds to 0.85 / 0.95 when none are supplied', () => {
        // 42/50 = 84% is still normal; 43/50 = 86% crosses into warning.
        const normal = rowByKey(mountTracker({ units: [{ key: 'w', label: 'W', occupied: 42, total: 50 }] }), 'w');
        expect(fillOf(normal).classes()).not.toContain('bg-tint-warning');
        const warn = rowByKey(mountTracker({ units: [{ key: 'w', label: 'W', occupied: 43, total: 50 }] }), 'w');
        expect(fillOf(warn).classes()).toContain('bg-tint-warning');
    });

    // Whole-component sweep: no status/brand colour is ever used AS TEXT (mirrors a11y-status-text guard).
    it('never uses a status colour as text anywhere it renders', () => {
        const w = mountTracker({ units: [{ key: 'a', label: 'A', occupied: 48, total: 50 }] });
        expect(w.html()).not.toMatch(/text-(?:warning|success|danger|info)-(?:400|500|600|700)/);
    });

    // Each row is an accessible image with a self-describing label — the same pattern the charts use.
    it('exposes each bar as role="img" with a descriptive aria-label', () => {
        const row = rowByKey(mountTracker(), 'ward');
        expect(row.attributes('role')).toBe('img');
        expect(row.attributes('aria-label')).toBe('Ward: 36 of 50 beds occupied, 72%, within normal range');
    });
});
