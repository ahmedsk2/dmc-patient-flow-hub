# EHC UI Wave 0 — Brand Foundation, "Census Board" Tokens & Style Guide — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the EHC brand foundation and the "Census Board" design signature as a single, AA-verified token layer, plus an internal `/style-guide` page that renders every primitive in both themes — with **zero behavior change to any existing page**.

**Architecture:** All design decisions live in exactly one stylesheet (`laravel/resources/css/app.css`) as Tailwind v4 `@theme` tokens + `@utility` classes. A dependency-free Node script computes WCAG contrast ratios and *drives* which tokens exist (several current status colors fail AA as text — the script proves it). Two new Vue components (`FlowAlert`, an extended `EhcLogo`) consume the tokens; a new admin-gated Inertia page (`StyleGuide`) renders them all as living documentation.

**Tech Stack:** Laravel 13 · Inertia 2 · Vue 3 `<script setup>` · Tailwind v4 (CSS-first `@theme`, no `tailwind.config.js`) · Vitest + @vue/test-utils · PHPUnit. **Zero new runtime dependencies.**

---

## Critical environment notes (read before Task 1)

- **Working directory for every command is `laravel/`** unless stated otherwise.
- **PHP is not on PATH.** Always invoke: `"C:/wamp64/bin/php/php8.5.0/php.exe"`. An xdebug DLL warning on stderr is harmless.
- **Composer is unavailable.** Do not add PHP packages.
- **`public/build/` is committed to git** (`laravel/.gitignore:17` — the deploy host has no Node). Any change to `resources/css/**` or `resources/js/**` **must** be followed by `npm run build`, and the rebuilt `public/build/` **must** be committed. Skipping this ships stale CSS/JS.
- **The PHP suite runs in two passes** (dompdf segfaults if all PDF tests share one process):
  ```bash
  "C:/wamp64/bin/php/php8.5.0/php.exe" artisan test --exclude-group pdf
  "C:/wamp64/bin/php/php8.5.0/php.exe" artisan test --group pdf
  ```
- **Spec:** `docs/superpowers/specs/2026-07-08-ehc-ui-ux-delta-design.md` (§2 = the design signature this plan implements).

---

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `laravel/scripts/contrast.mjs` | Dev-only, zero-dep WCAG contrast calculator. Single job: print ratio + AA verdict for a list of colour pairs. Re-runnable whenever tokens change. |
| `laravel/resources/js/Components/FlowAlert.vue` | The 3-tier callout primitive. One job: render an alert whose urgency is carried by icon + hidden SR prefix + text, never colour alone. |
| `laravel/resources/js/Components/__tests__/FlowAlert.spec.js` | Vitest spec for the above. |
| `laravel/resources/js/Components/__tests__/EhcLogo.spec.js` | Vitest spec for the logo's asset/fallback/mono behaviour. |
| `laravel/app/Http/Controllers/StyleGuideController.php` | One job: render the `StyleGuide` Inertia page. No queries, no data. |
| `laravel/resources/js/Pages/StyleGuide.vue` | Living design-system reference. Renders tokens + primitives; imports them rather than re-implementing. |
| `laravel/resources/js/Pages/__tests__/StyleGuide.spec.js` | Vitest smoke spec for the page. |
| `laravel/tests/Feature/StyleGuideTest.php` | Route gate (guest → login, non-admin → 403, admin → page). |
| `laravel/public/images/BRAND_README.md` | Brand-asset provenance + the recorded contrast table. |

**Modify:**

| File | Change |
|---|---|
| `laravel/resources/css/app.css` | Add signature tokens (`--color-hairline`, `--shadow-overlay`), AA-safe status tint/text tokens (`tint-*`, `on-*`), and the `@utility` classes (`status-rail`, `rail-*`, `border-hairline`, `row-pad`, `transition-row`) + density classes. |
| `laravel/resources/js/Components/EhcLogo.vue` | Add a `mono` prop (white/monochrome variant for dark headers + print) and re-arm the `<img>` when the variant flips. |
| `laravel/routes/web.php` | Import `StyleGuideController`; register `GET /style-guide` inside the existing `admin` group. |

**Design note — why `admin` and not plain `auth`:** the spec says the style guide is *"internal, auth-gated"*. The `admin` group is strictly stronger (admin ⊂ auth) and is the smallest surface that satisfies it. The page exposes **no patient data**, but internal tooling belongs behind the smallest gate.

---

## Task 1: Contrast audit script (drives every token decision)

**Files:**
- Create: `laravel/scripts/contrast.mjs`

- [ ] **Step 1: Write the script**

```javascript
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

/** '#rrggbb' -> relative luminance L. */
const lum = (hex) => {
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
```

- [ ] **Step 2: Run it**

Run (from `laravel/`):
```bash
node scripts/contrast.mjs
```

Expected — the first three and the four "CURRENT status" rows are the load-bearing results:

```
brand-500 on white                   #009ca6 on #ffffff    3.33:1  UI / large text only
brand-700 on white                   #00727b on #ffffff    5.69:1  AA text
brand-800 on white                   #00565e on #ffffff    8.42:1  AA text
warning-500 on white                 #e69209 on #ffffff    2.48:1  FAIL
warning-500 on warning-100           #e69209 on #fdedd2    2.15:1  FAIL
danger-600 on danger-100             #c1302d on #fbdcdc    4.39:1  UI / large text only
success-600 on success-100           #15803d on #d8f5e3    4.32:1  UI / large text only
info-500 on info-100                 #2f7fe0 on #d7e9fb    3.24:1  UI / large text only
```

> *(Corrected 2026-07-08 against the script's real output: `brand-500` is **3.33**, not the 3.29 I
> computed by hand, and `info-500 on info-100` is **3.24**, not 3.46. Verdict categories unchanged.
> The script is the source of truth — that is the entire reason it runs first.)*

Every `on-*` row must print **`AA text`**.

> These ratios were computed during planning. **Trust the script, not this table.** If any
> proposed `on-*` pair prints below `4.5`, darken the foreground hex by ~8 % and re-run until it
> reads `AA text`, then use the corrected hex in Task 2.

