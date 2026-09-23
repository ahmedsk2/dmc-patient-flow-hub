import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Guard against the 2026-09-23 walkthrough defect: PatientMerge.vue declared a local component
 * with an options-API `template:` STRING (a runtime-compiled template). Vitest's own Vue build
 * bundles the template compiler, so a component like that mounts fine under test — but the
 * production bundle ships Vue's RUNTIME-ONLY build (no compiler, to keep the bundle small and
 * because our CSP forbids `'unsafe-eval'`, which a runtime compile would need anyway). With no
 * compiler, Vue silently renders such a component as an empty comment node instead of throwing, so
 * the failure is invisible in dev, in Vitest, and even in a passing build — only a live page shows
 * it. A mount test alone can't catch this class of bug (see PatientMerge.spec.js and
 * PatientPicker.spec.js for the concrete regression coverage); this static scan is what actually
 * prevents a *new* `template:` string from shipping. Every component in this app must be a real
 * .vue SFC (or `<script setup>`), never an inline options-API object with a `template:` string.
 */
const jsRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) return e.name === '__tests__' ? [] : walk(p);
    return /\.(vue|js)$/.test(e.name) ? [p] : [];
});

// Matches an options-API `template:` (or `"template":`) property declaring a JS string — the
// backtick / single- / double-quoted forms all count. A `.vue` SFC's OWN `<template>` block is a
// different syntax entirely (an XML-ish tag, not a JS object key) and never matches this.
const TEMPLATE_STRING_OPTION = /(?:^|[{,\s])(['"]?)template\1\s*:\s*[`'"]/m;

describe('no component ships a runtime-compiled `template:` string option', () => {
    it('every non-test .vue/.js file under resources/js is free of options-API template: strings', () => {
        const files = walk(jsRoot).filter((f) => !/\.(spec|test)\.js$/.test(f));
        const offenders = files.filter((f) => TEMPLATE_STRING_OPTION.test(fs.readFileSync(f, 'utf8')));
        const rel = (f) => path.relative(jsRoot, f);
        expect(
            offenders,
            `Found a component defined with an options-API \`template:\` string — this renders as an\n`
            + `empty comment in production (the runtime-only Vue build has no template compiler):\n`
            + offenders.map(rel).join('\n'),
        ).toEqual([]);
    });
});
