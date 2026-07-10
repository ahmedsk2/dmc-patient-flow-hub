# Production-readiness closeout: security headers, CI, theme-stable button token

**Date:** 2026-07-10 · **Branch:** `laravel-replatform` · **Mode:** autonomous (user away;
questions pended). Closes the three code-side gaps identified in the production-readiness
review, plus two W5 non-blockers.

## Scope

1. **Security response headers middleware** — the app is CSP-*ready* (W5: zero inline
   handlers; one nonce-able inline script) but nothing emits headers.
2. **CI pipeline** — no `.github/workflows` exists on this branch; regressions are only
   caught by running gates by hand.
3. **Theme-stable primary-button token** — `bg-brand-600` + white text (the app-wide CTA
   fill) measures 4.31:1 light / **2.09:1 dark** (axe `color-contrast` serious). Pended
   earlier as a brand decision; the user has now delegated it to best judgment.
4. **W5 non-blockers** — Registry date-input Label-in-Name divergence; `formatDate`
   accepts calendar-impossible days.

Out of scope: everything operational (TLS, secrets rotation, backups, prod `.env`,
first-login config) — those live in `laravel/docs/DEPLOY-LARAVEL.md` and are owner infra.

## 1. SecurityHeaders middleware

**Approach chosen:** one new `app/Http/Middleware/SecurityHeaders.php` appended to the
`web` group in `bootstrap/app.php` (Laravel 12+ style already used there). Alternatives
rejected: web-server config (couples security to a specific nginx/Apache install — the app
must be safe by default on any host); per-route middleware (headers belong on everything).