**Findings this proves (and Task 2 fixes):**
- `brand-500` (#009ca6) is **not** a legal text colour on white → text/links must use `brand-700`/`brand-800`.
- `warning-500` as text **fails outright** (2.48:1 on white). The existing status chips are illegible to AA.
- `danger-600`, `success-600`, `info-500` on their own 100-tints are all **below 4.5:1**.

- [ ] **Step 3: Commit**

```bash
git add laravel/scripts/contrast.mjs
git commit -m "ui(tokens): add zero-dep WCAG contrast audit script"
```

---

## Task 2: "Census Board" signature + AA-safe status tokens

**Files:**
- Modify: `laravel/resources/css/app.css` (four insertion points, below)

- [ ] **Step 1: Add the new tokens to the `@theme` block**

In `laravel/resources/css/app.css`, find the last line of the `@theme` block:

```css
    --radius-xl2: 1.1rem;
}
```

Replace it with:

```css
    --radius-xl2: 1.1rem;

    /* ---- "Census Board" signature (Wave 0) ----------------------------------------------
     * Hairline: a teal-tinted separator that REPLACES drop-shadows as the depth cue. Distinct
     * from --color-line (neutral). Shadow is reserved for TRUE overlays only. */
    --color-hairline: var(--surface-hairline);
    /* Tailwind v4 INLINES --shadow-* at build time; only the COLOUR slot can indirect via a var.
     * A `.dark { --shadow-overlay: ... }` override would be inert — hence --overlay-ink. */
    --shadow-overlay: 0 24px 60px -18px var(--overlay-ink);

    /* Status callout surfaces + their AA-verified text colours. Theme-aware: the tint darkens
     * and the text lightens under .dark, while the HUE is held constant so a status never
     * re-reads across a shift change. Ratios proven by scripts/contrast.mjs. */
    --color-tint-info: var(--tint-info);
    --color-tint-warning: var(--tint-warning);
    --color-tint-danger: var(--tint-danger);
    --color-tint-success: var(--tint-success);
    --color-on-info: var(--on-info);
    --color-on-warning: var(--on-warning);
    --color-on-danger: var(--on-danger);
    --color-on-success: var(--on-success);
}
```

- [ ] **Step 2: Add the light-theme raw values**

Find, inside the `:root` block:

```css
    --chart-stroke: #ffffff;  /* donut slice gaps / card-matched separators */
}
```

Replace with:

```css
    --chart-stroke: #ffffff;  /* donut slice gaps / card-matched separators */

    /* Signature hairline — brand teal at low alpha over the light surfaces. */
    --surface-hairline: rgb(0 156 166 / 0.16);
    /* Overlay-shadow colour — the only part of a --shadow-* token that can indirect per theme. */
    --overlay-ink: rgb(30 42 46 / 0.35);

    /* Status tints + AA-safe text (light). warning-500/info-500/etc. FAIL as text — see
     * scripts/contrast.mjs. These `on-*` values all clear 4.5:1 on their tint AND on white. */
    --tint-info: #d7e9fb;
    --tint-warning: #fdedd2;
    --tint-danger: #fbdcdc;
    --tint-success: #d8f5e3;
    --on-info: #1b5cad;
    --on-warning: #8a5a00;
    --on-danger: #a82824;
    --on-success: #11672f;
}
```

- [ ] **Step 3: Add the dark-theme overrides**

Find, inside the first `.dark` block:

```css
    --shadow-card-lg: 0 2px 6px rgb(0 0 0 / 0.5), 0 18px 40px -14px rgb(0 0 0 / 0.75);
}
```

Replace with:

```css
    --shadow-card-lg: 0 2px 6px rgb(0 0 0 / 0.5), 0 18px 40px -14px rgb(0 0 0 / 0.75);

    /* Signature hairline reads brighter against the dark page. */
    --surface-hairline: rgb(0 156 166 / 0.26);
    --overlay-ink: rgb(0 0 0 / 0.7);

    /* Status tints go deep + opaque (exact contrast maths, no alpha compositing); the `on-*`
     * text lightens. Hues held constant with the light theme. */
    --tint-info: #12293f;
    --tint-warning: #3a2a09;
    --tint-danger: #3a1a19;
    --tint-success: #10291a;
    --on-info: #9cc4f5;
    --on-warning: #f0c073;
    --on-danger: #f4a6a4;
    --on-success: #86d6a3;
}
```

- [ ] **Step 4: Add the density classes to `@layer base`**

Find, inside `@layer base`:

```css
    /* tabular numerals for KPI figures */
    .nums {
        font-variant-numeric: tabular-nums;
        font-feature-settings: 'tnum';
    }
```

Add immediately after it (still inside `@layer base`):

```css
    /* Density — a container sets the row rhythm; `row-pad` rows consume it. Wave 2 will migrate the
     * EXISTING toggle (localStorage['dmc-density'] + the px/py ternaries in Pages/Patients/Index.vue)
     * onto this mechanism — the values line up exactly. Do not build a second density system. */
    .density-comfortable { --row-py: 0.5rem; }
    .density-compact { --row-py: 0.25rem; }
```

- [ ] **Step 5: Add the signature utilities**

Find this block (the last of the existing semantic utilities):

```css
@utility divide-line {
    & > :not([hidden]) ~ :not([hidden]) {
        border-color: var(--color-line);
    }
}
```

Add immediately after it:

```css
/* ----- "Census Board" signature utilities (Wave 0) --------------------------------------------
 * Flat surfaces separated by teal-tinted hairlines, NOT floating drop-shadow cards. Shadow is
 * reserved for TRUE overlays (command palette, dialogs) via `shadow-overlay`. Every patient row
 * carries a 3px inset status rail — the app's fingerprint; the board reads as a stack of tickets.
 * Logical properties (border-inline-start) are used so a future RTL mode is a no-op (Wave 5).
 *
 * `bg-/text-/border-/ring-/divide-/outline-hairline` are AUTO-GENERATED from the --color-hairline
 * theme key — no @utility needed. (The older `-line` set above duplicates this; harmless, and out
 * of scope to remove here.)
 */

/* Custom properties inherit by default; without this a toned row would silently re-tone every
 * NESTED rail (the board nests 3 deep), so an un-toned child would render critical red. `syntax: '*'`
 * with no initial-value keeps the guaranteed-invalid initial value, so the var() fallback still fires. */
@property --rail-color {
    syntax: '*';
    inherits: false;
}

/* The fingerprint. Pair with exactly one rail-* tone. Tone names match the colour tokens they
 * dereference (success/danger) — NOT alert vocabulary (ok/critical). */
@utility status-rail {
    border-inline-start: 3px solid var(--rail-color, var(--color-ink-200));
}
@utility rail-neutral {
    --rail-color: var(--color-ink-200);
}
@utility rail-info {
    --rail-color: var(--color-info-500);
}
@utility rail-success {
    --rail-color: var(--color-success-500);
}
@utility rail-warning {
    --rail-color: var(--color-warning-500);
}
@utility rail-danger {
    --rail-color: var(--color-danger-500);
}

/* Density-aware row padding. --row-py is deliberately NOT declared in :root — this fallback IS the
 * default; a .density-* container overrides it. */
@utility row-pad {
    padding-block: var(--row-py, 0.5rem);
}

/* The ONE meaningful transition in the signature (120ms). The global prefers-reduced-motion
 * block at the top of this file neutralizes it automatically. */
@utility transition-row {
    transition:
        background-color 120ms ease,
        border-color 120ms ease;
}
```

- [ ] **Step 6: Verify the stylesheet compiles and the tokens ship**

Run (from `laravel/`):
```bash
npm run build
grep -c "surface-hairline" public/build/assets/*.css
```
Expected: the build completes with no error, and `grep` prints a count **≥ 1** (the `:root` raw vars are always emitted; the `@utility` classes only appear once a component uses them — that happens in Task 3).

- [ ] **Step 7: Verify no existing page regressed**

Run:
```bash
npx vitest run
```
Expected: PASS — all existing specs green (this task adds CSS only; no component changed).

- [ ] **Step 8: Commit**

```bash
git add laravel/resources/css/app.css laravel/public/build
git commit -m "ui(tokens): Census Board signature + AA-safe status tint/text tokens"
```

---

## Task 3: `FlowAlert.vue` — the 3-tier callout

Urgency is carried by **three redundant signals** (visually-hidden SR prefix, icon, text title) so colour is never load-bearing — the NHS pattern, and WCAG 1.4.1.

**Files:**
- Create: `laravel/resources/js/Components/FlowAlert.vue`
- Test: `laravel/resources/js/Components/__tests__/FlowAlert.spec.js`

- [ ] **Step 1: Write the failing test**

```javascript
// laravel/resources/js/Components/__tests__/FlowAlert.spec.js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import FlowAlert from '@/Components/FlowAlert.vue';

const mountAlert = (props = {}, slots = {}) =>
    mount(FlowAlert, { props: { title: 'Two discharges are overdue', ...props }, slots });

describe('FlowAlert', () => {
    it('renders the title and the default-slot body', () => {
        const w = mountAlert({}, { default: 'Review the boarding worklist.' });
        expect(w.text()).toContain('Two discharges are overdue');
        expect(w.text()).toContain('Review the boarding worklist.');
    });

    it('carries a visually-hidden screen-reader prefix per tone (colour is never alone)', () => {
        expect(mountAlert({ tone: 'info' }).find('.sr-only').text()).toBe('Information:');
        expect(mountAlert({ tone: 'warning' }).find('.sr-only').text()).toBe('Important:');
        expect(mountAlert({ tone: 'critical' }).find('.sr-only').text()).toBe('Action needed:');
    });

    it('always renders a decorative icon beside the text', () => {
        const svg = mountAlert({ tone: 'critical' }).find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.attributes('aria-hidden')).toBe('true');
    });

    it('applies the status-rail plus the matching tone rail class', () => {
        expect(mountAlert({ tone: 'info' }).classes()).toContain('status-rail');
        expect(mountAlert({ tone: 'info' }).classes()).toContain('rail-info');
        expect(mountAlert({ tone: 'warning' }).classes()).toContain('rail-warning');
        expect(mountAlert({ tone: 'critical' }).classes()).toContain('rail-danger');
    });

    it('uses the AA-safe on-* text token, never the raw status-500 colour', () => {
        expect(mountAlert({ tone: 'warning' }).classes()).toContain('text-on-warning');
        expect(mountAlert({ tone: 'warning' }).classes()).toContain('bg-tint-warning');
        expect(mountAlert({ tone: 'warning' }).classes()).not.toContain('text-warning-500');
    });

    it('gives critical a polite live region (role=status); calmer tones are role=note', () => {
        expect(mountAlert({ tone: 'critical' }).attributes('role')).toBe('status');
        expect(mountAlert({ tone: 'warning' }).attributes('role')).toBe('note');
        expect(mountAlert({ tone: 'info' }).attributes('role')).toBe('note');
    });

    // A missing map key would silently erase all three redundant channels at once.
    it.each(['info', 'warning', 'critical'])('tone %s renders all three redundant signals', (tone) => {
        const w = mountAlert({ tone });
        const has = (re) => w.classes().some((c) => re.test(c));
        expect(w.find('.sr-only').text()).not.toBe('');
        expect(w.find('path').attributes('d')).toBeTruthy();
        expect(has(/^rail-/) && has(/^bg-tint-/) && has(/^text-on-/)).toBe(true);
    });

    it('degrades an unknown tone UP to the critical treatment, never to a bare grey box', () => {
        const w = mountAlert({ tone: 'criticl' });   // typo
        expect(w.classes()).toContain('rail-danger');
        expect(w.find('.sr-only').text()).toBe('Action needed:');
    });

    it('defaults to the info tone', () => {
        expect(mountAlert().classes()).toContain('rail-info');
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run (from `laravel/`):
```bash
npx vitest run resources/js/Components/__tests__/FlowAlert.spec.js
```
Expected: FAIL — `Failed to resolve import "@/Components/FlowAlert.vue"`.

- [ ] **Step 3: Write the component**

```vue
<!-- laravel/resources/js/Components/FlowAlert.vue -->
<script setup>
/**
 * FlowAlert — the 3-tier callout of the "Census Board" signature (Wave 0).
 *
 * Rule (NHS-derived, WCAG 1.4.1): urgency is NEVER carried by colour alone. Every alert renders
 * three redundant signals — a visually-hidden screen-reader prefix, an icon, and a text title —
 * on top of the tone colour. `critical` gets role="alert" so assistive tech announces it; calmer
 * tones use role="note" and stay quiet.
 *
 * Colours come from the AA-verified `tint-*` / `on-*` token pairs (scripts/contrast.mjs proves the
 * ratios in BOTH themes). Never use the raw `*-500` status colours as text — most fail AA.
 *
 * Cap at two callouts per view — see docs/superpowers/specs/2026-07-08-ehc-ui-ux-delta-design.md §2.
 */
import { computed } from 'vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'info',
        validator: (v) => ['info', 'warning', 'critical'].includes(v),
    },
    title: { type: String, required: true },
});

