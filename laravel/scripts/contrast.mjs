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
