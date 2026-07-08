// laravel/scripts/contrast.mjs
//
// Dev-only WCAG 2.2 contrast + CIEDE2000 perceptual-distance gate. Zero dependencies (Node >= 18, ESM).
// Run:  node scripts/contrast.mjs   (or  npm run contrast  from laravel/)
//
// This is a REAL CI gate now (wired into .github/workflows/laravel-ci.yml as a required step), not
// just a report a human reads: it `process.exit(1)`s on any non-KNOWN contrast FAIL or perceptual
// TOO-CLOSE pair, and on a broken CIEDE2000 implementation (see the self-test below). Re-run it
// whenever a colour token changes, and paste the output into public/images/BRAND_README.md.
//
// "KNOWN" rows: some entries below are DELIBERATE historical/baseline/trap pairs — kept so a
// regression is legible as a number, not a silent diff (see the `known:` field). They are still
// printed and still show their real verdict, but they are excluded from the exit code: they document
// a defect that was already fixed (or a pairing nothing ships), not a live claim about today's app.

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

// Each row is [label, fg, bg] or [label, fg, bg, { known: 'why this is a deliberate exception' }].
const PAIRS = [
    // --- brand on the light page surfaces ---
    // The status/brand-as-text sweep migrated every brand TEXT/GLYPH use to the theme-aware brand-700
    // (the ✕ diagnosis-chip glyph — DxChips/Admissions·Create/Registry — now uses it), so brand-500 no
    // longer ships as text in the Vue app (a11y-status-text.spec.js enforces it). It DOES still ship in
    // the dompdf PRINT templates (annual/statistics-pdf KPI numerals, #009ca6 15px bold on the #f1f6f6
    // cell = 3.05:1) — a print-only fail kept KNOWN pending a dedicated PDF-template a11y pass.
    ['brand-500 on brand-100 (ex DxChips ✕ glyph — now brand-700)', '#009ca6', '#d4f0ef', { info: true }],
    ['brand-500 on #f1f6f6 (PDF KPI numerals, 15px bold — still ships in print)', '#009ca6', '#f1f6f6', { known: 'print-only (dompdf): 15px bold ≠ large text; 3.05:1 on the report cell. Pending a PDF-template a11y pass.' }],
    ['brand-700 on white', '#00727b', '#ffffff'],
    ['brand-800 on white', '#00565e', '#ffffff'],
    // --- status colours as TEXT: the sweep routed every text/badge use through bg-tint-X + text-on-X
    //     (enforced by resources/js/__tests__/a11y-status-text.spec.js). What remains of these raw pairs
    //     is NON-TEXT only: bg-X-100 status ICON circles/chips (1.4.11 → 3:1 bar), which 4.32–4.39:1 clears.
    //     The warning/info rows no longer ship at all — kept as pre-fix baselines so a regression reads as
    //     a number. (See the on-*-on-card rows below for the pairs that NOW ship as text.) ---
    ['warning-500 on white (pre-fix baseline; migrated to on-warning)', '#e69209', '#ffffff', { info: true }],
    ['warning-500 on warning-100 (pre-fix baseline; migrated)', '#e69209', '#fdedd2', { info: true }],
    ['danger-600 on danger-100 (status ICON chips only — non-text UI)', '#c1302d', '#fbdcdc', { large: true }],
    ['success-600 on success-100 (status ICON circle only — non-text UI)', '#15803d', '#d8f5e3', { large: true }],
    ['info-500 on info-100 (pre-fix baseline; migrated to on-info)', '#2f7fe0', '#d7e9fb', { info: true }],
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
    // on-* as PLAIN TEXT on the card surface — now shipped widely after the sweep (menu-item labels,
    // form-error <p>, table numerals, status words that sit straight on the card, not on a tint chip).
    ['on-danger on card', '#a82824', '#ffffff'],
    ['on-danger on card (dark)', '#f4a6a4', '#13201f'],
    ['on-success on card', '#11672f', '#ffffff'],
    ['on-success on card (dark)', '#86d6a3', '#13201f'],
    ['on-info on card', '#1b5cad', '#ffffff'],
    ['on-info on card (dark)', '#9cc4f5', '#13201f'],
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
    ['accent-600 on accent-300/40 over card (BASELINE, light)', '#b9842a', '#faf0d9', { known: 'W0-T3e baseline — the PRE-fix Long-term badge pairing, kept so the fix reads as a number' }],
    ['accent-600 on accent-300/40 over card (BASELINE, dark)', '#b9842a', '#6d6a53', { known: 'W0-T3e baseline — same' }],
    ['accent-600 on white (BASELINE plain, light)', '#b9842a', '#ffffff', { info: true }],   // W0-T3j migrated every text-accent-600 → on-accent; this baseline documents the pre-fix fail, nothing ships it now
    ['on-accent on tint-accent', '#4f5314', '#eef0d6'],
    ['on-accent on card', '#4f5314', '#ffffff'],
    ['on-accent on app surface', '#4f5314', '#f1f6f6'],
    ['on-accent on tint-accent (dark)', '#cdd48a', '#262a10'],
    ['on-accent on card (dark)', '#cdd48a', '#13201f'],
    ['on-accent on app surface (dark)', '#cdd48a', '#0c1416'],
    // on-warning as plain text on the page surfaces (W0-T3j: the Statistics "(Fri/Sat ticks in amber)"
    // hint moved here from text-accent-600, to keep the word "amber" honest and clear AA).
    ['on-warning on card', '#8a5a00', '#ffffff'],
    ['on-warning on app surface', '#8a5a00', '#f1f6f6'],
    ['on-warning on card (dark)', '#f0c073', '#13201f'],
    ['on-warning on app surface (dark)', '#f0c073', '#0c1416'],
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
    ['white on success-600 at 90% opacity over card (HISTORICAL FAIL)', '#ffffff', '#2c8d50', { info: true }],   // documents the opacity-composite trap; the fade was removed (W0-T3f), nothing ships this
    // --- danger-200 is FIXED (not theme-aware) because the sidebar is always navy. Backdrop is the
    //     bg-danger-600/20 chip composited on navy-900, the lighter end of the sidebar gradient. ---
    ['danger-200 on nav chip over navy-900', '#f4a6a4', '#273237'],
    ['on-danger on nav chip over navy-900 (the trap)', '#a82824', '#273237', { known: 'the trap — on-danger was never meant for this backdrop; danger-200 (row above) is the token this chip actually uses (NavLink.vue)' }],
    // --- body text ---
    ['ink-700 on app', '#344145', '#f1f6f6'],
    ['brand-400 on dark card', '#38b4ba', '#13201f'],
];