// Trailing space so AT announces "Important: Two discharges…" rather than running the prefix into
// the title. Template whitespace can't do this (Vue's `condense` strips it); VTU's .text() trims,
// so the toBe('Important:') assertions still hold.
const PREFIX = { info: 'Information: ', warning: 'Important: ', critical: 'Action needed: ' };
const RAIL = { info: 'rail-info', warning: 'rail-warning', critical: 'rail-danger' };
const TINT = { info: 'bg-tint-info', warning: 'bg-tint-warning', critical: 'bg-tint-danger' };
const TEXT = { info: 'text-on-info', warning: 'text-on-warning', critical: 'text-on-danger' };
// 24x24 paths, all rendered as OUTLINES (the <svg> sets fill="none"). Three distinct SILHOUETTES —
// circle (info) · triangle (warning) · octagon (critical) — so the icon still distinguishes the tier
// when hue is unavailable. The SR prefix and title carry the real load.
const ICON = {
    info: 'M12 16v-4m0-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    warning: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z',
    critical: 'M12 8v4m0 4h.01M8.5 2h7L22 8.5v7L15.5 22h-7L2 15.5v-7L8.5 2Z',
};

// An unknown tone must never render as an unmarked grey box — a missing key would silently erase
// ALL THREE redundant channels at once (no rail, no tint, no prefix, no icon). Degrade UP: in a
// clinical UI an over-loud alert beats an invisible one. Vue strips prop validators from production
// builds, so the validator alone cannot protect this. Object.hasOwn, not `in` ('constructor' in ICON).
const t = computed(() => (Object.hasOwn(ICON, props.tone) ? props.tone : 'critical'));

