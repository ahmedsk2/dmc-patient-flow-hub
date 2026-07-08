import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// AppLayout drags in the whole nav shell: usePage(), Link, router, the tour composable, matchMedia,
// localStorage. None of that is under test here, and StyleGuide passes it nothing but `title` +
// `breadcrumbs`. Stub it down to its default slot so this spec exercises the PAGE, not the chrome.
// (vi.mock is hoisted above the imports below — that is why the stub can be declared after them.)
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));

import StyleGuide from '@/Pages/StyleGuide.vue';
import EhcLogo from '@/Components/EhcLogo.vue';

/**
 * StyleGuide.vue is documentation that cannot go stale: it IMPORTS the real primitives rather than
 * re-implementing them, so a regression here is a regression everywhere. This spec therefore locks
 * the PROPERTIES the page exists to demonstrate, not its prose or its layout:
 *
 *   · the five rail tones — and that the two names that have never existed (`rail-ok`,
 *     `rail-critical`) stay non-existent, because a style guide is exactly where a plausible-
 *     sounding-but-fake token would get copy-pasted into real code from;
 *   · the AA-verified `on-*` text tokens, never the raw `*-500` status colours;
 *   · that NO `opacity-` utility appears — `opacity` composites the whole element over its backdrop,
 *     which drops light on-info to 4.38:1 and on-warning to 4.19:1 at 90%. Hierarchy is font-weight.
 *   · FlowAlert's live-region contract: `critical` is a POLITE role=status; calmer tones are inert
 *     role=note. (Assertive would interrupt the page-title announcement on every Inertia navigation.)
 */
const RAIL_TONES = ['rail-neutral', 'rail-info', 'rail-success', 'rail-warning', 'rail-danger'];

const mountPage = () => mount(StyleGuide);

describe('StyleGuide', () => {
    it('renders all three FlowAlert tones with the right live-region roles', () => {
        const w = mountPage();
        // critical => role=status (polite live region); info + warning => inert role=note.
        expect(w.findAll('[role="note"]')).toHaveLength(2);
        expect(w.findAll('[role="status"]')).toHaveLength(1);
    });

    // Row-scoped, so this cannot be satisfied by the rails FlowAlert happens to render above.
    it('demonstrates every one of the five rail tones, exactly once each', () => {
        const rows = mountPage().findAll('[data-testid="rail-row"]');
        expect(rows).toHaveLength(RAIL_TONES.length);

        const tones = rows.map((r) => r.classes().find((c) => c.startsWith('rail-')));
        expect(tones.sort()).toEqual([...RAIL_TONES].sort());
    });

    it('gives every rail row the shared signature classes', () => {
        for (const row of mountPage().findAll('[data-testid="rail-row"]')) {
            const c = row.classes();
            expect(c).toEqual(expect.arrayContaining(['status-rail', 'transition-row', 'row-pad', 'rounded-e-xl']));
        }
    });

    // Status is never colour alone: each rail row carries a text label beside its tone.
    it('labels every rail row in text, and gives each a tabular-numerals MRN', () => {
        const rows = mountPage().findAll('[data-testid="rail-row"]');
        for (const row of rows) {
            expect(row.text().trim()).not.toBe('');
            expect(row.get('[data-testid="rail-mrn"]').classes()).toContain('nums');
        }
    });

    // There is no `rail-ok` and no `rail-critical`. Both are the names a reader would GUESS, which is
    // precisely why the reference page must never mint them.
    it('never names a rail tone that does not exist', () => {
        const html = mountPage().html();
        expect(html).not.toContain('rail-ok');
        expect(html).not.toContain('rail-critical');
    });

    it('uses the AA-verified on-* text tokens, never the raw status colours', () => {
        const html = mountPage().html();
        for (const t of ['text-on-info', 'text-on-success', 'text-on-warning', 'text-on-danger', 'text-on-accent']) {
            expect(html).toContain(t);
        }
        for (const t of ['bg-tint-info', 'bg-tint-success', 'bg-tint-warning', 'bg-tint-danger', 'bg-tint-accent']) {
            expect(html).toContain(t);
        }
        expect(html).not.toContain('text-warning-500');
        expect(html).not.toContain('text-accent-600'); // 3.28:1 on the light card
        expect(html).not.toContain('bg-accent-300'); // the warm-gold fill, not the olive text pair
    });

    it('pairs each tint fill with its matching on-* ink on the same element', () => {
        for (const sw of mountPage().findAll('[data-testid="token-swatch"]')) {
            const c = sw.classes();
            const tint = c.find((x) => x.startsWith('bg-tint-'));
            const on = c.find((x) => x.startsWith('text-on-'));
            expect(tint).toBeTruthy();
            expect(on).toBe(`text-on-${tint.slice('bg-tint-'.length)}`);
        }
    });

    it('renders the KPI numeral in the display face with tabular numerals', () => {
        const kpi = mountPage().get('[data-testid="kpi-numeral"]');
        expect(kpi.classes()).toContain('font-display');
        expect(kpi.classes()).toContain('nums');
    });

    // Guard for the rule the whole token set rests on: `opacity` composites the ELEMENT (text and
    // all) over its backdrop. Fading a label on a tinted fill is what pushed on-info/on-warning
    // below 4.5:1. The reference page must never model it.
    it('never fades anything with an opacity utility', () => {
        expect(mountPage().html()).not.toContain('opacity-');
    });

    it('shows both logo variants: colour, and mono with a caller-supplied ink', () => {
        const logos = mountPage().findAllComponents(EhcLogo);
        expect(logos).toHaveLength(2);
        expect(logos.map((l) => l.props('mono'))).toEqual([false, true]);

        // `mono` paints in currentColor, so the CALLER owns the contrast — navy-900 light /
        // navy-100 dark on the card surface (13.70:1 / 13.13:1).
        const monoClasses = logos[1].classes();
        expect(monoClasses).toContain('text-navy-900');
        expect(monoClasses).toContain('dark:text-navy-100');
        expect(logos[0].classes()).not.toContain('text-navy-900');
    });

    it('offers a numeric-keypad form field built from the canonical .field utility', () => {
        const input = mountPage().get('input.field');
        expect(input.attributes('inputmode')).toBe('numeric');
    });
});
