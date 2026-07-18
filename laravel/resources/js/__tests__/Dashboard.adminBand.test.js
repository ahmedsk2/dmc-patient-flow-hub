import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Ground truth for "is this colour step real" pulled from the actual stylesheet source rather
// than a hand-maintained list here — a hardcoded family list goes stale the moment a step is
// added or removed and nothing red-flags it (see the W0-T3m postmortem below). `--color-X-N` is
// DECLARED (Tailwind will emit `bg-X-N`) whenever `@theme` defines it, whatever the right-hand
// side. It is THEME-AWARE only when that right-hand side is itself a var() indirection that
// `.dark` can override (`--color-brand-50: var(--brand-50)`), not a bare literal
// (`--color-brand-50: #f0fafa`) that renders identically — bug-identically — in both themes.
const appCss = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../css/app.css'),
    'utf8',
);
const declaredColorSteps = new Set([...appCss.matchAll(/--color-([a-z]+-\d+):/g)].map((m) => m[1]));
const isThemeAwareStep = (step) => new RegExp(`--color-${step}:\\s*var\\(`).test(appCss);

let authUser;
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn(), on: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser } } }),
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
// the chart-theme composable touches CSS vars / matchMedia — stub to inert refs.
vi.mock('@/composables/useChartTheme', () => ({
    useChartTheme: () => ({
        gridColor: { value: '#000' }, axisColor: { value: '#000' }, strokeColor: { value: '#000' }, inkColor: { value: '#000' },
        // W4: chart-option computeds read series.value.<name>; the mock must expose it or they throw.
        series: { value: { primary: '#009ca6', accent: '#d9a23c', deep: '#00565e', info: '#2f7fe0', muted: '#5b6a6e', primarySoft: '#38b4ba' } },
    }),
}));
// AdminBandCard is the unit under test downstream — count its instances via the stub.
vi.mock('@/Components/AdminBandCard.vue', () => ({ default: { name: 'AdminBandCard', props: ['label', 'count', 'href', 'iconPath', 'urgent'], template: '<a class="band-card">{{ count }} {{ label }}</a>' } }));

import Dashboard from '@/Pages/Dashboard.vue';
import AdminBandCard from '@/Components/AdminBandCard.vue';
import Sparkline from '@/Components/Sparkline.vue';
import { deltaChipClass } from '@/lib/ui.js';
import { router } from '@inertiajs/vue3';

const emptyKpis = { census: 0, ward: 0, icu: 0, admissionsToday: 0, dischargesToday: 0, activeConsults: 0, deathsMonth: 0, avgLosMonth: 0, occupancy: 0, occupancyGauge: 0, wardBeds: 50, icuBeds: 8 };
const baseProps = (over = {}) => ({
    adminBand: null,
    kpis: emptyKpis, boardingCount: 0, boardingWorklist: [], deltas: {}, alerts: [], myUnit: null,
    loadBands: { minHosp: 0, maxHosp: 0, minSubs: 0, maxSubs: 0 },
    trend: { labels: [], admissions: [], discharges: [] }, consults: { labels: [], new: [], signed: [] },
    consultDonut: { signed24h: 0, active: 0 }, los: { labels: [], data: [] },
    mix: { hospitalist: 0, subspecialty: 0, longterm: 0 }, donutTotal: 0, donutTb: 0,
    perConsultant: [], consultantBoard: [], activity24h: [], ytd: { admissions: 0, discharges: 0, consultations: 0, signoffs: 0 },
    topDxWeek: [], topDxWeekNum: 0, recent: [], generatedAt: 'now',
    ...over,
});

const mountAs = (user, over = {}) => {
    authUser = user;
    // renderStubDefaultSlot so the AppLayout stub renders the page body (the band lives there).
    return shallowMount(Dashboard, { props: baseProps(over), global: { renderStubDefaultSlot: true, stubs: { apexchart: true, teleport: true } } });
};