// role="alert" is assertive: a live region already in the DOM at page load is NOT announced, but on
// an Inertia SPA navigation it interrupts the heading announcement. These callouts are a standing
// summary of state, not a transient event — so `status` (polite: queues) is correct, and `note` for
// the calmer tones. Urgency is carried by the prefix/icon/title, not by interrupting the user.
const role = computed(() => (t.value === 'critical' ? 'status' : 'note'));
</script>

<!-- rounded-e-* (logical) leaves the rail edge square: the "ticket" silhouette.
     NOTE: this comment MUST live outside <template>. A root-level comment inside <template> makes
     Vue compile the SFC as a multi-root Fragment, and @vue/test-utils' wrapper.classes() /
     wrapper.attributes() then silently return []/undefined instead of the root div's values. -->
<template>
    <div :role="role" :class="['status-rail flex gap-3 rounded-e-xl p-3', RAIL[t], TINT[t], TEXT[t]]">
        <svg
            class="mt-0.5 h-5 w-5 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path :d="ICON[t]" />
        </svg>
        <div class="min-w-0">
            <p class="text-sm font-semibold"><span class="sr-only">{{ PREFIX[t] }}</span>{{ title }}</p>
            <!-- NO opacity-* here. `opacity` composites the text over the tint: at 90% the light-theme
                 on-info/on-warning pairs fall to 4.41:1 and 4.21:1, below the 4.5:1 AA bar for 14px
                 text. Hierarchy comes from font-weight (title is semibold), never from opacity. -->
            <div v-if="$slots.default" class="mt-0.5 text-sm"><slot /></div>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
