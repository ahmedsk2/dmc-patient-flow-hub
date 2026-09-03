# DMC Internal Medicine — Patient-Flow Hub

The Laravel application that runs an Internal Medicine unit's day-to-day patient flow. It is a
**live clinical system holding protected health information (PHI)**: treat every part of this
repository as security-sensitive, never point it at production data from a workstation, and read
[`SECURITY.md`](SECURITY.md) before reporting or probing anything.

This directory (`laravel/`) is the go-forward system. The repository root holds the hardened
legacy PHP application it replaced (branch `renovation`, kept as a rollback target) and the
project-level documents (`CLAUDE.md`, `REVIEW-FINDINGS.md`, `RENOVATION-PLAN.md`, `DEPLOY.md`).

## What it does

- **Admissions** — admit a patient with demographics and ICD-10 diagnoses, queue them for a
  consultant, assign manually or with the auto-balancing "shuffle", assign-to-me, bulk
  reassignment. (`AdmissionsController`, `PatientActionController`)
- **Patient board** — the active census by ward / ICU / ER with LOS bands and flags; transfers
  (ward ↔ ICU, to another specialty), two-phase discharge (medical → complete) with outcome,
  long-term and TB views, an admin-only reverse of same-day discharges. (`PatientsController`,
  `RecentController`)
- **Consultations ledger** — per-specialty bookkeeping of consults referred to the unit (or by it),
  with sign-off, a recent-activity registry and a physician dashboard.
  (`ConsultationsController`, `ConsultationDashboardController`)
- **Handovers** — create, edit, transfer, acknowledge and remind on patient handovers, with the
  compliance record described in [`docs/HANDOVER-COMPLIANCE.md`](docs/HANDOVER-COMPLIANCE.md).
  (`HandoverController`)
- **Dashboards, statistics and reports** — live KPIs and charts, date-range statistics, and the
  annual A4 booklet rendered server-side to PDF and emailed monthly. (`DashboardController`,
  `StatisticsController`, `ReportsController`, `App\Jobs\GenerateMonthlyReport`)
- **Registry** — historical search over admissions and consultations with CSV / XLSX export.
  (`RegistryController`)
- **Administration** — control panel for settings, users, roles and capability flags, runtime
  SMTP / timezone, patient merge, data-quality review, bulk historical import, trash, and the
  audit-log viewer. (`ControlController`, `PatientMergeController`, `DataQualityController`,
  `ImportController`, `TrashedController`, `AuditController`)

Every number on the dashboard, statistics and report pages is defined in
[`docs/DASHBOARD-AND-STATISTICS-METRICS.md`](docs/DASHBOARD-AND-STATISTICS-METRICS.md); what every
button does to the database is in [`docs/DATABASE-AND-BEHAVIOR.md`](docs/DATABASE-AND-BEHAVIOR.md).

## Who uses it

The unit's clinicians and administrators. Roles (`users.role`): **0 Admin**, **2 Registrar**,
**3 Consultant**, **4 Resident**, **5 Observer** (read-only everywhere). Per-user capability flags
(assign / add / manage / modify) refine what a role may do; all of it is enforced server-side, not
just in the UI. Control panel, statistics, registry, reports, recent-activity, import and every
export are admin-only.

## Stack

| Layer | What | Pinned in |
|---|---|---|
| Runtime | PHP 8.3+, MySQL 8 (InnoDB, utf8mb4) | `composer.json`, `docs/DEPLOY-LARAVEL.md` |
| Backend | Laravel `^13.8`, Inertia (`inertiajs/inertia-laravel ^3.1`), dompdf `^3.1` (PDF), openspout `^5.3` (XLSX) | `composer.json` |
| Frontend | Vue `^3.5` (`<script setup>`), `@inertiajs/vue3 ^3.3`, Tailwind CSS `^4`, ApexCharts `^5` via `vue3-apexcharts`, Vite `^8` | `package.json` |
| Tests | PHPUnit (Feature tests against a real MySQL database), Vitest `^3` | `phpunit.xml`, `vitest.config.js` |