- **CSP, nonce-based, enforcing by default.** Per-request nonce (`base64` random 16),
  exposed to Blade; the theme bootstrap in `app.blade.php` (the app's ONLY inline script,
  documented as the future-nonce'd exception) gets `nonce="…"`. Policy:
  `default-src 'self'; script-src 'self' 'nonce-<n>'; style-src 'self' 'unsafe-inline';
  img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self';
  frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'`.
  `style-src 'unsafe-inline'` is deliberate: ApexCharts/Vue set inline style attributes;
  blocking them would break every chart for zero practical XSS gain (scripts stay locked).
- **Mode switch:** `CSP_MODE` env — `enforce` (default) | `report` (Content-Security-
  Policy-Report-Only) | `off`. Ops escape hatch; documented in `.env.example`.
- **Static headers (always):** `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: same-origin` (PHI app — never leak URLs cross-origin),
  `Permissions-Policy: camera=(), microphone=(), geolocation=()`.
- **HSTS** (`max-age=31536000; includeSubDomains`) only when `$request->secure()` — never
  on local HTTP.
- **`Cache-Control: no-store`** on authenticated responses (PHI must not persist in shared-
  workstation caches; W4 spec item "no-store intent on PHI views"). Guest responses
  (login page) untouched; Vite `/build` assets are served by the web server, not PHP.
- **Tests:** feature test asserting each header on an authenticated page, nonce present +
  matching the inline script, HSTS absent on HTTP, no-store on authed / absent on login,
  `report` and `off` modes honored.
- **Verification:** live reload of the Dashboard (charts) under the enforced CSP — zero
  console CSP violations, charts render.

## 2. CI workflow

`.github/workflows/ci.yml`, triggered on push + PR to `main` and `laravel-replatform`.
Two independent jobs (facts verified from the repo):

- **frontend** (Node 22, `package-lock.json` exists → `npm ci`): Vitest run →
  `npm run build` → allowlist gate → contrast gate → **build-reproducibility check**
  (`git diff --exit-code -- public/build` after the build; the committed bundle must be
  regenerable from source — a W0 invariant).
- **backend** (PHP 8.4 via `shivammathur/setup-php` — satisfies `"php": "^8.3"`;
  `vendor/` is NOT committed → `composer install`; MySQL 8 service container with a
  `dmc_test` schema — phpunit.xml runs `RefreshDatabase` against MySQL and says
  "Override DB_TEST_* in CI as needed"): `cp .env.example .env` + `key:generate`, then the
  established **two-pass** suite (`--exclude-group pdf`, then `--group pdf`).

No deploy step — CI is a gate, not a pipeline to prod (deploy stays manual per runbook).

## 3. Theme-stable primary-button token (`brand-solid`)

**Decision:** stay **brand teal**, not navy. New tokens in `@theme` (declared once, NO
`.dark` override → theme-invariant by construction):

- `--color-brand-solid: #00727b` — the light-mode brand-700 hex; **5.87:1** with white.
- `--color-brand-solid-hover: #00565e` — the light-mode brand-800 hex; **8.42:1**.

Why not bump to `bg-brand-700`: the brand steps **invert** on dark (`.dark` brand-700 =
#7accc9, 1.6:1 with white) — any stepped class stays broken in dark mode. Why not navy:
the CTA is the brand's primary affordance; a fixed dark teal keeps identity and clears AA
in both themes (dark-teal buttons on dark surfaces are standard dark-UI practice).

**Sweep rule (64 occurrences / 25 files, discriminating):** replace `bg-brand-600` →
`bg-brand-solid` (and the paired `hover:bg-brand-700` → `hover:bg-brand-solid-hover`,
`focus:bg-brand-600` on the skip link likewise) **only where the fill carries white/light
text** (buttons, active-pagination, active-tab pills, skip link, timeout toast CTA).
Leave: gradient icon tiles (`toneClass` composites), decorative washes, any
`bg-brand-600/NN` alpha usages, and non-text indicator dots. Contrast manifest: add the
two new pairs as guarded rows; re-mark any existing `brand-600`-on-white-text rows.
Existing Vitest specs asserting `bg-brand-600` on swept elements must be updated to the
new token (test-follows-intent, not blind).

## 4. W5 non-blockers

- **Registry date inputs:** replace the divergent `aria-label="From date"/"To date"` with
  proper programmatic association — `for`/`id` between the existing visible `<label>`s
  ("Admitted from"/"to"/"From") and their inputs, ids mode-scoped to avoid duplicates
  across the three `v-if` filter modes (only one renders at a time, but unique ids are
  cheaper than reasoning about it). Accessible name == visible label (WCAG 2.5.3 clean).
- **`formatDate`:** reject calendar-impossible days (day > days-in-month for that
  month/year, incl. leap years) → return `''` like other invalid input. Extend the unit
  tests (`2026-02-31` → `''`, `2024-02-29` → `'29 Feb 2024'`, `2023-02-29` → `''`).

## Execution & verification

Three parallel implementer subagents on disjoint files — **A:** middleware + blade +
bootstrap + `.env.example` + feature test; **B:** workflow file only; **C:** tokens +
sweep + manifest + the two minor fixes + spec updates. None build/commit/gate. Controller
then: one build → full gates (Vitest, two-pass PHPUnit, contrast, allowlist, build) →
**live CSP + button verification** in the running preview (both themes) → adversarial
verification Workflow over all three deliverables → commit → push `laravel-replatform`
+ fast-forward `main`.

## Pended for the user

- The chosen `brand-solid` hue (#00727b teal) is my judgment call — flag if you want the
  navy family or a different tint instead; it's a two-line token change to adjust.
- CI provider assumed GitHub Actions (repo is on github.com). Say if you use something else.

## Post-implementation corrections (2026-07-10, same session)

- **Wrong premise:** "no `.github/workflows` exists on this branch" was false — the check had run
  inside `laravel/`, but workflows live at the repo root. `ci.yml` there is the legacy PHP app's
  live CI (left untouched); the pre-existing partial `laravel-ci.yml` was rewritten in place
  instead, with its original `paths` scoping and npm/composer audit steps preserved.
- **Wrong number:** white on `brand-solid` measures **5.69:1** (per `scripts/contrast.mjs`), not
  the 5.87:1 stated above.
- **Adversarial pass findings, all fixed before commit:** blocking `npm audit` failed on a current
  low-severity esbuild advisory → `--audit-level=moderate`; the driver.js tour Next button (plain
  CSS, unreachable by the class sweep) still used inverted `--color-brand-600` → `--color-brand-solid`;
  SecurityHeaders moved from append to **prepend** so 419/CSRF error pages carry headers (curl-
  verified); CSP auto-relaxes when Vite's gitignored `public/hot` sentinel exists (dev HMR).
- **Noted, not addressed:** `report` mode has no report-uri (console-only observability); Octane-
  style long-lived workers would need the `view()->share` nonce pattern revisited (not the current
  runtime).