npx vitest run resources/js/Components/__tests__/FlowAlert.spec.js
```
Expected: PASS — 7 tests.

- [ ] **Step 5: Confirm the new utilities now reach the built stylesheet**

Run:
```bash
npm run build
grep -o "status-rail" public/build/assets/*.css | head -1
grep -o "rail-danger" public/build/assets/*.css | head -1
```
Expected: each `grep` prints its class name once (Tailwind v4 only emits utilities it sees used in `@source`-scanned files — `FlowAlert.vue` is the first consumer).

- [ ] **Step 6: Commit**

```bash
git add laravel/resources/js/Components/FlowAlert.vue laravel/resources/js/Components/__tests__/FlowAlert.spec.js laravel/public/build
git commit -m "ui(tokens): add FlowAlert 3-tier callout (icon + SR prefix + text, never colour alone)"
```

---

## Task 4: `EhcLogo` monochrome variant

**Files:**
- Modify: `laravel/resources/js/Components/EhcLogo.vue`
- Test: `laravel/resources/js/Components/__tests__/EhcLogo.spec.js`

- [ ] **Step 1: Write the failing test**

```javascript
// laravel/resources/js/Components/__tests__/EhcLogo.spec.js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';

import EhcLogo from '@/Components/EhcLogo.vue';

describe('EhcLogo', () => {
    it('prefers the official asset at /images/ehc-logo.svg', () => {
        const img = mount(EhcLogo).find('img');
        expect(img.attributes('src')).toBe('/images/ehc-logo.svg');
        expect(img.attributes('alt')).toBe('Eastern Health Cluster');
    });

    it('requests the mono asset when mono is set (dark headers, print)', () => {
        expect(mount(EhcLogo, { props: { mono: true } }).find('img').attributes('src'))
            .toBe('/images/ehc-logo-mono.svg');
    });

    it('falls back to the inline recreation when the asset 404s', async () => {
        const w = mount(EhcLogo);
        await w.find('img').trigger('error');
        expect(w.find('img').exists()).toBe(false);
        const svg = w.find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.attributes('aria-label')).toBe('Eastern Health Cluster');
        expect(w.findAll('path')).toHaveLength(5); // the five flame petals
    });

    it('the mono fallback paints in currentColor and drops the brand gradient', async () => {
        const w = mount(EhcLogo, { props: { mono: true } });
        await w.find('img').trigger('error');
        expect(w.find('linearGradient').exists()).toBe(false);
        expect(w.find('g').attributes('fill')).toBe('currentColor');
    });

    it('re-arms the <img> when the mono prop flips (the other file may well exist)', async () => {
        const w = mount(EhcLogo);
        await w.find('img').trigger('error');
        expect(w.find('svg').exists()).toBe(true);
        await w.setProps({ mono: true });
        expect(w.find('img').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
npx vitest run resources/js/Components/__tests__/EhcLogo.spec.js
```
Expected: FAIL — the `mono` tests fail (`src` is still `/images/ehc-logo.svg`; `<g>` fill is the gradient URL).

- [ ] **Step 3: Rewrite the component**

Replace the whole of `laravel/resources/js/Components/EhcLogo.vue` with:

```vue
<script setup>
import { ref, computed, watch } from 'vue';

// Uses the official EHC asset if present at /images/ehc-logo.svg — or /images/ehc-logo-mono.svg for
// dark chrome and print — so dropping the file in needs no rebuild. Otherwise renders a vector
// recreation of the EHC 5-point flame star with a central medallion.
//
// `mono` paints the recreation in currentColor (no brand gradient) so it sits correctly on the dark
// teal sidebar and prints as solid ink.
const props = defineProps({ mono: { type: Boolean, default: false } });

const failed = ref(false);
const src = computed(() => (props.mono ? '/images/ehc-logo-mono.svg' : '/images/ehc-logo.svg'));
// A different file may exist even if the other 404'd — re-arm the <img> when the variant flips.
watch(() => props.mono, () => { failed.value = false; });

const petals = [0, 72, 144, 216, 288];
</script>

<template>
    <img v-if="!failed" :src="src" alt="Eastern Health Cluster" @error="failed = true" />
    <svg v-else viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Eastern Health Cluster">
        <defs v-if="!mono">
            <linearGradient id="ehcFlame" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#2f97c4" />
                <stop offset="55%" stop-color="#1f86bf" />
                <stop offset="100%" stop-color="#0e6fa6" />
            </linearGradient>
        </defs>
        <g :fill="mono ? 'currentColor' : 'url(#ehcFlame)'">
            <path
                v-for="a in petals"
                :key="a"
                d="M50,50 C 40,40 36,22 50,7 C 64,22 60,40 50,50 Z"
                :transform="`rotate(${a} 50 50)`"
            />
        </g>
        <circle
            cx="50" cy="50" r="10.5"
            :fill="mono ? 'currentColor' : '#eaf4f4'"
            :fill-opacity="mono ? 0.25 : 1"
            :stroke="mono ? 'currentColor' : '#2f97c4'"
            stroke-width="1.4"
        />
        <circle cx="50" cy="50" r="3.4" :fill="mono ? 'currentColor' : '#7fa8bd'" />
    </svg>
</template>
```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
npx vitest run resources/js/Components/__tests__/EhcLogo.spec.js
```
Expected: PASS — 5 tests.

- [ ] **Step 5: Verify the existing sidebar still renders the logo**

Run:
```bash
npx vitest run resources/js/__tests__/AppLayout.nav.spec.js
```
Expected: PASS (the `mono` prop defaults to `false`, so `AppLayout.vue:313` is unchanged).

- [ ] **Step 6: Commit**

```bash
git add laravel/resources/js/Components/EhcLogo.vue laravel/resources/js/Components/__tests__/EhcLogo.spec.js
git commit -m "ui(brand): EhcLogo mono variant for dark chrome + print"
```

---

## Task 5: Brand assets + `BRAND_README.md`

**Files:**
- Create: `laravel/public/images/BRAND_README.md`
- Create (best-effort): `laravel/public/images/ehc-logo.svg`, `laravel/public/images/ehc-logo-mono.svg`

> **Authorization gate.** The EHC mark is copyrighted. Only download and commit it because the
> project maintainer — the department's own maintainer — has directed it. **If the site is
> unreachable, or the mark cannot be retrieved cleanly, STOP: do not scrape or reconstruct it.**
> The `EhcLogo` fallback already renders a recreation, so the app is never blocked. Record whichever
> outcome occurred in `BRAND_README.md`.

- [ ] **Step 1: Attempt to retrieve the official mark**

Run (from `laravel/`):
```bash
curl -sSL -o /tmp/ehc-home.html -w '%{http_code}\n' https://www.ehc.med.sa/
grep -oiE '(src|href)="[^"]*(logo|brand)[^"]*\.(svg|png)"' /tmp/ehc-home.html | head -5
```
Expected: `200` plus zero or more candidate asset paths.

- **If a candidate is found:** download it to `laravel/public/images/ehc-logo.svg` (or `.png` —
  if you use a different extension you must update `src` in `EhcLogo.vue`). Then produce
  `ehc-logo-mono.svg` by taking the SVG and replacing every `fill`/`stop-color` with
  `currentColor`, removing any `<linearGradient>`.
- **If the fetch returns non-200, or no candidate matches:** create neither file. The recreation
  ships. Continue to Step 2 and record the failure.

- [ ] **Step 2: Sample the brand colours actually in use**

If an SVG was retrieved:
```bash
grep -oiE '#[0-9a-f]{6}|#[0-9a-f]{3}' laravel/public/images/ehc-logo.svg | sort -u
```
Record every hex found. If no asset was retrieved, record the palette already committed in
`resources/css/app.css:39-49` (primary `#009ca6`, deep `#00565e`, light aqua `#a9ded8`) and note
that its provenance is a prior renovation wave, not a fresh sample.

- [ ] **Step 3: Re-run the contrast audit and capture its output**

Run:
```bash
node scripts/contrast.mjs > /tmp/contrast.txt
cat /tmp/contrast.txt
```
Expected: every `on-*` row reads `AA text`; the four "CURRENT status" rows read `FAIL` or
`UI / large text only` (this is the evidence for Task 2's new tokens).

- [ ] **Step 4: Write `BRAND_README.md`**

Create `laravel/public/images/BRAND_README.md`, replacing the three bracketed values with real
data from Steps 1–3. **No brackets may remain in the committed file.**

```markdown
# EHC brand assets — provenance & contrast record

## Logo

| Field | Value |
|---|---|
| Source | https://www.ehc.med.sa/ |
| Retrieved | <the date you ran Task 5, ISO YYYY-MM-DD> |
| Outcome | <one of: "official mark downloaded" / "site unreachable (HTTP <code>) — recreation ships" / "no logo asset found — recreation ships"> |
| Files | `ehc-logo.svg` (colour), `ehc-logo-mono.svg` (currentColor, dark chrome + print) — absent if the fetch failed |
| Fallback | `resources/js/Components/EhcLogo.vue` renders a vector recreation of the 5-petal flame star whenever the asset is missing. The app is never blocked. |

Drop-in rule: save the square star **mark** only (transparent background). The bilingual
"Eastern Health Cluster / تجمع الشرقية الصحي" wordmark already appears as text in the UI.
Never distort, recolour arbitrarily, or add effects to the mark.

## Sampled palette

<paste the hex values from Step 2, one per line>

## Contrast record (WCAG 2.2)

Regenerate any time a colour token changes:

```
node scripts/contrast.mjs
```

<paste the verbatim output of Step 3 here>

### Rules this establishes

- `brand-500` (#009ca6) is **3.29:1** on white — legal for **fills, borders and large text only**.
  Body text and links must use `brand-700` (5.69:1) or `brand-800` (8.42:1).
- The raw `*-500` status colours **fail AA as text** (`warning-500` is 2.48:1 on white). Always use
  the `on-info` / `on-warning` / `on-danger` / `on-success` tokens for status text, paired with the
  matching `tint-*` surface. Both themes are verified above.
- Status is never conveyed by colour alone — pair it with an icon and a text label
  (see `resources/js/Components/FlowAlert.vue`).
```

- [ ] **Step 5: Verify no brackets remain**

Run (from repo root):
```bash
grep -n "<the date\|<one of\|<paste\|<code>" laravel/public/images/BRAND_README.md
```
Expected: **no output** (exit code 1). Any hit is an unfilled placeholder — fix it before committing.

- [ ] **Step 6: Commit**

```bash
git add laravel/public/images/BRAND_README.md
git add laravel/public/images/ehc-logo.svg laravel/public/images/ehc-logo-mono.svg 2>/dev/null || true
git commit -m "ui(brand): record EHC asset provenance + WCAG contrast audit"
```

---

## Task 6: `/style-guide` route + controller

**Files:**
- Create: `laravel/app/Http/Controllers/StyleGuideController.php`
- Modify: `laravel/routes/web.php`
- Test: `laravel/tests/Feature/StyleGuideTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// laravel/tests/Feature/StyleGuideTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 0 — the internal /style-guide design reference. It renders only design tokens and UI
 * primitives (no patient data, no queries), but it is internal tooling, so it lives inside the
 * admin group: the smallest gate that satisfies the spec's "auth-gated" requirement.
 */
class StyleGuideTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role): User
    {
        return User::create([
            'username' => 'sg_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'SG',
            'full_name' => 'SG User',
            'password' => 'secret12345',
            'role' => $role,
            'active' => 1,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/style-guide')->assertRedirect('/login');
    }

    public function test_a_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->get('/style-guide')
            ->assertForbidden();
    }

    public function test_an_admin_sees_the_style_guide(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/style-guide')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('StyleGuide'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (from `laravel/`):
```bash
"C:/wamp64/bin/php/php8.5.0/php.exe" artisan test --filter=StyleGuideTest
```
Expected: FAIL — all three tests 404 (`Expected response status code [200] but received 404`), because the route does not exist.

- [ ] **Step 3: Write the controller**

```php
<?php
// laravel/app/Http/Controllers/StyleGuideController.php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Wave 0 — the internal design-system reference page. Renders the "Census Board" signature: the
 * colour tokens, the status-rail language, button hierarchy, status chips, FlowAlert callouts, a
 * form field, and a KPI numeral — in whichever theme the viewer has selected.
 *
 * Deliberately holds NO patient data and issues NO queries. Admin-gated because it is internal
 * tooling, not because it is sensitive.
 */
class StyleGuideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('StyleGuide');
    }
}
```

- [ ] **Step 4: Register the route**

In `laravel/routes/web.php`, add the import in alphabetical order — find:

```php
use App\Http\Controllers\StepUpController;
use App\Http\Controllers\TourController;
```

Replace with:

```php
use App\Http\Controllers\StepUpController;
use App\Http\Controllers\StyleGuideController;
use App\Http\Controllers\TourController;
```

Then find the opening of the admin group (`routes/web.php:145`):

```php
    Route::middleware('admin')->group(function () {
        Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
```

Replace with:

```php
    Route::middleware('admin')->group(function () {
        // Wave 0 — internal design-system reference. No patient data; admin-gated as internal tooling.
        Route::get('/style-guide', [StyleGuideController::class, 'index'])->name('styleguide');

        Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
```

- [ ] **Step 5: Run the test — it should now fail only on the missing page component**

Run:
```bash
"C:/wamp64/bin/php/php8.5.0/php.exe" artisan test --filter=StyleGuideTest
```
Expected: the guest and non-admin tests **PASS**; `test_an_admin_sees_the_style_guide` still passes too — `Inertia::render('StyleGuide')` does not require the `.vue` file to exist server-side (`withoutVite()` is on in `tests/TestCase.php`). If all three pass, proceed; the Vue page is Task 7.

- [ ] **Step 6: Commit**

```bash
git add laravel/app/Http/Controllers/StyleGuideController.php laravel/routes/web.php laravel/tests/Feature/StyleGuideTest.php
git commit -m "ui(styleguide): admin-gated /style-guide route + controller"
```

---

## Task 7: `StyleGuide.vue` — the living reference page

**Files:**
- Create: `laravel/resources/js/Pages/StyleGuide.vue`
- Test: `laravel/resources/js/Pages/__tests__/StyleGuide.spec.js`

- [ ] **Step 1: Write the failing test**

```javascript
// laravel/resources/js/Pages/__tests__/StyleGuide.spec.js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// AppLayout pulls in Inertia + the whole nav shell; stub it down to its default slot.
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { name: 'AppLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));

import StyleGuide from '@/Pages/StyleGuide.vue';

const w = () => mount(StyleGuide);

describe('StyleGuide page', () => {
    it('renders all three FlowAlert tones so both themes can be eyeballed', () => {
        const page = w();
        expect(page.findAll('[role="note"]')).toHaveLength(2);   // info + warning
        expect(page.findAll('[role="status"]')).toHaveLength(1); // critical (polite live region)
    });

    it('documents every status-rail tone', () => {
        const page = w();
        for (const tone of ['rail-neutral', 'rail-info', 'rail-success', 'rail-warning', 'rail-danger']) {
            expect(page.find(`.${tone}`).exists()).toBe(true);
        }
    });

    it('shows the AA-safe text tokens, not the raw status-500 colours', () => {
        const html = w().html();
        expect(html).toContain('text-on-warning');
        expect(html).not.toContain('text-warning-500');
    });

    it('renders a KPI numeral using the display face and tabular numerals', () => {
        const kpi = w().find('[data-testid="kpi-numeral"]');
        expect(kpi.exists()).toBe(true);
        expect(kpi.classes()).toContain('font-display');
        expect(kpi.classes()).toContain('nums');
    });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
npx vitest run resources/js/Pages/__tests__/StyleGuide.spec.js
```
Expected: FAIL — `Failed to resolve import "@/Pages/StyleGuide.vue"`.

- [ ] **Step 3: Write the page**

```vue
<!-- laravel/resources/js/Pages/StyleGuide.vue -->
<script setup>
/**
 * Wave 0 — the living design-system reference for the "Census Board" signature.
 *
 * This page is DOCUMENTATION THAT CANNOT GO STALE: it imports the real primitives rather than
 * re-implementing them, so a regression here is a regression everywhere. Flip the theme toggle in
 * the header to review light and dark parity.
 *
 * Spec: docs/superpowers/specs/2026-07-08-ehc-ui-ux-delta-design.md §2.
 */
import AppLayout from '@/Layouts/AppLayout.vue';
import FlowAlert from '@/Components/FlowAlert.vue';
import EhcLogo from '@/Components/EhcLogo.vue';

const card = 'overflow-hidden rounded-2xl bg-card ring-1 ring-line';

const BRAND = ['brand-200', 'brand-400', 'brand-500', 'brand-700', 'brand-800', 'brand-950'];
const STATUS = [
    { rail: 'rail-neutral', tint: 'bg-ink-100', text: 'text-ink-600', label: 'Neutral' },
    { rail: 'rail-info', tint: 'bg-tint-info', text: 'text-on-info', label: 'Info' },
    { rail: 'rail-success', tint: 'bg-tint-success', text: 'text-on-success', label: 'Stable' },
    { rail: 'rail-warning', tint: 'bg-tint-warning', text: 'text-on-warning', label: 'Boarding' },
    { rail: 'rail-danger', tint: 'bg-tint-danger', text: 'text-on-danger', label: 'Critical' },
];
</script>

<template>
    <AppLayout title="Style guide">
        <div class="space-y-8">
            <FlowAlert tone="info" title="This page is the source of truth for the UI">
                Every primitive below is imported from the real component — if it looks wrong here, it is
                wrong everywhere. Toggle the theme in the header to check light/dark parity.
            </FlowAlert>

            <!-- Brand + logo -->
            <section :class="[card, 'p-5']">
                <h2 class="mb-4 text-lg font-semibold text-ink-900">Brand</h2>
                <div class="flex items-center gap-6">
                    <EhcLogo class="h-12 w-12" />
                    <EhcLogo class="h-12 w-12 text-navy-900" mono />
                    <p class="text-sm text-ink-500">Colour mark · monochrome mark (dark chrome + print)</p>
                </div>
                <div class="mt-5 flex flex-wrap gap-2">
                    <div v-for="c in BRAND" :key="c" class="w-24">
                        <div :class="['h-12 rounded-lg ring-1 ring-line', `bg-${c}`]" />
                        <p class="mt-1 text-[11px] text-ink-500">{{ c }}</p>
                    </div>
                </div>
                <p class="mt-4 text-sm text-ink-500">
                    <strong class="text-ink-900">brand-500 is 3.29:1 on white</strong> — fills, borders and large
                    text only. Body text and links use <code>brand-700</code> (5.69:1) or <code>brand-800</code>
                    (8.42:1). See <code>public/images/BRAND_README.md</code>.
                </p>
            </section>

            <!-- The fingerprint: status rails -->
            <section :class="[card, 'p-5']">
                <h2 class="mb-1 text-lg font-semibold text-ink-900">Status rail — the fingerprint</h2>
                <p class="mb-4 text-sm text-ink-500">
                    A 3px logical inset rail (<code>border-inline-start</code>, so RTL flips it for free).
                    Every row is a ticket. Status is <em>never</em> colour alone — always rail + text.
                </p>
                <div class="space-y-2">
                    <div
                        v-for="s in STATUS"
                        :key="s.rail"
                        :class="['status-rail transition-row row-pad flex items-center gap-3 rounded-e-xl px-3', s.rail, s.tint]"
                    >
                        <span :class="['text-sm font-semibold', s.text]">{{ s.label }}</span>
                        <span class="nums text-xs text-ink-500">MRN 3166901 · 64y · M · Ward 4B</span>
                    </div>
                </div>
            </section>

            <!-- Callouts -->
            <section :class="[card, 'p-5']">
                <h2 class="mb-1 text-lg font-semibold text-ink-900">Flow alerts</h2>
                <p class="mb-4 text-sm text-ink-500">
                    Three redundant signals — hidden screen-reader prefix, icon, text — so colour is never
                    load-bearing. Cap at two per view. Only <code>critical</code> announces (role=alert).
                </p>
                <div class="space-y-3">
                    <FlowAlert tone="warning" title="Two discharges are overdue">
                        Both patients are medically discharged and still occupying a ward bed.
                    </FlowAlert>
                    <FlowAlert tone="critical" title="Bed occupancy has passed the critical threshold" />
                </div>
            </section>

            <!-- Numerals + controls -->
            <section :class="[card, 'p-5']">
                <h2 class="mb-4 text-lg font-semibold text-ink-900">Numerals &amp; controls</h2>
                <div class="flex flex-wrap items-end gap-10">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">Current census</p>
                        <p data-testid="kpi-numeral" class="font-display nums text-4xl font-extrabold text-ink-900">
                            128
                        </p>
                        <div class="mt-1 h-px w-16 bg-hairline" />
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">
                            Primary
                        </button>
                        <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-brand-800 ring-1 ring-line">
                            Secondary
                        </button>
                        <button type="button" class="rounded-xl bg-danger-600 px-4 py-2 text-sm font-semibold text-white">
                            Destructive
                        </button>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-ink-700">MRN</span>
                        <input class="field nums" inputmode="numeric" placeholder="3166901" />
                    </label>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
```

> **Note on `bg-${c}`:** Tailwind cannot statically see dynamically-assembled class names. The six
> brand swatches are therefore also listed literally in the comment below so `@source` scanning
> emits them. Add this line at the end of the `<script setup>` block:
>
> ```javascript
> // Tailwind @source safelist (dynamic `bg-${c}` above is invisible to the scanner):
> // bg-brand-200 bg-brand-400 bg-brand-500 bg-brand-700 bg-brand-800 bg-brand-950
> ```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
npx vitest run resources/js/Pages/__tests__/StyleGuide.spec.js
```
Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add laravel/resources/js/Pages/StyleGuide.vue laravel/resources/js/Pages/__tests__/StyleGuide.spec.js
git commit -m "ui(styleguide): living Census Board reference page"
```

---

## Task 8: Full verification + ship the rebuilt assets

- [ ] **Step 1: Run the whole front-end suite**

Run (from `laravel/`):
```bash
npx vitest run
```
Expected: PASS — the pre-existing specs **plus** the three new files (FlowAlert 7, EhcLogo 5, StyleGuide 4 = 16 new tests). No failures.

- [ ] **Step 2: Run the whole PHP suite, two passes**

Run:
```bash
"C:/wamp64/bin/php/php8.5.0/php.exe" artisan test --exclude-group pdf
"C:/wamp64/bin/php/php8.5.0/php.exe" artisan test --group pdf
```
Expected: both passes green. Pass A gains the 3 new `StyleGuideTest` tests; Pass B is unchanged.
If Pass A dies with `Premature end of PHP process`, you ran it **without** `--exclude-group pdf` —
re-read the environment notes.

- [ ] **Step 3: Rebuild and commit the shipped assets**

Run:
```bash
npm run build
```
Expected: build succeeds. `public/build/assets/*.css` now contains `status-rail`, `rail-danger`,
`bg-tint-warning`, `text-on-warning`, and the six `bg-brand-*` swatches.

Verify:
```bash
for c in status-rail rail-danger bg-tint-warning text-on-warning bg-brand-950; do
  grep -q "$c" public/build/assets/*.css && echo "OK  $c" || echo "MISSING  $c"
done
```
Expected: five `OK` lines. A `MISSING` means Tailwind never saw the class — check the `@source`
safelist comment in `StyleGuide.vue`.

- [ ] **Step 4: Confirm zero behaviour change on existing pages**

Run:
```bash
git diff --name-only HEAD~7 -- laravel/app laravel/routes | grep -v StyleGuide
```
Expected: **no output** — Wave 0 touched no existing controller or route besides adding the
style-guide entry. (`laravel/resources/css/app.css` is additive; no existing selector was altered.)

- [ ] **Step 5: Commit the built assets**

```bash
git add laravel/public/build
git commit -m "build: rebuild assets for Wave 0 tokens + style guide"
```

- [ ] **Step 6: Capture before/after evidence**

Start the dev server and screenshot the style guide in both themes, saving to
`docs/renovation/wave0-styleguide-light.png` and `docs/renovation/wave0-styleguide-dark.png`
(use the Claude_Preview tooling, or a manual browser capture at `/style-guide`).

```bash
git add docs/renovation
git commit -m "docs(renovation): Wave 0 style-guide screenshots (light + dark)"
```

---

## Wave 0 acceptance criteria (from the spec §3 W0)

- [ ] A single token source of truth — no new hard-coded colour outside `app.css` and the logo SVG.
- [ ] `/style-guide` renders every primitive in **both** themes; admin-gated (403 for non-admins).
- [ ] Contrast recorded in `public/images/BRAND_README.md`, regenerable via `node scripts/contrast.mjs`.
- [ ] Every status text uses an `on-*` token; the raw `*-500` colours never appear as text.
- [ ] Status is never colour alone — `FlowAlert` proves the icon + hidden-prefix + text pattern.
- [ ] All existing pages still load; the whole Vitest + two-pass PHPUnit suite is green.
- [ ] `public/build/` rebuilt and committed.
- [ ] No new runtime dependency; no new inline `<script>`/`<style>` (CSP-readiness, spec §5 P1).

---

## Self-review notes (run against the spec before starting)

- **Spec coverage.** §3 W0 has four deliverables: brand asset + `BRAND_README.md` (Task 5),
  signature tokens (Task 2), `FlowAlert` callouts (Task 3), `/style-guide` (Tasks 6–7). The
  `navy-*`-stays-dark-teal and `APP_NAME`-unchanged decisions are honoured by *not* touching them.
  The mono logo variant (spec §3 W0, "white/mono variant for dark headers") is Task 4.
- **Contrast rule.** The spec says *"text/links fall back to `brand-800` where `brand-500` fails
  4.5:1"*. Task 1 proves `brand-500` = 3.29:1, so the rule is live, and Task 5 records it.
- **Deviation from spec, deliberate:** `/style-guide` is `admin`-gated rather than merely `auth`-gated.
  `admin ⊂ auth`, so this is strictly stronger and does not violate the spec.
- **Discovered scope, added:** the spec did not anticipate that the *existing* `warning-500` /
  `info-500` status colours fail AA as text. Task 2 adds the `tint-*` / `on-*` token pairs to fix
  it. This is required by the spec's own WCAG 2.2 AA acceptance bar.
- **Naming consistency:** `status-rail` + `rail-{neutral,info,success,warning,danger}` are used
  identically in Task 2 (CSS), Task 3 (`FlowAlert`), and Task 7 (`StyleGuide`). `tint-*`/`on-*`
  likewise. Rail tone names deliberately match the colour tokens they dereference
  (`success`/`danger`), not alert vocabulary (`ok`/`critical`) — `FlowAlert`'s `critical` *prop*
  maps to `rail-danger`. `--row-py` is NOT declared in `:root`; `row-pad`'s `var(--row-py, 0.5rem)`
  fallback is the default, and `.density-*` (Task 2 Step 4) overrides it.
- **Amended after the Task 2 code review (2026-07-08):** `--shadow-overlay` now indirects through
  `--overlay-ink` at the colour slot, because Tailwind v4 *inlines* `--shadow-*` at build time and a
  `.dark` override of the shadow token itself is inert. `@property --rail-color { inherits: false }`
  was added so a toned row cannot silently re-tone nested rails. Both were proven by compiling the
  real stylesheet through Tailwind's own API. The identical inert-dark-shadow bug in the pre-existing
  `--shadow-card`/`--shadow-card-lg` is documented in-file but deliberately NOT fixed here — **not**
  because it is hard (`--shadow-card: var(--card-shadow)` + `:root`/`.dark` raws is ~4 lines; Tailwind
  happily indirects the colour slot, the geometry, *or* the whole value), but because it is a **visible
  dark-mode change to every card** and wants eyeballs, not a mechanical edit inside a token commit.
  Spun off separately. (An earlier draft of this note wrongly claimed the fix required unifying
  per-theme geometry — corrected after the reviewer disproved it by compiling three shapes.)