No SSR; the browser renders everything. `public/build/` is committed so the deploy host needs no
Node toolchain.

## Running it locally

Requirements: PHP 8.3+ with `pdo_mysql mbstring openssl gd zip bcmath`, Composer 2, Node 20+,
a local MySQL 8.

```bash
cd laravel
composer install
npm ci
cp .env.example .env            # read the comments — every variable the app reads is listed there
php artisan key:generate
# create the database named in .env (DB_DATABASE, default dmc_laravel), then:
php artisan migrate
```

**Data.** Either seed synthetic, non-PHI demo data so the board and charts populate —

```bash
php artisan db:seed --class=DemoSeeder
```

— or import a *local copy* of the legacy database through the read-only `legacy` connection
(`LEGACY_DB_DATABASE` in `.env`; see `config/database.php`). Never point that at production.

```bash
php artisan legacy:import
```

**First admin.** There is no default account. Create one, mirroring what self-registration would
set (a NULL `pass_exp_date` counts as an expired password):

```bash
php artisan tinker
>>> App\Models\User::create(['username' => 'admin', 'name' => 'Admin', 'email' => 'you@example.test',
...   'password' => 'choose-a-long-passphrase', 'role' => 0, 'active' => 1,
...   'pass_exp_date' => now()->toDateString()]);
```

On first sign-in the app walks you through email verification and MFA enrolment — both are
mandatory. With `MAIL_MAILER=log` the verification code is written to `storage/logs/laravel.log`.

**Serve.**

```bash
npm run dev                      # Vite with HMR (the CSP auto-relaxes while public/hot exists)
php artisan serve --port=8001    # http://localhost:8001 — matches APP_URL in .env.example
php artisan schedule:work        # optional: runs the scheduler, incl. the /health heartbeat
```

Production deployment is a separate, ordered runbook: [`docs/DEPLOY-LARAVEL.md`](docs/DEPLOY-LARAVEL.md).

## Tests and gates

All of these run in CI (`.github/workflows/laravel-ci.yml` at the repository root; two jobs,
**frontend** and **backend**) and should be green before a merge.

**PHPUnit** runs against a real MySQL database — `dmc_test`, per `phpunit.xml` — so the actual SQL
dialect is exercised (`RefreshDatabase` migrates it). Create it once with
`CREATE DATABASE dmc_test`. The suite is a **two-pass** run because dompdf's native state
segfaults PHP when the PDF tests share a long-lived process with the rest:

```bash
php artisan test --exclude-group pdf     # pass 1: everything else
php artisan test --group pdf             # pass 2: the PDF tests, isolated
```

`tests/Feature/LegacyImportTest.php` is tagged `slow-import`; skip it in a quick local loop with
`--exclude-group slow-import` (CI runs it). Feature tests build their fixtures inline rather than
through factories — follow the idiom in `tests/Feature/Round5J1Test.php`.

**Frontend** gates, from `laravel/`:

```bash
npx vitest run               # component / composable specs
npm run build                # must leave public/build unchanged in git — CI rebuilds and diffs it
npm run check-allowlist      # Tailwind @source allow-list drift guard (scripts/check-source-allowlist.mjs)
npm run contrast             # WCAG 2.2 contrast + CIEDE2000 perceptual-distance gate (scripts/contrast.mjs)
```

If you touch a `.vue`, CSS or JS source, run `npm run build` and commit the resulting
`public/build` diff, or the build-reproducibility step fails.

## Operational endpoints and jobs

- `GET /up` — Laravel's static liveness page (what the hosting platform polls).
- `GET /health` — the deep probe: database (fresh 2-second-bounded connection), `storage/framework`
  writable, and the scheduler heartbeat (stale after 5 minutes). `200 ok` / `503 degraded`, JSON,
  no PHI, no secrets, throttled, session-less. Reports `app.version` from `APP_VERSION`.