// --- PERCEPTUAL DISTANCE ------------------------------------------------------------------------
// Contrast ratio answers "can I read this?". It cannot answer "is this the SAME colour as the badge
// next to it?" — two hues can both clear 7:1 on the same tint and still be one colour to the eye.
// That is precisely how the first `on-accent` shipped: AA-compliant, and visually identical to
// `on-warning` on a 10px badge.

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
/** Euclidean distance in Lab = CIE76 colour difference. Kept for reference/comparison only — the
 *  DELTAS verdicts below run on CIEDE2000 (`dE00`), not this. */
const dE76 = (a, b) => Math.hypot(...lab(a).map((v, i) => v - lab(b)[i]));

// --- CIEDE2000 -----------------------------------------------------------------------------------
// WHY: ΔE76 is plain Euclidean distance in Lab space, and Lab is known to be perceptually non-uniform
// — it over-states differences in some regions and under-states them in others, worst of all in the
// low-chroma blue/yellow region. That is exactly where the accent/warning hues live: the pre-fix
// warm-gold `on-accent` (#6b4a08) vs `on-warning` (#8a5a00) scored ΔE76 = 14.15 — comfortably past the
// old floor of 10 — while CIEDE2000 puts it at ΔE00 = 8.18 (see the self-test / DELTAS table below).
// ΔE76 was the wrong tool for the one decision this file exists to gate.
//
// CIEDE2000 (Sharma, Wu & Dalal, 2005; building on CIE 2000) fixes this with hue-dependent weighting
// functions (S_L, S_C, S_H), a chroma-dependent a*/b* rescaling (G), and a rotation term (R_T) for the
// blue region. Implemented directly from the published formula — no shortcuts — and validated below
// against the authors' own 34-pair reference dataset before it is trusted for anything.
const deg2rad = (d) => (d * Math.PI) / 180;
const rad2deg = (r) => (r * 180) / Math.PI;

