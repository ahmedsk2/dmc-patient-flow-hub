<script setup>
/**
 * StyleGuide.vue — Wave 0's living reference for the "Census Board" design language.
 *
 * THE POINT: this page IMPORTS the real primitives (EhcLogo, FlowAlert) and spells the real utility
 * classes rather than re-implementing either. It is documentation that cannot go stale — a
 * regression on this page is a regression everywhere. Admin-gated (StyleGuideController): internal
 * tooling, not sensitive.
 *
 * ── HOUSE RULES OBSERVED HERE, each of which cost this wave a real defect ────────────────────────
 *
 * 1. NEVER spell a resolvable utility class in a comment or a string in this file. Tailwind v4's
 *    extractor reads raw bytes — prose included — and mints whatever parses as a class. A top-offset
 *    rule once shipped into the bundle out of an ordinary English phrase in a PHP docblock. So below,
 *    tokens are named by their bare step (`brand-200`, `tint-accent`, `on-accent`) and never with a
 *    utility prefix. scripts/check-source-allowlist.mjs is the guard: any selector present in the
 *    built CSS but absent from its committed snapshot fails CI, in either direction.
 *
 * 2. NO dynamic class assembly for the swatches. A template-literal class is opaque to the extractor,
 *    and the usual remedy — a safelist comment — is rule 1 all over again. Every swatch below is
 *    written out longhand. That verbosity IS the mechanism.
 *
 * 3. NO alpha fading anywhere on this page. Reducing an element's opacity composites the whole
 *    element, its text included, over the backdrop in sRGB. Measured at 90%: light `on-info` falls to
 *    4.38:1 and `on-warning` to 4.19:1, both under the 4.5:1 that normal-weight body text needs
 *    (WCAG 1.4.3). `on-danger` (4.68) and `on-success` (4.87) happen to survive, which is the trap:
 *    the PAIRING decides, not the token, so no blanket alpha is safe. Hierarchy is font-weight.
 *
 * 4. Every root-level comment stays OUT of <template>. A comment beside the root element makes the
 *    SFC a multi-root Fragment, after which wrapper.classes() and wrapper.attributes() silently
 *    return [] and undefined in @vue/test-utils.
 */
import AppLayout from '@/Layouts/AppLayout.vue';
import EhcLogo from '@/Components/EhcLogo.vue';
import FlowAlert from '@/Components/FlowAlert.vue';
</script>

