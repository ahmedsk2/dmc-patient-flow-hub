import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// WCAG 1.4.3 guard for STATUS/BRAND colours used as TEXT. The status text steps
// (text-{success,danger,warning,info}-{500,600}) and text-brand-500 are theme-INVARIANT
// literals in app.css — declared once in @theme, NOT remapped under .dark — so on the
// theme-aware card/app surface they read fine in light and fail in dark (text-danger-600 =
// 5.63:1 light / 2.97:1 dark; text-warning-500 ~2.2:1 in BOTH). Status text must go through
// the theme-aware text-on-{success,danger,warning,info} pair sitting on a bg-tint-* fill.
// This scans the whole shipped client so no page can quietly reintroduce a failing pair.
// Sibling of a11y-accent-text.spec.js.
const jsRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) return e.name === '__tests__' ? [] : walk(p);
    return /\.(vue|js)$/.test(e.name) ? [p] : [];
});

// Every status TEXT step that fails AA on the card/tint (they are theme-invariant, so a light-mode
// pass is not enough), plus text-brand-500. Broadened beyond the steps used at fix-time to the whole
// failing family so a FUTURE reintroduction (e.g. text-warning-600 as body text) also trips the guard;
// the verifier confirmed the extra steps have zero current source uses. bg-*/border-*/ring-* FILLS are
// unaffected — only `text-*` colour usage is forbidden. text-brand-{600,700,800} are theme-aware and AA,
// so they are NOT listed. (Any legitimate non-text/large use is carved out by the allowlist below.)
const FORBIDDEN = /\btext-(?:warning|success|danger|info)-(?:400|500|600|700)\b|\btext-brand-500\b/;

// Universal LEAVE patterns (any file):
//  - required-marker asterisk: text-danger-500 on a bare "*" is a non-text indicator (>=3:1)
//  - icon-only button: a hover:bg-X-100 paired with hover:text-X-{500,600} colours an SVG glyph
//    on hover only (resting colour is neutral ink); non-text UI, 1.4.11 holds it to 3:1.
const ASTERISK = /text-danger-500">\*/;
const ICON_HOVER = /hover:bg-(?:danger|success|warning|info)-100 hover:text-(?:danger|success|warning|info)-(?:500|600)/;

// File-scoped LEAVE cases (path suffix + a substring that uniquely identifies the line).
const ALLOW = [
    // complete-discharge ICON button (no text label; the SVG follows the class immediately). Moved
    // from Patients/Index.vue into PatientCard.vue when the board card was extracted (shared by the
    // Grouped + Split boards); the resting text-success-600 is a >=3:1 non-text glyph on bg-card.
    { file: 'Components/Patients/PatientCard.vue', needle: 'text-success-600 hover:bg-success-100"><svg' },
    // status ICON circles (bg-X-100 wrapper + SVG glyph, no text) — non-text, >=3:1
    // status ICON circle (bg-success-100 wrapper + SVG glyph, no text) — non-text, 4.32:1 >=3 both themes
    { file: 'Pages/Admissions/Index.vue', needle: 'place-items-center rounded-full bg-success-100 text-success-600' },
    // AdminBandCard icon chip keeps danger-600 on danger-100 (4.39:1) — see its comment
    { file: 'Components/AdminBandCard.vue', needle: "? 'bg-danger-100 text-danger-600' :" },
    // Statistics KPI numeral is 24px bold (large-text 3:1) — info key only; the danger key changed
    { file: 'Pages/Statistics/Index.vue', needle: "info: 'text-info-500'" },
    // NOTE: lib/ui.js locTone and the AppLayout idle-warning circle were the two sub-3:1 stragglers the
    // implementer flagged; the controller CONVERTED both to bg-tint-*/text-on-* (locTone ER was 2.15:1,
    // idle circle 2.15:1), updating ui.test.js — so they are no longer exempt and this guard enforces them.
];

const norm = (p) => p.replace(/\\/g, '/');
const exempt = (file, line) =>
    ASTERISK.test(line) || ICON_HOVER.test(line) ||
    ALLOW.some((a) => norm(file).endsWith(a.file) && line.includes(a.needle));

describe('a11y: status/brand colour is never used as small body text', () => {
    it('no shipped .vue/.js uses a failing status/brand TEXT step (use text-on-* instead)', () => {
        const offenders = [];
        for (const f of walk(jsRoot)) {
            fs.readFileSync(f, 'utf8').split('\n').forEach((line, i) => {
                if (FORBIDDEN.test(line) && !exempt(f, line)) {
                    offenders.push(`${norm(path.relative(jsRoot, f))}:${i + 1}  ${line.trim().slice(0, 90)}`);
                }
            });
        }
        expect(offenders, `status colour used as text (switch to text-on-*):\n${offenders.join('\n')}`).toEqual([]);
    });
});
