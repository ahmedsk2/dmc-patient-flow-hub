# Project Overview — DMC Patient-Flow Hub

> A brief technical summary of this project for engineering review: what the system does, how it is
> built, which languages/frameworks/tools are used, and how quality was verified.

## 1. What the system is

A web application that runs the day-to-day **patient flow of a hospital Internal Medicine unit**
(~36,000 admission episodes, ~16,600 patients, 325 staff accounts):

- **Admissions** — admit a patient (demographics + ICD-10 diagnoses), queue of new admissions,
  auto-balancing assignment ("shuffle") of patients across on-service consultants.
- **Active patient board** — grouped by consultant; transfers (ward↔ICU), two-phase discharge
  (medical discharge → "discharged still in" → complete discharge), ICU discharge, long-term/TB flags,
  72-hour readmission badges, full patient edit.
- **Consultations** — referrals between services, sign-off workflow.
- **Registry** — historical search (3 modes: admissions / consultations / free-text diagnosis) with
  ~13 filters and Excel/CSV export.
- **Analytics** — live dashboard (census, bed occupancy, trends), statistics with selectable date
  range + daily/monthly/quarterly interval, printable A4 annual & monthly reports with server-side PDF.
- **Administration** — user roles & per-user capabilities, operational settings (bed capacity, LOS
  thresholds, MFA policy) with an append-only change history, reference-data management, audit log.

## 2. History and architecture (two implementations, one repo)

The project began as a **legacy procedural PHP application** (no framework, file-based routing,
~15k-row MyISAM schema) that had serious security defects. Work happened in two phases:

**Phase 1 — Renovation of the legacy app** (repo root, branch `renovation`)
The original codebase was kept but systematically hardened: central auth/authorization guard
(roles + capability flags + object-level ownership), CSRF tokens, prepared statements everywhere
(SQL-injection fixes), server-side validation, session/cookie hardening, bcrypt + TOTP two-factor,
secure password reset, audit logging, DB transactions, MyISAM→InnoDB + indexes + foreign keys,
secrets moved out of source, plus performance rewrites of the statistics queries (~3,500 queries/page
→ grouped SQL). An end-to-end HTTP test harness (`tools/e2e/`) verifies login, the full patient
lifecycle, the role×endpoint authorization matrix, and statistics values against independent SQL.

**Phase 2 — Re-platform on Laravel** (`laravel/`, branch `laravel-replatform`) — *the go-forward system*
A parallel rebuild on a modern stack with a **clean normalized schema** (canonical `patients` entity,
`admissions` episodes, `admission_diagnoses` rows instead of JSON arrays, real foreign keys and unique
constraints). A one-command importer (`php artisan legacy:import`) transforms the original database;
a reconciliation suite proves the two systems produce **identical numbers** on the same live data
(20/20 metric definitions match after source data cleanup).

## 3. Languages, frameworks, and tools

| Layer | Technology |
|---|---|
| Backend language | **PHP 8.3** |
| Framework (new app) | **Laravel 13** (Eloquent ORM, migrations, validation, middleware, password broker) |
| Frontend (new app) | **Vue 3** (`<script setup>`) via **Inertia.js 2** (SPA without a separate API), **Tailwind CSS v4**, **ApexCharts** (vue3-apexcharts) |
| Build tooling | **Vite**, npm; **Composer** for PHP packages |
| Database | **MySQL 8.4** (InnoDB, utf8mb4); separate read-only connection to the legacy DB for import |
| PDF / Excel | **dompdf** (`barryvdh/laravel-dompdf`) for server-side A4 reports; **OpenSpout** for .xlsx export (legacy app: bespoke dependency-free `xlsx-writer.php`) |
| Auth & security | bcrypt password hashing, self-contained **TOTP (RFC 6238)** two-factor with recovery codes + enforcement policy, CSRF, role/capability authorization middleware, append-only audit log, password expiry (3 months), tokened email password-reset |
| Legacy app (kept) | Procedural PHP 8.3 + mysqli (prepared statements), jQuery 3.7 / Bootstrap 5.3 / AdminLTE 3, Chart.js 4, Select2, PHPMailer |
| Testing | **PHPUnit 11** feature tests against a dedicated MySQL test DB (real SQL dialect; 22 tests / 121 assertions), plus the legacy `tools/e2e/` HTTP harness |
| CI | **GitHub Actions** (`laravel-ci.yml`): full test suite with a MySQL service on every push |
| VCS | Git / GitHub (private repo), branch-per-phase (`renovation`, `laravel-replatform`) |

## 4. Data model (new schema, simplified)

```
patients (1 row per MRN)
  └─ admissions (1 row per episode; FK patient_id, consultant_id→users, indexed dates)
       └─ admission_diagnoses (1 row per ICD-10 code; UNIQUE(admission_id, icd10_code))
consultations (FK consultant_id, entered_by; JSON indication → consultation_reasons)
users (roles 0/2/3/4/5 + capability flags + MFA columns)
settings (singleton; thresholds, licensed bed counts) + setting_changes (append-only history)
audit_logs (who did what, when, from which IP)
reference: icd10 (~72k), specialties, consultation_reasons, tb_diagnoses, countries
```

Patient state is derived: active = `discharge_date IS NULL`; ward vs ICU = `current_location`;
two-phase discharge via `medical_discharge_date` then `discharge_date`. Every clinical write is
validated server-side, wrapped in a transaction where multi-row, attributed to the **session** user,
and audit-logged. Data-quality rules are enforced at **both** app and database level (digits-only
unique MRN, consultant must exist, no duplicate diagnoses per admission).

## 5. Quality verification

- **Automated tests (CI-gated):** authorization matrix, data-integrity guards, the discharge state
  machine, registry filters, statistics bucketing, settings history.
- **Cross-system reconciliation** on a fresh production dump: entity counts and every clinical metric
  (census, mortality, LOS, 72-hour readmissions, per-year activity) computed independently on both
  systems — exact matches; the few deltas were traced to source-data defects, fixed at source, and
  guarded against recurrence.
- **Metrics dictionary:** every dashboard/statistics number has its exact formula and SQL documented
  ([`laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md`](laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md)).
- **Browser verification** of every page/chart during development (Inertia SSR props + rendered DOM).

## 6. Where to look (reviewer's map)

| Question | File |
|---|---|
| How is the DB structured; what does each button write? | `laravel/docs/DATABASE-AND-BEHAVIOR.md` |
| How is each statistic calculated? | `laravel/docs/DASHBOARD-AND-STATISTICS-METRICS.md` |
| Do the old and new systems agree on the data? | `laravel/docs/RECONCILIATION.md` |
| How do I run it locally? | `laravel/README-DMC.md` |
| How do I deploy it? | `laravel/docs/DEPLOY-LARAVEL.md` (new) / `DEPLOY.md` (legacy) |
| Business logic | `laravel/app/Http/Controllers/`, `laravel/app/Services/ShuffleService.php` |
| Schema | `laravel/database/migrations/` |
| Tests | `laravel/tests/Feature/`, legacy `tools/e2e/` |

## 7. Known limitations / open items

- `ward_beds` / `icu_beds` defaults are placeholders until the admin sets the real licensed counts
  (Control → Settings; changes are tracked in the on-page history).
- Clinical thresholds are **signed off** (2026-06-09): `short_los`=5, `long_los`=11, shuffle min/max
  confirmed; the readmission window (default 3 days) is admin-tunable in Control → Settings.
- The EHC logo in the UI is a recreated approximation pending the official asset.
- Deployment (credential rotation, TLS, backups) is documented but executed by the operator, not in CI.