/** CIEDE2000 colour difference between two [L*, a*, b*] triples. Kl = Kc = Kh = 1 (reference
 *  conditions — this is a UI/graphic-design gate, not an industrial-tolerancing one). */
function deltaE2000([L1, a1, b1], [L2, a2, b2]) {
    const C1 = Math.hypot(a1, b1), C2 = Math.hypot(a2, b2);
    const Cbar7 = ((C1 + C2) / 2) ** 7;
    const G = 0.5 * (1 - Math.sqrt(Cbar7 / (Cbar7 + 25 ** 7)));    // rescales a* toward Munsell-like uniformity
    const [a1p, a2p] = [a1 * (1 + G), a2 * (1 + G)];
    const [C1p, C2p] = [Math.hypot(a1p, b1), Math.hypot(a2p, b2)];
    const hueDeg = (a, b) => (a === 0 && b === 0 ? 0 : (rad2deg(Math.atan2(b, a)) + 360) % 360);
    const [h1p, h2p] = [hueDeg(a1p, b1), hueDeg(a2p, b2)];

    const dLp = L2 - L1;
    const dCp = C2p - C1p;
    const dhRaw = h2p - h1p;
    const dhp = C1p * C2p === 0 ? 0 : Math.abs(dhRaw) <= 180 ? dhRaw : dhRaw > 180 ? dhRaw - 360 : dhRaw + 360;
    const dHp = 2 * Math.sqrt(C1p * C2p) * Math.sin(deg2rad(dhp) / 2);

    const Lbarp = (L1 + L2) / 2;
    const Cbarp = (C1p + C2p) / 2;
    const hSum = h1p + h2p;
    const hbarp = C1p * C2p === 0 ? hSum : Math.abs(h1p - h2p) <= 180 ? hSum / 2 : hSum < 360 ? (hSum + 360) / 2 : (hSum - 360) / 2;

    const T = 1 - 0.17 * Math.cos(deg2rad(hbarp - 30)) + 0.24 * Math.cos(deg2rad(2 * hbarp))
        + 0.32 * Math.cos(deg2rad(3 * hbarp + 6)) - 0.2 * Math.cos(deg2rad(4 * hbarp - 63));
    const dTheta = 30 * Math.exp(-(((hbarp - 275) / 25) ** 2));
    const Cbarp7 = Cbarp ** 7;
    const Rc = 2 * Math.sqrt(Cbarp7 / (Cbarp7 + 25 ** 7));
    const Sl = 1 + (0.015 * (Lbarp - 50) ** 2) / Math.sqrt(20 + (Lbarp - 50) ** 2);
    const Sc = 1 + 0.045 * Cbarp;
    const Sh = 1 + 0.015 * Cbarp * T;
    const Rt = -Math.sin(deg2rad(2 * dTheta)) * Rc;

    const [dLt, dCt, dHt] = [dLp / Sl, dCp / Sc, dHp / Sh];
    return Math.sqrt(dLt ** 2 + dCt ** 2 + dHt ** 2 + Rt * dCt * dHt);
}
/** hex-pair convenience wrapper used by DELTAS below. */
const dE00 = (a, b) => deltaE2000(lab(a), lab(b));