- `GET /.well-known/security.txt` — RFC 9116 disclosure contact (`SECURITY_CONTACT`).
- Scheduled (`routes/console.php`): `scheduler:heartbeat` every minute, `audit:ship` hourly,
  `audit:verify-daily` at 02:30, `dq:notify` at 07:00, the monthly report on the 1st at 06:00.
- Operator-run only: `audit:verify`, `audit:prune --confirm`, `legacy:import`.

## Documentation

| Document | Contents |
|---|---|
| [`docs/DEPLOY-LARAVEL.md`](docs/DEPLOY-LARAVEL.md) | Production deployment runbook, update and rollback procedure, CI description |
| [`docs/DATABASE-AND-BEHAVIOR.md`](docs/DATABASE-AND-BEHAVIOR.md) | Schema, and exactly what every button and field does to the database |
| [`docs/DASHBOARD-AND-STATISTICS-METRICS.md`](docs/DASHBOARD-AND-STATISTICS-METRICS.md) | Definition, formula and caveats for every dashboard / statistics / report number |
| [`docs/HANDOVER-COMPLIANCE.md`](docs/HANDOVER-COMPLIANCE.md) | Handover lifecycle and audit reference for clinical governance |
| [`docs/RECONCILIATION.md`](docs/RECONCILIATION.md) | How to verify the Laravel app reproduces the legacy system's numbers after a data load |
| [`docs/LEGACY-VS-LARAVEL-DASHBOARD-COMPARISON.md`](docs/LEGACY-VS-LARAVEL-DASHBOARD-COMPARISON.md) | A worked dashboard comparison on a real dump |
| [`docs/UAT-TEST-PLAN.md`](docs/UAT-TEST-PLAN.md) (+ `.docx`) | Hands-on UAT checklist for the testing team |
| [`docs/REVIEW-2026-06-09.md`](docs/REVIEW-2026-06-09.md) | Earlier four-track review (functionality, security, performance, quality) |
| [`docs/EHC-UI-WAVES-1-5-REPORT.md`](docs/EHC-UI-WAVES-1-5-REPORT.md) | UI/UX renovation verification report |
| `docs/superpowers/specs/`, `docs/superpowers/plans/` | Design specs and implementation plans behind the larger changes |
| [`README-DMC.md`](README-DMC.md) | The original re-platform write-up (partly historical — branch names and test counts have moved on) |
| [`SECURITY.md`](SECURITY.md) | Vulnerability-disclosure policy (identical copy at the repository root) |

## Security posture

- **MFA is mandatory for every user.** TOTP enrolment is enforced by `EnsureMfaEnrolled` on every
  authenticated route; self-registration only creates an account after the email is verified and
  the authenticator confirmed (`RegisterController`).
- **Tamper-evident audit log, shipped in-Kingdom.** Every write records an `audit_log` row
  hash-chained to its predecessor (`App\Support\Audit`, `AuditLog` model hooks); the chain is
  verified nightly (`audit:verify-daily`) and shipped hourly off-box (`audit:ship`) to an
  S3-compatible archive whose configured region defaults to `me-riyadh-1` (`config/services.php`).
- **PHI never in URLs** (SPC-TM-011). Patient names and MRNs travel in POST bodies — registry
  search and patient merge are POST-only — and routes carry opaque ids, so access logs, browser
  history and CSP reports stay PHI-free.
- **CSP with a per-request nonce** plus the static header set (`SecurityHeaders` middleware:
  `frame-ancestors 'none'`, HSTS with preload over TLS, `no-store` on authenticated pages) and a
  log-only `/csp-report` sink so violations are visible to operators.
- **Break-glass logging.** PHI reads write their own audit rows — per-record opens (when
  `log_record_opens` is on in Control → Settings), handover reads, every export, and registry
  searches (`AdmissionsController`, `HandoverController`, `RegistryController`) — and the audit
  viewer surfaces them as their own category (`AuditController`).