describe('Dashboard — admin landing band (Wave 1, Item 7)', () => {
    // HC-T9: a 5th card, "Handover Due (unit)", joins the band (urgent, deep-links into the
    // needs_handover board filter).
    const adminBand = { dqIssues: 2, securityAnomalies: 5, recentlyDeleted: 1, pendingHandovers: 3, handoverDueUnit: 4 };

    it('renders the Administrative section with five cards for an admin', () => {
        const w = mountAs({ role: 0, is_admin: true }, { adminBand });
        expect(w.find('section[aria-label="Administrative overview"]').exists()).toBe(true);
        expect(w.findAllComponents(AdminBandCard)).toHaveLength(5);
    });

    it('passes the urgent flag to Data Quality + Security + Handover Due (unit)', () => {
        const w = mountAs({ role: 0, is_admin: true }, { adminBand });
        const cards = w.findAllComponents(AdminBandCard);
        const byLabel = Object.fromEntries(cards.map((c) => [c.props('label'), c.props('urgent')]));
        expect(byLabel['Data Quality Issues']).toBe(true);
        expect(byLabel['Security Anomalies']).toBe(true);
        expect(byLabel['Recently Deleted']).toBe(false);
        expect(byLabel['Pending Handovers']).toBe(false);
        expect(byLabel['Handover Due (unit)']).toBe(true);
    });

    it('does NOT render the band for a non-admin', () => {
        const w = mountAs({ role: 3, is_admin: false }, { adminBand });
        expect(w.find('section[aria-label="Administrative overview"]').exists()).toBe(false);
        expect(w.findAllComponents(AdminBandCard)).toHaveLength(0);
    });

    it('does NOT render the band when adminBand is null even for an admin', () => {
        const w = mountAs({ role: 0, is_admin: true }, { adminBand: null });
        expect(w.find('section[aria-label="Administrative overview"]').exists()).toBe(false);
    });
});

// W0-T3i — the "My unit today" lens. Colocated here because this file already owns the only
// Dashboard mount harness; the tiles are a sibling of the admin band in the same template.
//
// Three of the six tiles asked for `bg-danger-50` / `bg-warning-50` / `bg-info-50`. Those steps are
// not declared in @theme, so Tailwind emitted NO rule for them and the tiles rendered with no fill
// at all: half the row's colour coding was silently dead. They now use the theme-aware `bg-tint-*`
// tokens at 30% — the alpha whose CIE dE76 from `bg-card` (4.2 / 4.6 / 4.4) matches the two fills
// that always EMITTED (bg-brand-50 = 4.25, bg-ink-50 = 3.90), so the row keeps one visual weight.
// The `nums` value keeps `text-ink-900`: 13.7-14.2:1 light, 15.0-15.6:1 dark on every new fill.
//
// W0-T3m (later fix, do not re-break): "always emitted" is not "always legible". bg-brand-50 was,
// until then, a theme-INVARIANT literal — so on dark it rendered the SAME near-white fill as
// light, and `text-ink-900` (near-white in dark) sat on it at 1.01:1: the Active tile's number was
// there in the DOM and invisible on screen. `--color-brand-50`/`-100` are now var()-indirected
// (same pattern as `--color-ink-50`) with real dark values; see app.css for the derivation.
// text-ink-900 on bg-brand-50: light 13.86:1 (unchanged), dark 14.41:1 (up from 1.01:1).
describe('Dashboard — "My unit today" tiles (W0-T3i)', () => {
    // HC-T9: a 7th tile, "Handover due", joins the row right after Boarding (personal count,
    // deep-links into the needs_handover board filter — same warning tint as Boarding).
    const myUnit = { total: 12, ward: 9, icu: 3, boarding: 2, handoverDue: 6, new: 4, myConsults: 5, signPending: 0 };
    // label -> class list. The label is the second <p> in each tile button.
    const tiles = (w) => Object.fromEntries(
        w.findAll('.grid-cols-3 button').map((b) => [b.findAll('p')[1].text(), b.classes()]),
    );
    const mountConsultant = () => mountAs({ role: 3, is_admin: false }, { myUnit });

    it('renders all seven tiles for a consultant', () => {
        expect(Object.keys(tiles(mountConsultant()))).toEqual(
            ['Active', 'Ward', 'ICU', 'Boarding', 'Handover due', 'New (24h)', 'Consults'],
        );
    });

    it('the three formerly fill-less tiles now carry theme-aware tint fills', () => {
        const t = tiles(mountConsultant());
        expect(t['ICU']).toContain('bg-tint-danger/30');
        expect(t['Boarding']).toContain('bg-tint-warning/30');
        expect(t['New (24h)']).toContain('bg-tint-info/30');
    });

    it('no tile references an undeclared *-50/-100 colour step (the defect: zero emitted CSS)', () => {
        const t = tiles(mountConsultant());
        // Mutation-proven guard: if `.grid-cols-3` in Dashboard.vue ever stopped matching (e.g. a
        // `grid-cols-2` typo), `tiles()` would return `{}` and the loop below would iterate zero
        // times and pass vacuously, even though three OTHER tests in this block would rightly
        // redden. Pin the tile count so an empty selector match fails HERE too.
        expect(Object.keys(t)).toHaveLength(7);
        for (const [label, classes] of Object.entries(t)) {
            // Broadened from a hardcoded (danger|warning|info|success) family list: that list only
            // ever protected against families that were undeclared on the day it was written, and
            // does nothing for a step in ANY OTHER family (accent-50 today; brand-50 was ALWAYS
            // declared but, until W0-T3m below, theme-broken — a distinct defect this particular
            // assertion cannot see, which is why the theme-awareness check below exists). Checked
            // against the real @theme declarations instead of a maintained list, so this guard
            // cannot go stale the way the old one did.
            const dead = classes.filter((c) => {
                const m = /^bg-([a-z]+-(?:50|100))(?:\/\d+)?$/.exec(c);
                return m && !declaredColorSteps.has(m[1]);
            });
            expect(dead, `${label} tile still asks for an undeclared step`).toEqual([]);
        }
    });

    it('leaves the two fills that always emitted alone', () => {
        const t = tiles(mountConsultant());
        expect(t['Active']).toContain('bg-brand-50');
        expect(t['Ward']).toContain('bg-ink-50');
        expect(t['Consults']).toContain('bg-accent-300/20');
    });

    // W0-T3m. The assertion above only proves the Active tile still SPELLS `bg-brand-50` — it
    // cannot see that the step used to render identically (and invisibly, 1.01:1) in both themes.
    // This is the reachable check: app.css declares `--color-brand-50` through a var() indirection
    // that `.dark` overrides, the same shape as `--color-ink-50` — so it is provably theme-aware
    // at the source level, not just "still the right class name".
    it('bg-brand-50 (the Active tile fill) is theme-aware, not a fixed literal', () => {
        expect(isThemeAwareStep('brand-50')).toBe(true);
        expect(isThemeAwareStep('brand-100')).toBe(true);
    });
});

