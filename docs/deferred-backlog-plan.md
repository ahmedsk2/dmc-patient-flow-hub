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
- [x] **Phase 1 — Statistics engine (grouped SQL).** Substantially complete: time1 collapsed; kpis
  verified already-grouped; charts1 collapsed + the readmission/quarterly **crash fixed**; charts.php
  two latent bugs fixed; a4/a4-monthly readmission N+1 collapsed. Every change proven against the
  golden master. Remaining a4 sub-items (per-day census collapse, caching, merge-twins, un-hide KPI)
  are **documented deferrals** with rationale below — not silent gaps.
- [ ] ~~Phase 1 (original framing)~~ — Correctness (sargability +
  cross-year) was already fixed in earlier batches; remaining work is PERFORMANCE: collapse the
  per-period PHP loops into single `GROUP BY` queries, then add a cached-aggregates layer for the
  heavy A4 reports (a4.php ≈ 1,800 q/load, a4-monthly ≈ 3,500). Validate each rewrite byte-for-byte
  vs `before`. Also fold in the "merge A4 twins" (a4.php/a4-monthly near-duplicates + the hidden
  draft KPI page).
  - [x] time1.php (period overview) — POC for the grouped-SQL pattern. 124/48/16 queries → 4.
    Proven byte-identical (`time1__*` match `before`). Commit `5679057`.
  - [ ] kpis.php — **already grouped** in an earlier batch (`fetchCounts()` = 1 `GROUP BY` query
    per metric, sargable `BETWEEN` filter). Verify-only, no rewrite needed.
  - [x] charts1.php — per-consultant KPI charts. LOS (3 intervals): N per-consultant fetches → 1
    grouped fetch + PHP bucketing (averaging unchanged; day-diffs are exact integers in Asia/Riyadh
    so order-independent). Admission (3 intervals): 4N queries → 4 `GROUP BY consultant_id` counts.
    Readmission/quarterly: the N×(1+M) loop that crashed (warning storm) → fetch-once + guarded
    subquery + count (mirrors the monthly path) — same window/subquery/attribution. 8 deterministic
    cases byte-identical vs `before`; the (formerly 11 MB, non-deterministic) quarterly now a clean
    5.5 KB page whose chartdata matches two independent ground-truth recomputations (loop + set-based).
  - [x] charts.php — per-consultant detail view; already mostly grouped (`GROUP BY trans_discharge`
    / `DISTO`, single-query LOS table). No N+1 worth collapsing. Fixed two latent bugs instead:
    (1) the discharge-destination doughnut's daily/monthly branch built `$all_counts` + a 5-label set
    but the `<script>` reads `$all_counts1` / 6 labels → monthly rendered a blank doughnut + an
    "Undefined variable" warning; mirrored the quarterly branch. (2) The LOS/numbers readmission loop
    (daily/monthly **and** quarterly) dereferenced a null row when no readmission was found → 24
    "array offset on null" warnings/quarter; added a `$recentadmission &&` guard (count was already
    unaffected, so zero number change).
  - [x] a4.php / a4-monthly.php — **fully collapsed**. (1) the 72h-readmission N+1 → one
    `EXISTS + COUNT(*) GROUP BY month` query; (2) the **per-day census** (bed-days + weekend) → set-based
    per-month queries; (3) a4-monthly's **daily-chart** per-day loop → 5 `GROUP BY`-day queries.
    **Measured: a4.php ~1800→~170 SELECTs (~91%); a4-monthly ~3500→~445 (~87%).** Byte-identical on the
    demo golden master (`a4*__2023/2024`).
    - The census **validation gap is now CLOSED**: the maintainer supplied a current full production
      export (35,808 admissions through 2026-06-07, 151 active). Imported to an isolated local DB and
      proved the set-based bed-days **and** weekend formulas equal the original per-day loop for **all
      54 months including the live partial month** (June 2026: 886==886), mismatches=0 — so the
      `<= today` boundary is exercised on real data. (`patient_diagnosis` lossless was also re-proven
      on the full 35.8k dataset; surfaced + handled a JSON-`null` diagnosis edge case the demo lacked.)
    - One original quirk preserved byte-for-byte: a4-monthly's "Weekend Discharge" zero-guard used the
      per-day loop's leftover `$dischargedpcount` (last day's count); reproduced exactly.
    - *Per-month metric COUNTs/LOS fetches* (5+3 per month) — still loop-per-month; safely collapsible
      (time1-style) but now low marginal value (~a few dozen queries left).
    - *Cached-aggregates layer* — **not done on purpose**: a stale cached report in a live clinical
      system is a safety hazard, and these MyISAM tables have no reliable change-version (no
      updated_at/triggers) for safe invalidation. Live queries are the safe default.
    - *Merge "A4 twins" + un-hide the draft KPI page* — structural/product changes (un-hiding a
      `display:none` draft page alters the printed clinical report); defer to a product decision.

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
- [x] **Phase 2 — MFA (TOTP, self-contained RFC-6238).** Rollout chosen: **opt-in / enforce-later**
  (no clinician lockout at deploy). Delivered + validated:
  - `mfa.php` — TOTP (HMAC-SHA1, 6-digit/30s), Base32, drift-window verify (constant-time),
    otpauth URI, AES-256-GCM at-rest encryption of the secret (key = config `MFA_KEY`), single-use
    bcrypt recovery codes. **`tools/mfa_test.php`: 30/30, incl. the published RFC-6238 vectors** →
    interoperable with Google Authenticator / Authy / MS Authenticator. (commit 317415e)
  - `migrations/06-mfa.sql` — additive nullable `members.mfa_secret/mfa_recovery_codes/
    mfa_enrolled_at` + `settings.mfa_enforcement` (default 0). No existing login changes.
  - `mfa-setup.php` (enroll + code-gated disable), `mfa-verify.php` (second factor — the real
    session is established only after a valid TOTP/recovery code), `index.php` additive hook,
    sidebar link. **Validated end-to-end**: enroll → logout → password-login diverts to 2FA
    (password alone does NOT log in) → wrong code rejected → correct TOTP → dashboard. (commit 4bb86a6)
  - `mfa-admin-reset.php` + control.php button — admin lockout recovery (admin-only, CSRF, audited).
    Validated (resets secret + writes `mfa.admin_reset` audit row). (commit 75e5be9)
  - **Per-role enforcement lever DONE** (`settings.mfa_enforcement`: 0=off, 1=admins, 2=all). Control-
    panel dropdown + a loop-free gate in sidebar.php (required-but-not-enrolled → mfa-setup); default 0
    so it's a no-op until switched on. Validated E2E (policy 0 normal; 1/2 force a non-enrolled admin
    to setup; mfa-setup stays reachable). (commit 563eb38)
  - **QR code DONE** — `vendor/qrcodejs/` (davidshimjs, MIT, single 20KB file, user-approved) renders
    the QR client-side on mfa-setup.php from the otpauth URI; Base32 manual key kept as fallback.
    Nothing new leaves the server (secret already on the page). (commit aa081c6)
  - **Deploy steps (prod):** run `migrations/06-mfa.sql`; set a long random `MFA_KEY` in the prod
    (git-ignored) `config.local.php` — see `config.local.sample.php`. Keep `MFA_KEY` stable
    (changing it forces re-enrollment).
