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
    //     kept below so a regression is legible as a number, not just as a missing row.
    //
    //     W0-T3h: the pair is OLIVE-gold. Contrast alone did not settle it — the first (warm-gold)
    //     pair passed every ratio below and was still wrong, because it landed a few degrees of hue
    //     from the `on-warning` pair and the Long-term badge stopped being distinguishable from the
    //     Readmit badge next to it. Ratios cannot see that; the DELTAS block at the bottom can. ---
    ['accent-600 on accent-300/40 over card (BASELINE, light)', '#b9842a', '#faf0d9'],
    ['accent-600 on accent-300/40 over card (BASELINE, dark)', '#b9842a', '#6d6a53'],
    ['accent-600 on white (BASELINE plain, light)', '#b9842a', '#ffffff'],
    ['on-accent on tint-accent', '#4f5314', '#eef0d6'],
    ['on-accent on card', '#4f5314', '#ffffff'],
    ['on-accent on app surface', '#4f5314', '#f1f6f6'],
    ['on-accent on tint-accent (dark)', '#cdd48a', '#262a10'],
    ['on-accent on card (dark)', '#cdd48a', '#13201f'],
    ['on-accent on app surface (dark)', '#cdd48a', '#0c1416'],
    // --- FILL-ONLY steps. These are hover fills; they would FAIL as text on the card, which is why
    //     every warning-600 / danger-700 TEXT call site was migrated to an on-* token first. The pair
    //     that matters is the fill against its OWN label.
    //
    //     NB: name colour STEPS here (e.g. `warning-600`), never a full utility. resources/css/app.css
    //     now pins its scan set with `source(none)` + an allow-list that excludes scripts/, so this
    //     file is no longer read by Tailwind's extractor. It USED to be: the extractor reads comments,
    //     and the earlier wording of this very warning spelled out the colour-text utility it was
    //     warning about — and thereby emitted that rule into the shipped bundle, reviving the class the
    //     migration had just retired. A bare step name resolves to nothing, so the rule stays dead even
    //     if the allow-list is ever loosened. Same reason the labels below say "opacity 90%". ---
    //
    //     A hover fill under a NEAR-BLACK label is a special case: darkening it always LOWERS
    //     contrast. warning-600 was #c87d06 and made hover the button's WEAKEST state (4.92:1, vs a
    //     6.53:1 rest). It is now the lighter #d58506 (5.54:1) — see the DELTAS block for why that is
    //     still a visible state change. ---
    ['white on success-700 (hover fill)', '#ffffff', '#166534'],
    ['white on danger-700 (hover fill)', '#ffffff', '#9f2724'],
    ['navy-950 on warning-500 (rest fill)', '#00252a', '#e69209'],
    ['navy-950 on warning-600 (hover fill)', '#00252a', '#d58506'],
    ['white on danger-600 (rest fill)', '#ffffff', '#c1302d'],
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

// --- PERCEPTUAL DISTANCE ------------------------------------------------------------------------
// Contrast ratio answers "can I read this?". It cannot answer "is this the SAME colour as the badge
// next to it?" — two hues can both clear 7:1 on the same tint and still be one colour to the eye.
// That is precisely how the first `on-accent` shipped: AA-compliant, and visually identical to
// `on-warning` on a 10px badge. CIE L*a*b* + dE76 is the cheapest check that sees it.
//
// dE76 rule of thumb: ~2.3 = just-noticeable difference under ideal conditions; small, low-contrast
// glyphs need considerably more. `MIN_DE` below is a pragmatic floor for 10px semibold text.

/** '#rrggbb' -> CIE L*a*b* (D65, 2-degree observer). Reuses `lin` above for the transfer function. */
const lab = (h) => {
    if (!/^#[0-9a-f]{6}$/i.test(h)) throw new Error(`bad hex: ${h}`);
    const n = parseInt(h.slice(1), 16);
    const [R, G, B] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(lin);
    const X = (0.4124564 * R + 0.3575761 * G + 0.1804375 * B) * 100;
    const Y = (0.2126729 * R + 0.7151522 * G + 0.0721750 * B) * 100;
    const Z = (0.0193339 * R + 0.1191920 * G + 0.9503041 * B) * 100;
    const [Xn, Yn, Zn] = [95.047, 100.0, 108.883];                 // D65 white point
    const f = (t) => (t > 216 / 24389 ? Math.cbrt(t) : (841 / 108) * t + 4 / 29);
    const [fx, fy, fz] = [f(X / Xn), f(Y / Yn), f(Z / Zn)];
    return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)];
};
/** Euclidean distance in Lab = CIE76 colour difference. */
const dE76 = (a, b) => Math.hypot(...lab(a).map((v, i) => v - lab(b)[i]));

const MIN_DE = 10;
const DELTAS = [
    // Long-term badge vs Readmit badge (PatientFlags.vue) — adjacent, 10px semibold, same pill shape.
    ['TEXT  on-accent vs on-warning', '#4f5314', '#8a5a00', MIN_DE],
    ['TEXT  on-accent vs on-warning (dark)', '#cdd48a', '#f0c073', MIN_DE],
    // The fills sit behind those labels. On light they stay close; the distinction is the label.
    ['FILL  tint-accent vs tint-warning', '#eef0d6', '#fdedd2', 0],
    ['FILL  tint-accent vs tint-warning (dark)', '#262a10', '#3a2a09', 0],
    // A hover fill must read as a state change, not as a rendering artifact.
    ['HOVER warning-600 vs warning-500', '#d58506', '#e69209', 4],
    ['HOVER success-700 vs success-600', '#166534', '#15803d', 4],
    ['HOVER danger-700 vs danger-600', '#9f2724', '#c1302d', 4],
];

let failures = 0;
for (const [label, fg, bg] of PAIRS) {
    const r = ratio(fg, bg);
    const v = verdict(r);
    if (v === 'FAIL') failures++;
    console.log(`${label.padEnd(36)} ${fg} on ${bg}  ${r.toFixed(2).padStart(6)}:1  ${v}`);
}
console.log(`\n${failures} pair(s) below 3:1.`);

console.log('\n--- perceptual distance (CIE dE76) ---');
let tooClose = 0;
for (const [label, a, b, floor] of DELTAS) {
    const d = dE76(a, b);
    const bad = floor > 0 && d < floor;
    if (bad) tooClose++;
    console.log(`${label.padEnd(36)} ${a} vs ${b}  dE ${d.toFixed(2).padStart(6)}${floor ? `  (floor ${floor})${bad ? '  TOO CLOSE' : ''}` : ''}`);
}
console.log(`\n${tooClose} pair(s) below their perceptual floor.`);