// W4 — KPI triad: polarity-driven delta chips + inline sparklines. The chip's colour comes from the
// KPI's own polarity (a rise in a 'bad' KPI is red; a rise in a 'good' KPI is green; a 'neutral' KPI
// is always ink), NOT the raw number — proven both on the pure helper and on the rendered cards.
describe('Dashboard — KPI triad, polarity & sparklines (W4)', () => {
    // The pure token map (lib/ui.js) — the single source of truth the cards consume.
    it('deltaChipClass: bad+up → danger, good+up → success, neutral/flat → ink', () => {
        expect(deltaChipClass('bad', 'up')).toBe('bg-tint-danger text-on-danger');
        expect(deltaChipClass('good', 'up')).toBe('bg-tint-success text-on-success');
        expect(deltaChipClass('bad', 'down')).toBe('bg-tint-success text-on-success');
        expect(deltaChipClass('good', 'down')).toBe('bg-tint-danger text-on-danger');
        expect(deltaChipClass('neutral', 'up')).toBe('bg-ink-100 text-ink-500');
        expect(deltaChipClass('bad', 'flat')).toBe('bg-ink-100 text-ink-500');
    });

    const chipOf = (w, label) => w.find(`[data-kpi="${label}"] span.rounded-full`);
    const withDeltas = (over = {}) => mountAs({ role: 0, is_admin: true }, {
        deltas: {
            deathsMonth: { delta: 2, direction: 'up' },   // Mortality is a 'bad' KPI
            admissions: { delta: 1, direction: 'up' },     // Admissions is 'neutral'
            occupancy: { delta: 3, direction: 'up' },      // Occupancy is 'bad' (good_up was null in the payload)
            discharges: { delta: 4, direction: 'up' },     // Discharges is the rendered 'good' KPI
        },
        ...over,
    });

    it('renders the success token chip for the good-polarity Discharges card on an up delta', () => {
        const chip = chipOf(withDeltas(), 'Discharges Today');
        expect(chip.exists()).toBe(true);
        expect(chip.classes()).toContain('bg-tint-success');
        expect(chip.classes()).toContain('text-on-success');
        expect(chip.text()).toContain('▲');
    });

    it('renders the danger token chip for a bad-polarity KPI with a positive (up) delta', () => {
        const chip = chipOf(withDeltas(), 'Mortality (Month)');
        expect(chip.exists()).toBe(true);
        expect(chip.classes()).toContain('bg-tint-danger');
        expect(chip.classes()).toContain('text-on-danger');
        expect(chip.text()).toContain('▲');   // meaning carried by the arrow too, not colour alone
    });

    it('a rise in occupancy (bad polarity) is danger, overriding the payload good_up=null', () => {
        expect(chipOf(withDeltas(), 'Bed Occupancy').classes()).toContain('bg-tint-danger');
    });

    it('keeps a neutral KPI (Admissions) ink on an up delta — never green', () => {
        const chip = chipOf(withDeltas(), 'Admissions Today');
        expect(chip.classes()).toContain('bg-ink-100');
        expect(chip.classes()).not.toContain('bg-tint-success');
    });

    it('renders an inline Sparkline only for a KPI that has a real series payload', () => {
        const w = withDeltas({ trend: { labels: [], admissions: [3, 5, 2, 8, 4], discharges: [] } });
        // Admissions Today carries spark=trend.admissions → a Sparkline; Mortality has none.
        expect(w.find('[data-kpi="Admissions Today"]').findComponent(Sparkline).exists()).toBe(true);
        expect(w.find('[data-kpi="Mortality (Month)"]').findComponent(Sparkline).exists()).toBe(false);
    });

    it('renders no Sparkline when the KPI series is empty', () => {
        const w = withDeltas({ trend: { labels: [], admissions: [], discharges: [] } });
        expect(w.find('[data-kpi="Admissions Today"]').findComponent(Sparkline).exists()).toBe(false);
    });
});