- [x] **Phase 3 — Server-side PDF.** FPDF 1.86 vendored under `vendor/fpdf/` (single-file, no deps,
  permissive licence; **user-approved** external code — runtime files only). `statistics/report_data.php`
  (`dmc_yearly_report_data()`) computes the yearly aggregates via grouped queries, metric definitions
  mirroring a4.php; **`tools/report_data_validate.php` proves 20/20 metrics identical to a4.php's JSON
  for 2023 & 2024**. `pdf-report.php?y=YYYY` (admin-only; unauth→302) renders an A4-landscape PDF
  (monthly KPI table + totals, per-consultant LOS, discharge destinations); a4.php untouched. A "PDF"
  button sits beside Yearly/Monthly in allstat.php. (commit 792eb57)
  - Deferred: bed-days/census column in the PDF (the one a4 metric not yet collapsed) and embedding
    the Chart.js graphs as images (PDF is tabular).
- [~] **Phase 4 — Layering + test suite (strangler-fig start).**
  - [x] **Self-contained test runner** (`tests/run.php` + `tests/README.md`): one command aggregates
    the validated check scripts (TOTP/RFC-6238 unit + report_data==a4 integration) via PHP_BINARY
    subprocesses; integration checks auto-skip when the dev server is down so `--unit` is CI-safe.
    (commit 8d0b6b3)
  - [ ] **Remaining (multi-session):** PSR-4 autoloader + a `src/` service/repository layer behind
    interfaces; `statistics/report_data.php` is the natural first slice to become a `src/` class with
    direct unit tests. Real PHPUnit swappable in where Composer exists.