<template>
    <AppLayout title="Style Guide" :breadcrumbs="[{ label: 'Administration' }, { label: 'Style Guide' }]">
        <p class="mb-8 max-w-3xl text-sm text-ink-500">
            The Census Board design language, rendered from the same components and tokens the app
            ships. Flat surfaces separated by teal-tinted hairlines; depth is reserved for true
            overlays. Every status carries a shape or a word as well as a colour.
        </p>

        <!-- ─── 1 · Brand ────────────────────────────────────────────────────────────────────────
             Two logo variants. The colour mark carries its own gradient and is safe on any surface.
             The `mono` mark paints in currentColor, so THE CALLER OWNS THE CONTRAST: here the caller
             supplies navy-900 ink in light and navy-100 in dark, which measure 13.70:1 and 13.13:1 on
             the card surface (--color-card = #ffffff light, #13201f dark).

             That is exactly why AppLayout's sidebar chip does NOT pass `mono`. The chip sits on the
             card surface, not on the navy gradient behind the rest of the aside, so an inherited
             currentColor there would be the aside's navy-100 ink — 1.28:1 on white, no glyph at all.
             The chip correctly uses the colour variant. `mono` is for callers that set the ink. -->
        <section class="mb-8 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
            <h2 class="font-display text-lg font-bold text-ink-900">Brand</h2>
            <p class="mt-1 text-sm text-ink-500">
                Eastern Health Cluster. Two variants — colour, and mono in <code>currentColor</code>.
            </p>

            <div class="mt-5 flex flex-wrap items-center gap-6">
                <div class="flex flex-col items-center gap-2">
                    <EhcLogo class="h-12 w-12" />
                    <span class="text-xs text-ink-400">colour</span>
                </div>
                <div class="flex flex-col items-center gap-2">
                    <EhcLogo class="h-12 w-12 text-navy-900 dark:text-navy-100" mono />
                    <span class="text-xs text-ink-400">mono · caller supplies the ink</span>
                </div>
            </div>

            <h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-ink-400">Brand scale</h3>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div>
                    <div class="h-12 rounded-lg bg-brand-50 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-50 †</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-100 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-100 †</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-200 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-200</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-300 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-300</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-400 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-400</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-500 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-500 · primary</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-600 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-600 †</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-700 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-700 †</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-800 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-800 † · deep</p>
                </div>
                <div>
                    <div class="h-12 rounded-lg bg-brand-900 ring-1 ring-line"></div>
                    <p class="mt-1.5 text-[11px] text-ink-500">brand-900</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-ink-400">
                † Theme-aware: these steps resolve to different values under dark, so read the swatch,
                not a hex you remember. Each swatch above renders the live token.
            </p>
        </section>

        <!-- ─── 2 · Status rail — the fingerprint ────────────────────────────────────────────────
             Every patient row carries a 3px inset rail; the board reads as a stack of tickets. The
             rail edge stays square while the far corners take the radius — that asymmetry is the
             silhouette. Five tones, no more: neutral, info, success, warning, danger. Their names
             track the fill/ink vocabulary (danger, never "critical"; neutral, never "ok") so the two
             sets can never read as different languages.

             Status is never carried by colour alone: every row also states its tone in words. -->
        <section class="mb-8 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
            <h2 class="font-display text-lg font-bold text-ink-900">Status rail</h2>
            <p class="mt-1 text-sm text-ink-500">
                Five tones. Hover a row for the one meaningful motion in the system — 120ms, and
                automatically neutralized under <code>prefers-reduced-motion</code>.
            </p>

            <div class="mt-5 grid gap-2">
                <div
                    data-testid="rail-row"
                    class="status-rail rail-neutral transition-row row-pad flex items-center justify-between gap-4 rounded-e-xl bg-app px-4 hover:bg-ink-100"
                >
                    <span class="text-sm font-medium text-ink-700">Neutral · settled, no action</span>
                    <span data-testid="rail-mrn" class="nums text-xs text-ink-500">MRN 40118293</span>
                </div>
                <div
                    data-testid="rail-row"
                    class="status-rail rail-info transition-row row-pad flex items-center justify-between gap-4 rounded-e-xl bg-app px-4 hover:bg-ink-100"
                >
                    <span class="text-sm font-medium text-ink-700">Info · consultation pending</span>
                    <span data-testid="rail-mrn" class="nums text-xs text-ink-500">MRN 40227416</span>
                </div>
                <div
                    data-testid="rail-row"
                    class="status-rail rail-success transition-row row-pad flex items-center justify-between gap-4 rounded-e-xl bg-app px-4 hover:bg-ink-100"
                >
                    <span class="text-sm font-medium text-ink-700">Success · discharge complete</span>
                    <span data-testid="rail-mrn" class="nums text-xs text-ink-500">MRN 40190875</span>
                </div>
                <div
                    data-testid="rail-row"
                    class="status-rail rail-warning transition-row row-pad flex items-center justify-between gap-4 rounded-e-xl bg-app px-4 hover:bg-ink-100"
                >
                    <span class="text-sm font-medium text-ink-700">Warning · discharge overdue</span>
                    <span data-testid="rail-mrn" class="nums text-xs text-ink-500">MRN 40206631</span>
                </div>
                <div
                    data-testid="rail-row"
                    class="status-rail rail-danger transition-row row-pad flex items-center justify-between gap-4 rounded-e-xl bg-app px-4 hover:bg-ink-100"
                >
                    <span class="text-sm font-medium text-ink-700">Danger · escalated to ICU</span>
                    <span data-testid="rail-mrn" class="nums text-xs text-ink-500">MRN 40233907</span>
                </div>
            </div>
        </section>

        <!-- ─── 3 · Flow alerts ──────────────────────────────────────────────────────────────────
             Three tiers, three redundant signals each: a screen-reader-only prefix, an icon
             distinguished by SILHOUETTE (circle · triangle · octagon, never by fill), and a text
             title. The `critical` tier is a role=status live region — POLITE, so it queues behind the
             page-title announcement on an Inertia navigation rather than interrupting it. The calmer
             tiers are the inert role=note. Cap at two callouts per view. -->
        <section class="mb-8 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
            <h2 class="font-display text-lg font-bold text-ink-900">Flow alerts</h2>
            <p class="mt-1 text-sm text-ink-500">
                Urgency is never carried by colour alone. Only <code>critical</code> is a live region,
                and it announces politely.
            </p>

            <div class="mt-5 grid gap-3">
                <FlowAlert tone="info" title="Four beds free on the ward">
                    Capacity is comfortable for the evening intake.
                </FlowAlert>
                <FlowAlert tone="warning" title="Two discharges are overdue">
                    Both were medically cleared yesterday. Review the boarding worklist.
                </FlowAlert>
                <FlowAlert tone="critical" title="A patient has been unassigned for six hours">
                    No consultant is named on MRN 40233907. Assign before the shift change.
                </FlowAlert>
            </div>
        </section>

        <!-- ─── 4 · Status tokens ────────────────────────────────────────────────────────────────
             The five AA-verified fill/ink pairs. Each fill has exactly one ink, and every pair clears
             4.5:1 in BOTH themes (proven by scripts/contrast.mjs). Never reach for a raw status
             colour at step 500 as text: the amber is 2.92:1 on the light card, and accent-600 is
             3.28:1.

             `accent` is the odd one out — the only non-status hue in the set, and deliberately OLIVE
             gold rather than the warm gold of the accent-300..600 fills. Sharing the warm hue put its
             ink at CIEDE2000 8.18 from the amber ink beside it: indistinguishable on a 10px badge,
             which is the one thing the badge exists to signal. -->
        <section class="mb-8 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
            <h2 class="font-display text-lg font-bold text-ink-900">Status tokens</h2>
            <p class="mt-1 text-sm text-ink-500">
                Five fill/ink pairs, AA in light and in dark. Use the pair; never the raw status colour.
            </p>

            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-5">
                <div data-testid="token-swatch" class="rounded-xl bg-tint-info px-4 py-3 text-on-info">
                    <p class="text-sm font-semibold">Info</p>
                    <p class="mt-0.5 text-xs">tint-info · on-info</p>
                </div>
                <div data-testid="token-swatch" class="rounded-xl bg-tint-success px-4 py-3 text-on-success">
                    <p class="text-sm font-semibold">Success</p>
                    <p class="mt-0.5 text-xs">tint-success · on-success</p>
                </div>
                <div data-testid="token-swatch" class="rounded-xl bg-tint-warning px-4 py-3 text-on-warning">
                    <p class="text-sm font-semibold">Warning</p>
                    <p class="mt-0.5 text-xs">tint-warning · on-warning</p>
                </div>
                <div data-testid="token-swatch" class="rounded-xl bg-tint-danger px-4 py-3 text-on-danger">
                    <p class="text-sm font-semibold">Danger</p>
                    <p class="mt-0.5 text-xs">tint-danger · on-danger</p>
                </div>
                <div data-testid="token-swatch" class="rounded-xl bg-tint-accent px-4 py-3 text-on-accent">
                    <p class="text-sm font-semibold">Accent</p>
                    <p class="mt-0.5 text-xs">tint-accent · on-accent</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-ink-400">
                Accent is olive-gold: #4f5314 on #eef0d6 (7.00:1) in light, #cdd48a on #262a10 (9.42:1)
                in dark. The other four hold their hue across themes, so a status never re-reads across
                a shift change.
            </p>
        </section>

        <!-- ─── 5 · Numerals & controls ─────────────────────────────────────────────────────────
             KPI figures are set in the display face with tabular numerals, so a column of counts does
             not jitter as it refreshes. Beneath it, a hairline rule: hairlines are the depth cue
             everywhere in this system except on a true overlay.

             (This sentence used to name the CSS filter it was contrasting hairlines against. Tailwind
             minted that utility straight out of the comment and check-source-allowlist.mjs caught it
             in the very next build. Rule 1 is not theoretical.)

             Numeric inputs declare a numeric keypad. Note the absence of any faded state below —
             secondary and destructive affordances are carried by real fills and by weight, per the
             no-alpha rule in the header comment. -->
        <section class="mb-8 rounded-2xl bg-card p-6 shadow-card ring-1 ring-line">
            <h2 class="font-display text-lg font-bold text-ink-900">Numerals &amp; controls</h2>
            <p class="mt-1 text-sm text-ink-500">
                Tabular numerals, a hairline underscore, three button tiers, one canonical input class.
            </p>

            <div class="mt-5 w-fit">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-400">Active census</p>
                <p data-testid="kpi-numeral" class="nums mt-1 font-display text-4xl font-bold text-ink-900">128</p>
                <div class="mt-2 h-0.5 w-full bg-hairline"></div>
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <button type="button" class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-brand-700">
                    Admit patient
                </button>
                <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-line bg-card px-5 py-2 text-sm font-semibold text-ink-600 shadow-sm transition hover:border-brand-300 hover:text-brand-700">
                    Cancel
                </button>
                <button type="button" class="inline-flex items-center gap-2 rounded-xl bg-danger-600 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-danger-700">
                    Delete record
                </button>
            </div>

            <div class="mt-6 max-w-sm">
                <label for="sg-mrn" class="mb-1.5 block text-xs font-semibold text-ink-500">Medical record number</label>
                <input id="sg-mrn" class="field" inputmode="numeric" autocomplete="off" placeholder="40118293" />
                <p class="mt-1.5 text-xs text-ink-400">
                    <code>inputmode</code> raises the numeric keypad on a ward tablet without rejecting
                    a pasted value.
                </p>
            </div>
        </section>
    </AppLayout>
</template>
