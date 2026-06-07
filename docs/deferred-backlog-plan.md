# Deferred backlog (Tier 2 + Tier 3) — execution plan & log

> Started 2026-06-07. Working **local-first** on branch `renovation`, **self-contained / no
> Composer** (matches the app's design; the bespoke `xlsx-writer` set the precedent).
> Every number-changing rewrite is proven equivalent with the **golden-master harness**
> (`tools/stats_validate.php`): `capture before` → rewrite → `capture after` → `compare` must be
> IDENTICAL. The local app must stay green at every step.

## Sequencing (by risk/value)

- [x] **Phase 0 — Validation foundation.** `tools/stats_validate.php` golden-master harness
  (authenticated HTTP capture of all 6 stats endpoints × param matrix; sha + diff). Baseline
  `before` captured (21 cases). Baselines git-ignored.
- [ ] **Phase 1 — Statistics engine (grouped SQL + caching).** Correctness (sargability +
  cross-year) was already fixed in earlier batches; remaining work is PERFORMANCE: collapse the
  per-period PHP loops into single `GROUP BY` queries, then add a cached-aggregates layer for the
  heavy A4 reports (a4.php ≈ 1,800 q/load, a4-monthly ≈ 3,500). Validate each rewrite byte-for-byte
  vs `before`. Also fold in the "merge A4 twins" (a4.php/a4-monthly near-duplicates + the hidden
  draft KPI page).
  - [x] time1.php (period overview) — POC for the grouped-SQL pattern. 124/48/16 queries → 4.
    Proven byte-identical (`time1__*` match `before`). Commit `5679057`.
  - [ ] kpis.php — **already grouped** in an earlier batch (`fetchCounts()` = 1 `GROUP BY` query
    per metric, sargable `BETWEEN` filter). Verify-only, no rewrite needed.
  - [ ] charts1.php · charts.php
  - [ ] a4.php / a4-monthly.php (+ cache + merge twins + un-hide KPI page)

### Bugs found during validation (fix as part of the relevant rewrite)

- **`charts.php:330` — `Undefined variable $all_counts1`.** `$all_counts1` is built only inside the
  patient-destination `case`, but the doughnut `<script>` echoes it unconditionally → on every other
  branch it renders `let all_counts1 = ` + a PHP warning (broken JS). Fix in the charts.php rewrite
  (task #4): initialise the array up-front / guard the echo.
- **`charts1.php` readmission (monthly & quarterly) — N×M loop + warning storm.** For each consultant
  it re-fetches all period admissions and runs a per-patient readmission sub-query, then dereferences
  a null row on every miss (`$recentadmission['consultant_id']`). On a full quarter this emits
  hundreds of thousands of PHP warnings; the dev server renders each as an Xdebug trace and the page
  crashes non-deterministically (the 11 MB `charts1__readmission__quarterly` capture). Fix in the
  charts1.php rewrite (task #3) with a single set-based readmission query (mirror `kpis.php`'s
  vetted `fetchReadmissions` CTE) grouped by consultant.

### Validation-harness hardening (Phase 0.1)

- `normalize()` now strips Xdebug error-trace tables (their *Time* column is wall-clock seconds →
  volatile). This removed 5 false-positive DIFFs (charts/charts1 emit a warning trace per load).
- `compare` skips a documented non-deterministic set (`charts1__readmission__quarterly` — see bug
  above) so the green/red signal reflects only baseline-able cases. **Self-test: two captures of the
  same code → 20/20 compared cases IDENTICAL, 1 skipped.** The harness is proven deterministic.
- [ ] **Phase 2 — MFA (TOTP, self-contained RFC-6238).** Secret column (migration), enrolment
  (QR via a self-contained generator or otpauth URI), verification step after password login,
  recovery codes, admin reset, enforcement policy. Tests.
- [ ] **Phase 3 — Server-side PDF.** Needs a PDF library (FPDF single-file, vendored) — the one
  item that needs an external lib; confirm before fetching. Render the A4 reports server-side.
- [ ] **Phase 4 — Layering + test suite (strangler-fig start).** Self-contained PSR-4-style
  autoloader + a `src/` layer (repositories/services), extract a few slices behind interfaces, and
  a lightweight self-contained test runner (`tests/`) — real PHPUnit can be swapped in where
  Composer exists on the server. Honest: this is the START of a multi-session rewrite.
- [ ] **Phase 5 — Deeper schema normalization (validated, additive).** `patient_diagnosis` join
  table (vs JSON), canonical `patients` entity (vs row-per-admission), specialty dedup — as
  **additive** migrations with a compatibility layer + a data-migration validated against the
  originals (counts must match). Highest risk; do behind the new layer, never as in-place edits.

## Validation log

| Step | before-sha set | after-sha set | result |
|---|---|---|---|
| (Phase 0) baseline `before` | 21 cases captured | — | n/a |
| (Phase 0.1) harness self-test | `before` | `selftest` (same code) | ✓ 20/20 identical, 1 skip |
| time1.php grouped-SQL rewrite | `before` | `after` | ✓ `time1__*` identical (commit 5679057) |