- [~] **Phase 5 — Deeper schema normalization (validated, additive).**
  - [x] **`patient_diagnosis` join table** (vs the `admissiondiagnosis` JSON). Additive, derived,
    indexed; JSON stays authoritative. `JSON_TABLE` backfill (16,067 rows / 14,827 admissions),
    **lossless round-trip validated** (`tools/patient_diagnosis_validate.php`, in `tests/run.php`).
    Migration 07. (commit 94c5bd1)
  - [ ] **Canonical `patients` entity** (vs row-per-admission). Additive creation from distinct MRNs
    is feasible, but the payoff only lands if the app is rewired to use it (risky) — and MRN is dirty
    (~51 known-bad, unindexed mediumtext). Recommend doing it *after* the Phase-4 `src/` layer and a
    prod MRN clean-up. Deferred (not started).
  - [x] **Specialty "dedup" — investigated, CONTRAINDICATED.** The data shows `speciality` (a
    consultant's *own* specialty — Hospitalist, Infectious Disease…, used by `members.specialty_id`)
    and `other_specialities` (referral *target services* — ICU, Cardiology, GIT…) are **distinct
    domains, not duplicates** (only the junk row "test" overlaps). Merging them would conflate two
    concepts and is the wrong move. The real residue is a handful of junk rows ("resr", "345"×2,
    "test"); deleting them is a **maintainer data-cleanup** (some may be referenced by members →
    orphaning risk), not an automated migration. No code change made — documented decision.

## Validation log

| Step | before-sha set | after-sha set | result |
|---|---|---|---|
| (Phase 0) baseline `before` | 21 cases captured | — | n/a |
| (Phase 0.1) harness self-test | `before` | `selftest` (same code) | ✓ 20/20 identical, 1 skip |
| time1.php grouped-SQL rewrite | `before` | `after` | ✓ `time1__*` identical (commit 5679057) |
| charts1.php LOS + admission rewrite | `before` | `after` | ✓ 20/20 identical (1 skip) |
| charts1.php readmission/quarterly fix | ground-truth | endpoint chartdata | ✓ matches loop **and** set-based (sum 49) |
| charts.php two bug fixes | `before` | `after2` | ✓ 14/14 untouched identical; 6 charts diffs = intended only* |
| a4 + a4-monthly readmission collapse | `before` (25, incl. A4) | `after`/`after2` | ✓ 25/25 IDENTICAL (commit 2fd8d40) |
| MFA TOTP core | RFC-6238 Appendix-B vectors | mfa.php | ✓ 30/30 (`tools/mfa_test.php`) |
| MFA enroll → 2FA login | live dev server | end-to-end | ✓ password-alone blocked; TOTP → dashboard |
| MFA admin reset | seeded-enrolled member | endpoint | ✓ secret cleared + audit row |
| PDF report_data vs a4.php | a4.php JSON (2023+2024) | report_data.php | ✓ 20/20 metrics identical |
| pdf-report.php output | — | live endpoint | ✓ 200 application/pdf, valid %PDF, admin-gated |
| patient_diagnosis join table | `admissiondiagnosis` JSON | join table | ✓ 16,067 rows lossless round-trip (commit 94c5bd1) |
| patient_diagnosis on PROD | full 2026 export (35,808) | join table | ✓ lossless; JSON-null edge case handled (commit 8b3d561) |
| a4 census collapse — formula | per-day loop (prod, 54 mo) | set-based | ✓ bed-days + weekend, 0 mismatches incl. partial June 2026 |
| a4 + a4-monthly census collapse | `before` (demo, 25) | `after` | ✓ a4/a4monthly byte-identical (commits 920cc35, e779e4f) |
| src/ YearlyReport extraction | report_data == a4 (through class) | after refactor | ✓ 20/20 still identical (commit 097d78e) |
| MFA enforcement gate | — | live E2E | ✓ 0=normal, 1/2 force setup, no loop, save persists (commit 563eb38) |

*charts.php diffs verified: after stripping warning markers, all 3 `charts__c*__quarterly` are
byte-identical (zero number change); the 3 `charts__c*__monthly` differ only in the label array
(`+"Transfer"`) and `all_counts1` (`null`→`[0,0,0,0,0,0]`). The all-zeros confirmed genuine via a
direct query (June-2024 has no destination-discharge rows for those consultants).