// --- Self-test: Sharma, Wu & Dalal (2005) supplementary test dataset (34 reference pairs), from
// https://www.hajim.rochester.edu/ece/~gsharma/ciede2000/dataNprograms/ciede2000testdata.txt
// Columns: L1 a1 b1  L2 a2 b2  expectedDeltaE00. This is the standard reference set implementers
// validate against — it exercises the G rescaling, the hue-average branches (Δh', h̄'), and the
// R_T rotation term near the blue singularity. If this ever goes red, the CIEDE2000 maths above is
// broken and NOTHING it gates should be trusted — hence it is wired into the same exit code.
const SWD_2005 = [
    [50.0000, 2.6772, -79.7751, 50.0000, 0.0000, -82.7485, 2.0425],
    [50.0000, 3.1571, -77.2803, 50.0000, 0.0000, -82.7485, 2.8615],
    [50.0000, 2.8361, -74.0200, 50.0000, 0.0000, -82.7485, 3.4412],
    [50.0000, -1.3802, -84.2814, 50.0000, 0.0000, -82.7485, 1.0000],
    [50.0000, -1.1848, -84.8006, 50.0000, 0.0000, -82.7485, 1.0000],
    [50.0000, -0.9009, -85.5211, 50.0000, 0.0000, -82.7485, 1.0000],
    [50.0000, 0.0000, 0.0000, 50.0000, -1.0000, 2.0000, 2.3669],
    [50.0000, -1.0000, 2.0000, 50.0000, 0.0000, 0.0000, 2.3669],
    [50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0009, 7.1792],
    [50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0010, 7.1792],
    [50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0011, 7.2195],
    [50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0012, 7.2195],
    [50.0000, -0.0010, 2.4900, 50.0000, 0.0009, -2.4900, 4.8045],
    [50.0000, -0.0010, 2.4900, 50.0000, 0.0010, -2.4900, 4.8045],
    [50.0000, -0.0010, 2.4900, 50.0000, 0.0011, -2.4900, 4.7461],
    [50.0000, 2.5000, 0.0000, 50.0000, 0.0000, -2.5000, 4.3065],
    [50.0000, 2.5000, 0.0000, 73.0000, 25.0000, -18.0000, 27.1492],
    [50.0000, 2.5000, 0.0000, 61.0000, -5.0000, 29.0000, 22.8977],
    [50.0000, 2.5000, 0.0000, 56.0000, -27.0000, -3.0000, 31.9030],
    [50.0000, 2.5000, 0.0000, 58.0000, 24.0000, 15.0000, 19.4535],
    [50.0000, 2.5000, 0.0000, 50.0000, 3.1736, 0.5854, 1.0000],
    [50.0000, 2.5000, 0.0000, 50.0000, 3.2972, 0.0000, 1.0000],
    [50.0000, 2.5000, 0.0000, 50.0000, 1.8634, 0.5757, 1.0000],
    [50.0000, 2.5000, 0.0000, 50.0000, 3.2592, 0.3350, 1.0000],
    [60.2574, -34.0099, 36.2677, 60.4626, -34.1751, 39.4387, 1.2644],
    [63.0109, -31.0961, -5.8663, 62.8187, -29.7946, -4.0864, 1.2630],
    [61.2901, 3.7196, -5.3901, 61.4292, 2.2480, -4.9620, 1.8731],
    [35.0831, -44.1164, 3.7933, 35.0232, -40.0716, 1.5901, 1.8645],
    [22.7233, 20.0904, -46.6940, 23.0331, 14.9730, -42.5619, 2.0373],
    [36.4612, 47.8580, 18.3852, 36.2715, 50.5065, 21.2231, 1.4146],
    [90.8027, -2.0831, 1.4410, 91.1528, -1.6435, 0.0447, 1.4441],
    [90.9257, -0.5406, -0.9208, 88.6381, -0.8985, -0.7239, 1.5381],
    [6.7747, -0.2908, -2.4247, 5.8714, -0.0985, -2.2286, 0.6377],
    [2.0776, 0.0795, -1.1350, 0.9033, -0.0636, -0.5514, 0.9082],
];
const SWD_TOLERANCE = 1e-3; // published values are 4dp; our max observed error is ~5e-5 — generous headroom
console.log('--- CIEDE2000 self-test (Sharma, Wu & Dalal 2005 reference data, 34 pairs) ---');
let selfTestFailures = 0;
SWD_2005.forEach(([L1, a1, b1, L2, a2, b2, expected], i) => {
    const got = deltaE2000([L1, a1, b1], [L2, a2, b2]);
    const err = Math.abs(got - expected);
    const ok = err < SWD_TOLERANCE;
    if (!ok) selfTestFailures++;
    console.log(`  #${String(i + 1).padStart(2)}  got ${got.toFixed(4)}  expected ${expected.toFixed(4)}  err ${err.toExponential(2)}  ${ok ? 'OK' : 'FAIL'}`);
});
console.log(`${SWD_2005.length - selfTestFailures}/${SWD_2005.length} reference pairs match (tolerance ${SWD_TOLERANCE}).`);
if (selfTestFailures) console.log('!! CIEDE2000 implementation is WRONG — every dE00 verdict below is untrustworthy.');

