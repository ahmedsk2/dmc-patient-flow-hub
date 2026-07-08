import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// W0-T3j regression guard. `text-accent-600` is the warm-gold accent SCALE step (#b9842a) used as
// TEXT — 3.28:1 on the light card, a WCAG 1.4.3 AA failure. accent-300..600 are declared as FILLS;
// accent-as-text goes through the AA-safe `text-on-accent` token (8.14:1 card / 10.67:1 dark), the
// same rule PatientFlags/StyleGuide already enforce for their own components. This scans the ENTIRE
// shipped client so no page can quietly reintroduce it (Consultations/Admissions/Import/Statistics
// were the stragglers fixed in W0-T3j). `text-accent-400` on Login's marketing panel is exempt: it
// is 24px bold decorative text (large-text bar 3:1), not body copy.
const jsRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) return e.name === '__tests__' ? [] : walk(p);
    return /\.(vue|js)$/.test(e.name) ? [p] : [];
});

describe('a11y: accent colour is never used as small body text', () => {
    it('no shipped .vue/.js spells text-accent-600 (use text-on-accent instead)', () => {
        const offenders = walk(jsRoot).filter((f) => /\btext-accent-600\b/.test(fs.readFileSync(f, 'utf8')));
        expect(offenders, `text-accent-600 (3.28:1) must be text-on-accent:\n${offenders.join('\n')}`).toEqual([]);
    });
});
