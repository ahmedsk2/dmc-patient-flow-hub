// laravel/scripts/contrast.mjs
//
// Dev-only WCAG 2.2 contrast calculator. Zero dependencies (Node >= 18, ESM).
// Run:  node scripts/contrast.mjs
//
// Wave 0 uses this to PROVE which design tokens are legible. Re-run it whenever a colour token
// changes, and paste the output into public/images/BRAND_README.md.

/** sRGB channel (0-255) -> linear-light value, per WCAG 2.x relative-luminance definition. */
const lin = (c) => {
    c /= 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};

/** '#rrggbb' -> relative luminance L. Throws on malformed input: a silently-wrong ratio would
 *  look just as authoritative as a correct one, and this script certifies accessibility. */
const lum = (hex) => {
    if (!/^#[0-9a-f]{6}$/i.test(hex)) throw new Error(`bad hex: ${hex}`);
    const n = parseInt(hex.slice(1), 16);
    return 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
};

/** Contrast ratio between two hexes, always >= 1. */
const ratio = (a, b) => {
    const [hi, lo] = [lum(a), lum(b)].sort((p, q) => q - p);
    return (hi + 0.05) / (lo + 0.05);
};

const verdict = (r) => (r >= 4.5 ? 'AA text' : r >= 3 ? 'UI / large text only' : 'FAIL');

const PAIRS = [
    // --- brand on the light page surfaces ---
    ['brand-500 on white', '#009ca6', '#ffffff'],
    ['brand-700 on white', '#00727b', '#ffffff'],
    ['brand-800 on white', '#00565e', '#ffffff'],
    // --- CURRENT status colours used as TEXT (this is what we are checking) ---
    ['warning-500 on white', '#e69209', '#ffffff'],
    ['warning-500 on warning-100', '#e69209', '#fdedd2'],
    ['danger-600 on danger-100', '#c1302d', '#fbdcdc'],
    ['success-600 on success-100', '#15803d', '#d8f5e3'],
    ['info-500 on info-100', '#2f7fe0', '#d7e9fb'],
    // --- PROPOSED AA-safe `on-*` text tokens, light theme ---
    ['on-info on tint-info', '#1b5cad', '#d7e9fb'],
    ['on-warning on tint-warning', '#8a5a00', '#fdedd2'],
    ['on-danger on tint-danger', '#a82824', '#fbdcdc'],
    ['on-success on tint-success', '#11672f', '#d8f5e3'],
    // --- PROPOSED AA-safe `on-*` text tokens, dark theme (opaque tints, exact maths) ---
    ['on-info on tint-info (dark)', '#9cc4f5', '#12293f'],
    ['on-warning on tint-warning (dark)', '#f0c073', '#3a2a09'],
    ['on-danger on tint-danger (dark)', '#f4a6a4', '#3a1a19'],
    ['on-success on tint-success (dark)', '#86d6a3', '#10291a'],
    // --- ACCENT (W0-T3e). The Long-term badge used accent-600 on accent-300/40, which composites to
    //     2.90:1 over the white card and 1.67:1 over the dark one (10px semibold — no large-text
    //     allowance applies). accent now has the same tint/on pair as the four status hues. The
    //     `on-accent` value must clear 4.5:1 on BOTH its tint and bg-card, in both themes, because
    //     the plain (pill-less) variant paints straight onto the card. The composited *baselines* are
    //     kept below so a regression is legible as a number, not just as a missing row. ---
    ['accent-600 on accent-300/40 over card (BASELINE, light)', '#b9842a', '#faf0d9'],
    ['accent-600 on accent-300/40 over card (BASELINE, dark)', '#b9842a', '#6d6a53'],
    ['accent-600 on white (BASELINE plain, light)', '#b9842a', '#ffffff'],
    ['on-accent on tint-accent', '#6b4a08', '#f7ecd2'],
    ['on-accent on card', '#6b4a08', '#ffffff'],
    ['on-accent on tint-accent (dark)', '#e9c77a', '#33270a'],
    ['on-accent on card (dark)', '#e9c77a', '#13201f'],
    // --- FILL-ONLY steps. These are hover fills; they would FAIL as text on the card, which is why
    //     every warning-600 / danger-700 TEXT call site was migrated to an on-* token first. The pair
    //     that matters is the fill against its OWN label.
    //
    //     NB: write class names here WITHOUT their utility prefix. Tailwind auto-scans this file (it
    //     is not gitignored) and its extractor reads comments, so a literal `text-warning-600` in
    //     this comment silently emits that rule into the shipped bundle — reviving the very class the
    //     migration retired. Same reason the labels below say "opacity 90%" rather than the utility. ---
    ['white on success-700 (hover fill)', '#ffffff', '#166534'],
    ['white on danger-700 (hover fill)', '#ffffff', '#9f2724'],
    ['navy-950 on warning-600 (hover fill)', '#00252a', '#c87d06'],
    ['on-success on success-200 (hover)', '#11672f', '#c2efd3'],
    ['on-success on success-200 (hover, dark)', '#86d6a3', '#18402a'],
    // The trap, kept as a live number: `opacity` composites the LABEL too, so the 5.02:1 rest state of
    // white-on-success-600 became 4.17:1 on hover over the light card panel. WCAG 1.4.3 exempts
    // `disabled`, never `hover`. Both figures below must stay above 4.5:1.
    ['white on success-600 (rest)', '#ffffff', '#15803d'],
    ['white on success-600 at 90% opacity over card (HISTORICAL FAIL)', '#ffffff', '#2c8d50'],
    // --- danger-200 is FIXED (not theme-aware) because the sidebar is always navy. Backdrop is the
    //     bg-danger-600/20 chip composited on navy-900, the lighter end of the sidebar gradient. ---
    ['danger-200 on nav chip over navy-900', '#f4a6a4', '#273237'],
    ['on-danger on nav chip over navy-900 (the trap)', '#a82824', '#273237'],
    // --- body text ---
    ['ink-700 on app', '#344145', '#f1f6f6'],
    ['brand-400 on dark card', '#38b4ba', '#13201f'],
];

let failures = 0;
for (const [label, fg, bg] of PAIRS) {
    const r = ratio(fg, bg);
    const v = verdict(r);
    if (v === 'FAIL') failures++;
    console.log(`${label.padEnd(36)} ${fg} on ${bg}  ${r.toFixed(2).padStart(6)}:1  ${v}`);
}
console.log(`\n${failures} pair(s) below 3:1.`);