// MIN_DE floors are now CIEDE2000 units, not ΔE76. This is NOT a like-for-like renumbering: CIEDE2000
// was fit so ΔE00 ≈ 1 approximates one JND under reference viewing conditions, versus ΔE76's own
// rule-of-thumb of ~2.3 units per JND — so "ΔE00 = 10" represents a MUCH larger perceptual gap than
// "ΔE76 = 10" did. There is no ISO/CIE-endorsed pass/fail number for either metric; the bands below are
// the commonly-used PRACTICAL heuristic for interpreting ΔE00 (imperceptible <1; perceptible on close
// inspection 1-2; perceptible at a glance 2-10; read as different colours >10) — a heuristic, same as
// the old comment's "~2.3 = JND" was, not a standard either metric defines for UI text.
//
//   MIN_DE00_TEXT  = 10 — "these must read as DIFFERENT COLOURS", not merely distinguishable shades.
//                    Sits right at the top of the "perceptible at a glance" band. Calibrated against
//                    the one incident this file exists to catch: the historical warm-gold trap below
//                    scores 8.18 (correctly TOO CLOSE) and the shipped olive pair scores 16-19 (clears
//                    with wide margin) — so 10 sits cleanly between the known-bad and known-good cases,
//                    not just a carried-over digit.
//   MIN_DE00_HOVER = 3 — a hover fill only needs to read as A STATE CHANGE, not a different colour; a
//                    floor at the JND end of "perceptible at a glance" is enough. All three current
//                    hover pairs (4.36 / 9.41 / 7.30) clear it; the tightest (warning) keeps real but
//                    honest headroom, unlike the near-zero margin an unexamined floor of 4 (its old
//                    ΔE76 number, reused verbatim) would have left it with — see the DELTAS table.
const MIN_DE00_TEXT = 10;
const MIN_DE00_HOVER = 3;