// W0-T3j — the alert dismiss ✕. `hover:opacity-*` composites the whole glyph toward the tinted
// backdrop on hover (WCAG 1.4.3 has no hover exemption). A DARKENING background wash is no better —
// it darkens the glyph's own local background (light on-warning 5.20 -> 4.16, sub-AA). The affordance
// must be a ring (a border-edge mark that never touches the glyph's background).
describe('Dashboard — alert dismiss ✕ (W0-T3j)', () => {
    const withAlert = () => mountAs({ role: 0, is_admin: true }, {
        alerts: [{ key: 'k1', severity: 'warning', message: 'Two discharges overdue' }],
    });
    it('the dismiss button carries no hover:opacity fade', () => {
        const btn = withAlert().find('button[aria-label="Dismiss alert"]');
        expect(btn.exists()).toBe(true);
        expect(btn.classes().some((c) => c.startsWith('hover:opacity-'))).toBe(false);
    });
    it('uses a ring for its hover affordance — never a darkening bg wash (which cuts glyph contrast)', () => {
        const c = withAlert().find('button[aria-label="Dismiss alert"]').classes();
        expect(c).toContain('hover:ring-1');
        expect(c).toContain('hover:ring-current');
        // A wash behind the glyph reduces its contrast (light on-warning 5.20 -> 4.16, sub-AA). Forbid it.
        expect(c).not.toContain('hover:bg-black/10');
        expect(c).not.toContain('dark:hover:bg-white/10');
    });
});

// Wave 5, Item 1 (a11y): the boarding-worklist row and the per-consultant breakdown row were
// click-only <tr>s (no keyboard path). Now real role="button" + tabindex="0" rows with Enter/Space
// handlers wired to the SAME navigation the click already used (goHref / drillTo -> router.visit).
describe('Wave 5 — dashboard rows are keyboard-operable', () => {
    it('the boarding-worklist row is a role="button" tabindex="0" row, and Enter fires the same navigation as a click', async () => {
        router.visit.mockClear();
        const w = mountAs({ role: 0, is_admin: true }, {
            boardingCount: 1,
            boardingWorklist: [{ id: 1, name: 'Jane Doe', mrn: '12345', consultant: 'Dr Smith', delay_days: 9 }],
        });
        const row = w.find('tr[role="button"]');
        expect(row.exists()).toBe(true);
        expect(row.attributes('tabindex')).toBe('0');
        expect(row.attributes('aria-label')).toContain('Jane Doe');
        expect(row.attributes('aria-label')).toContain('12345');

        await row.trigger('keydown.enter');
        expect(router.visit).toHaveBeenCalledWith('/patients', { data: { view: 'boarding' } });
    });

    it('the per-consultant breakdown row is keyboard-operable and Space drills into that consultant', async () => {
        router.visit.mockClear();
        const w = mountAs({ role: 0, is_admin: true }, {
            consultantBoard: [{ name: 'Ahmed', id: 9, old: 1, new: 0, active: 2, ward: 2, icu: 0, tb: 0 }],
        });
        const rows = w.findAll('tr[role="button"]');
        const row = rows.find((r) => r.text().includes('Ahmed'));
        expect(row).toBeTruthy();
        expect(row.attributes('tabindex')).toBe('0');

        await row.trigger('keydown.space');
        expect(router.visit).toHaveBeenCalledWith('/patients', { data: { consultant_id: 9 } });
    });
});
