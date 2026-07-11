# Laravel app â€” production deployment runbook

> Ordered checklist for putting the **Laravel re-platform** (`laravel/` on branch `main`)
> into production. The legacy PHP app has its own runbook at the repo root ([`DEPLOY.md`](../../DEPLOY.md));
> this one is for the new system. Treat the first deploy as a supervised change with a tested rollback.

## 0. Server requirements

- **PHP 8.3+** with extensions: `pdo_mysql`, `mbstring`, `openssl`, `gd` (dompdf), `zip`, `bcmath`
- **MySQL 8.x** (InnoDB, utf8mb4)
- **Composer 2.x**; **Node 20+ / npm** only if building assets on the server (you can also build
  locally / in CI and upload `public/build/`)
- A web server (Apache or nginx) with the document root pointed at **`laravel/public`** â€”
  never at `laravel/` itself
- HTTPS/TLS terminated in front of the app (this system holds PHI â€” do not run plain HTTP)

## 1. Code

```bash
git clone -b main https://github.com/ahmedsk2/dmc-patient-flow-hub.git
cd dmc-patient-flow-hub/laravel
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # or upload a locally-built public/build/
```

## 2. Configuration (`.env`)

```bash
cp .env.example .env
php artisan key:generate
```

Set in `.env` (never commit it):

| Key | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | the real https URL |
| `DB_*` | host, **`dmc_laravel`** (or your name), a **dedicated DB user with a fresh password** (the old legacy credentials were exposed â€” never reuse them) |
| `LEGACY_DB_*` | only needed if you will run `legacy:import` on this server; otherwise remove/leave unused |
| `MAIL_*` | the SMTP relay (password-reset emails); **rotated** SMTP credentials |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS only) |
| `APP_TIMEZONE` | the hospital's zone (e.g. `Asia/Riyadh`) â€” date columns are timezone-naive; "today" metrics drift near midnight if app/DB zones differ |
| `LOG_CHANNEL` / `LOG_LEVEL` | `daily` / `warning` (rotated; stock `single` grows unbounded) |
| `SENTRY_DSN` | optional but recommended: `composer require sentry/sentry-laravel` on the server, set the DSN, and unhandled exceptions get alerted instead of dying silently in a log file (the SDK is inert while the DSN is empty) |

## 3. Database

Pick **one**:

- **(a) Upload the ready-made export** â€” import `dmc_laravel_export.sql` into an empty database.
  Schema + data arrive together; no migrate needed. Afterwards run `php artisan migrate` once anyway â€”
  it should say *Nothing to migrate* (sanity check that code and schema agree). If the export predates
  the handover feature it will instead run `2026_06_11_000001_create_handover_tables` (handovers,
  handover_revisions, handover_signatures, notifications) â€” that's expected; they start empty.
- **(b) Rebuild from the legacy DB** â€” create an empty DB, then:
  `php artisan migrate && php artisan legacy:import` (requires the `legacy` connection to reach the
  original database; the import is read-only on the source and idempotent on the target). The
  importer self-heals to satisfy the schema: out-of-range/garbage ages and inverted admission dates
  are NULLed, duplicate member emails are de-duplicated (lowest id keeps the address) to fit the
  `users.email` unique index, and it raises `memory_limit` to 1 G when the CLI default is lower. To
  refresh an already-populated target with a newer legacy dump, just re-run `legacy:import` (it
  truncates and re-derives).

## 4. First-login / app configuration (in the UI, as an admin)

- [ ] **Control â†’ Settings:** set the real **Licensed ward beds** and **ICU beds** (occupancy is wrong
      until you do â€” defaults are placeholders). Changes are recorded in the on-page history.
- [ ] **Control â†’ Settings:** the **MFA enforcement** setting is now inert — MFA (authenticator app) is
      mandatory for **every** user since the 2026-07-11 auth-hardening (each user enrols at their
      next login; email verification is likewise required for accounts with an on-file address).
- [ ] **Control â†’ Users:** verify roles/capabilities; deactivate any accounts that should not exist.
- [ ] **New Admissions queue:** assign any patients showing as *awaiting assignment*.

## 5. Hardening & ops

- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- [ ] File permissions: web user needs write only to `storage/` and `bootstrap/cache/`
- [ ] **Backups:** schedule a daily `mysqldump` of the app DB + retention; verify a restore once.
      (No backup process shipped with the legacy system â€” this must be stood up.)
- [ ] Log rotation for `storage/logs/`
- [ ] Confirm `/login` is reachable over HTTPS and plain-HTTP redirects to HTTPS

## 6. Post-deploy smoke test

- [ ] Log in as a real admin (existing staff passwords carried over from the legacy system)
- [ ] Dashboard renders with believable census numbers; Bed Occupancy sensible after step 4
- [ ] Admit a test patient â†’ appears on New Admissions â†’ assign â†’ appears on Active board â†’ discharge
      (medical â†’ complete) â†’ appears in Registry â†’ **delete/clean the test record**
- [ ] PDF download works (Reports â†’ Download PDF)
- [ ] `php artisan test` on a staging copy is green (291 tests / 2,338 assertions as of 2026-06-12)

## Rollback

The previous system (legacy PHP app) remains deployable from the repo root per [`DEPLOY.md`](../../DEPLOY.md);
both apps read the same kind of MySQL backup. Rollback = repoint the web server + restore the pre-deploy
DB backup.

## CI

`.github/workflows/laravel-ci.yml` (workflow name **CI**) gates every push/PR to `main` and
`main` (formerly `laravel-replatform`, consolidated 2026-07-10). It is intentionally a separate file from the legacy app's own
`.github/workflows/ci.yml` at the repo root — the two apps have independent pipelines; do not merge
them. Two independent jobs:

- **frontend** — `npm ci` → Vitest → `npm run build` → the class allow-list drift guard →
  the contrast/perceptual-distance gate → a **build-reproducibility check**.
- **backend** — Composer install against a real MySQL 8 service container (mirrors `phpunit.xml`,
  which runs `RefreshDatabase` against MySQL so the actual SQL dialect is exercised), then the
  project's established **two-pass** `php artisan test` (the `pdf` group runs in its own process —
  dompdf's native font-compression state segfaults PHP if it shares a long-lived process with the
  rest of the suite).

**Build-reproducibility invariant:** `public/build/` is committed (so the app can be deployed
without a Node toolchain on the server — see §0/§1 above). CI rebuilds it from source and fails if
`git status --porcelain -- public/build` is non-empty afterwards — that covers both a modified
committed asset *and* a stray new/untracked one. If this step is red, someone edited a `.vue`/CSS/JS
source file and forgot to rebuild-and-commit `public/build`; the fix is `npm run build` locally,
then commit the resulting diff.

**A red build otherwise** means one of: a genuine test regression, an accessibility contrast
regression (a colour token no longer clears WCAG or is perceptually indistinguishable from a
neighbour — see `scripts/contrast.mjs`), or allow-list drift (the set of Tailwind classes
`app.css`'s `@source` allow-list can see changed without updating the committed snapshot — see
`scripts/check-source-allowlist.mjs`). None of these are neutral logging on the current gate — the
gates are the assertion; no test count or snapshot value is hard-coded into the workflow itself.