// Each row is [label, fg, bg, floor, known?]. `floor` is in ΔE00 units; 0 = informational only (no
// verdict). `known`, when present, is a string reason: the row documents a defect that was already
// fixed (or a pairing nothing ships) — printed as KNOWN and excluded from the exit code.
const DELTAS = [
    // Long-term badge vs Readmit badge (PatientFlags.vue) — adjacent, 10px semibold, same pill shape.
    ['TEXT  on-accent vs on-warning', '#4f5314', '#8a5a00', MIN_DE00_TEXT],
    ['TEXT  on-accent vs on-warning (dark)', '#cdd48a', '#f0c073', MIN_DE00_TEXT],
    // THE regression proof: CIEDE2000 correctly fails the pairing ΔE76 waved through (14.15, passed a
    // floor of 10). Kept live forever so this exact class of bug cannot silently recur unnoticed.
    ['TEXT  (HISTORICAL TRAP) old warm-gold on-accent vs on-warning', '#6b4a08', '#8a5a00', MIN_DE00_TEXT,
        { known: 'W0-T3h — the pre-fix warm-gold on-accent. dE76 = 14.15 (passed); dE00 = 8.18 (correctly fails) — the exact bug this gate exists to catch, kept as a live canary' }],
    // The fills sit behind those labels. On light they stay close; the distinction is the label.
    ['FILL  tint-accent vs tint-warning', '#eef0d6', '#fdedd2', 0],
    ['FILL  tint-accent vs tint-warning (dark)', '#262a10', '#3a2a09', 0],
    // A hover fill must read as a state change, not as a rendering artifact.
    ['HOVER warning-600 vs warning-500', '#d58506', '#e69209', MIN_DE00_HOVER],
    ['HOVER success-700 vs success-600', '#166534', '#15803d', MIN_DE00_HOVER],
    ['HOVER danger-700 vs danger-600', '#9f2724', '#c1302d', MIN_DE00_HOVER],
];

// Per-row WCAG target (W0-T3b). DEFAULT is the 4.5:1 NORMAL-TEXT bar. The old exit test was `r < 3`,
// which silently green-lit any body-text pair in the 3–4.5 gap — a real WCAG 1.4.3 AA failure the gate
// was supposed to catch but reported as merely "UI / large text only". A row opts DOWN only by saying
// what it actually is, so the default protects every NEW pair a future edit adds:
//   { large: true } → 3:1  (WCAG 1.4.3 large text: ≥24px, or ≥18.66px bold — and 1.4.11 non-text UI)
//   { info:  true } → 0     (informational BASELINE: a pre-fix number kept for legibility; nothing ships it)
// `{ known }` still excludes a row from the exit code — a documented, tracked defect — independent of its bar.
const barFor = (o) => (o?.info ? 0 : o?.large ? 3 : 4.5);

let failures = 0;
for (const [label, fg, bg, opts] of PAIRS) {
    const r = ratio(fg, bg);
    const v = verdict(r);
    const bar = barFor(opts);
    const fails = bar > 0 && r < bar;
    const isKnown = fails && opts?.known;
    if (fails && !opts?.known) failures++;
    const tag = fails ? (isKnown ? '  KNOWN' : '  FAIL') : '';
    console.log(`${label.padEnd(36)} ${fg} on ${bg}  ${r.toFixed(2).padStart(6)}:1  bar ${String(bar || '—').padStart(3)}  ${v}${tag}`);
}
console.log(`\n${failures} pair(s) below their per-row WCAG bar (excluding KNOWN rows).`);

console.log('\n--- perceptual distance: CIEDE2000 (verdict) + CIE76 (reference/comparison) ---');
let tooClose = 0;
for (const [label, a, b, floor, known] of DELTAS) {
    const d00 = dE00(a, b);
    const d76 = dE76(a, b);
    const bad = floor > 0 && d00 < floor;
    const isKnown = bad && known;
    if (bad && !isKnown) tooClose++;
    const floorTxt = floor ? `  (floor ${floor})${bad ? (isKnown ? '  KNOWN TOO CLOSE' : '  TOO CLOSE') : ''}` : '';
    console.log(`${label.padEnd(58)} ${a} vs ${b}  dE00 ${d00.toFixed(2).padStart(6)}  dE76 ${d76.toFixed(2).padStart(6)}${floorTxt}`);
}
console.log(`\n${tooClose} pair(s) below their CIEDE2000 perceptual floor (excluding KNOWN historical/trap rows).`);

const exitCode = failures || tooClose || selfTestFailures ? 1 : 0;
console.log(`\ncontrast gate: ${exitCode === 0 ? 'PASS' : 'FAIL'} (exit ${exitCode})`);
process.exit(exitCode);
