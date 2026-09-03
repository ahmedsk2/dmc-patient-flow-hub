# CLAUDE.md — DMC Internal Medicine Patient-Flow Hub (Laravel)

> **Read [`HANDOFF.md`](HANDOFF.md) first every session.** It is the ground truth for what is live and
> what remains. This file is the standing map of the product: stack, architecture, data, flows,
> operations and guardrails. Last rewritten 2026-09-03 (`main` at `31f0bfb` + this commit).
>
> **The go-forward product is the Laravel application under [`laravel/`](laravel/)** — this is what all
> repo work targets. **Ground truth as of 2026-09-03:** the Laravel app at `dmc-new.towardpcc.com` is
> live with a **parallel copy** of the data but is **not yet the daily system**. The unit's daily
> system is still the **legacy PHP app at `dmc-im.com`, hosted on SiteGround (United States)** — and
> the live build there is the **original, un-hardened** one, not the `renovation` hardening (verified
> 2026-09-03). The plan is to replace the dmc-im.com code with the Laravel app at cutover. Until then,
> the legacy site carries the original systemic security defects over real PHI; see
> [`docs/compliance/CONFIRMED-FACTS.md`](laravel/docs/compliance/CONFIRMED-FACTS.md) B1–B3.

---

## 1. What this is

A hospital patient-flow hub for one Internal Medicine unit: admissions, consultant assignment,
ward ↔ ICU transfers, two-phase discharge, a per-specialty consultation ledger, clinical handovers,
registry search with export, live dashboards, statistics and PDF reports, and an admin control panel.

**It is a live clinical system holding real protected health information (PHI).** Roughly 17k
patients, 37k admission episodes and 330 staff accounts. The Laravel app is live at
`https://dmc-new.towardpcc.com` (Cloudflare-proxied) on one OCI host in Riyadh, running in parallel
with the legacy daily system (see the scope note above) until cutover. **Saudi PDPL / SDAIA applies**:
Laravel data stays in-Kingdom; the legacy daily system and the mail relay are on US hosting, which is
the live cross-border exposure. There is **one Laravel environment**. There is no staging. Every
deploy is a production change.

---

## 2. Guardrails (non-negotiable)

- **Never fabricate** clinical, handover or audit records. Never invent a legal or compliance fact.
- **Never delete** `audit_log` or `notifications` rows. `audit:prune` is operator-run only, behind
  `--confirm`, and is never scheduled.
- **No PHI in URLs, logs, CSP reports or chat output.** Patient names and MRNs travel in POST bodies;
  routes carry opaque ids (SPC-TM-011). Never paste a dump, a narrative or a patient row into output.
- **The owner handles all secrets.** Never ask for one, print one, or commit one. `.env`, tokens and
  SQL dumps are git-ignored and must stay that way.
- **Confirm before** anything destructive or outward-facing: deploys, data reloads, deletions, emails,
  DNS or firewall changes, anything that touches production data.
- **Branch model:** `main` is the single Laravel dev branch and **direct-to-main is the consented
  workflow**. `renovation` is the deployable legacy lineage. CI must be green before a deploy.
- **Deploy only via the Coolify procedure** in `laravel/docs/DEPLOY-LARAVEL.md` and session memory.
  Back up the database before any deploy that carries a migration.
- **Token economy (owner's standing priority):** pin `haiku`/`sonnet` on every subagent; no
  Workflow or adversarial fan-out unless asked or the change is genuinely high-risk (schema, auth,
  clinical logic); read narrowly; prefer a fresh session per task.

---

## 3. Stack

| Layer | What | Pinned in |
|---|---|---|
| Runtime | PHP 8.3, MySQL 8.4 (InnoDB, utf8mb4, real foreign keys) | `laravel/composer.json`, `docs/DEPLOY-LARAVEL.md` |
| Backend | Laravel `^13.8`, Inertia (`inertiajs/inertia-laravel ^3.1`), dompdf `^3.1` (PDF), openspout `^5.3` (XLSX) | `composer.json` |
| Frontend | Vue `^3.5` (`<script setup>`), `@inertiajs/vue3 ^3.3`, Tailwind CSS `^4`, Chart.js `^4` via `ChartCanvas.vue`, Vite `^8`, driver.js (tour), zxcvbn (password meter), qrcode (MFA enrolment) | `package.json` |
| Tests | PHPUnit `^12` against a real MySQL `dmc_test`, Vitest `^3` + vitest-axe | `phpunit.xml`, `vitest.config.js` |
| Hosting | Coolify v4 + Nixpacks on one OCI Ubuntu instance (`me-riyadh-1`), MySQL 8 container on the same host, Cloudflare in front | `docs/DEPLOY-LARAVEL.md` §0 |

No SSR. No queue worker (`QUEUE_CONNECTION=sync`). No third-party auth or AWS SDK: TOTP and S3
SigV4 signing are small in-house helpers under `app/Support/`. `public/build/` is **committed**, so
the host never runs Node. As of 2026-09-03 `npm audit` reports 0 vulnerabilities and the blocking
composer-audit gate in CI passes.

---

## 4. Repository layout

```
/                      repo root — retired legacy PHP app (history only, §14) + project docs
├─ HANDOFF.md          ground truth: what is live, what remains, doc map
├─ CLAUDE.md           this file
├─ .github/workflows/  laravel-ci.yml (the product's CI) · ci.yml (legacy CI — never merge them)
└─ laravel/            THE PRODUCT
   ├─ app/
   │  ├─ Http/Controllers/   one controller per module (+ Auth/, Concerns/MetricQueries)
   │  ├─ Http/Middleware/     SecurityHeaders, SessionTimeout, EnsureMfaEnrolled, EnsureEmailVerified,
   │  │                       EnsurePasswordNotExpired, RequireStepUp, EnsureAdmin, HandleInertiaRequests
   │  ├─ Http/Requests/       FormRequests for the riskier writes (admission, consultation, merge, exports)
   │  ├─ Models/              20 Eloquent models (§6)
   │  ├─ Casts/               EncryptedNarrative (§9)
   │  ├─ Console/Commands/    audit:{ship,verify,verify-daily,prune}, backup:verify, dq:notify,
   │  │                       legacy:import, scheduler:heartbeat
   │  ├─ Jobs/ Mail/          GenerateMonthlyReport / GenerateMonthlyPdf; registration, reminder, report mails
   │  ├─ Services/            ShuffleService (auto-assignment)
   │  ├─ Support/             Audit, AuditDiff, DashboardCache, ReportSvg, S3SigV4, Totp
   │  └─ Providers/           AppServiceProvider, RuntimeConfigServiceProvider (§5)
   ├─ routes/                 web.php (all app routes) · public.php (session-less probes) · console.php (schedule)
   ├─ database/migrations/    47 migrations — the authoritative schema
   ├─ resources/js/           Pages/<Module>/ · Components/ · Layouts/ · composables/ · lib/ · __tests__
   ├─ tests/Feature (105 files) · tests/Unit (2)
   ├─ scripts/                smoke.sh, contrast.mjs, check-source-allowlist.mjs, backup/{db-backup.py,db-restore-drill.sh}
   ├─ docs/                   runbooks + behaviour docs (§13) · docs/compliance/ (PDPL paper trail)
   └─ .prod-ready/            local audit workspace — never commit
```

Directory names under `resources/js` are capitalised (`Pages`, `Components`, `Layouts`). Windows
hides case mistakes that Linux CI catches; the Inertia config is published to point at `Pages`.

---

## 5. Architecture and runtime

**Request pipeline** (`bootstrap/app.php`, `routes/web.php`):

- `SecurityHeaders` is **prepended** to the `web` group so even 419/CSRF error responses get stamped:
  per-request nonce CSP (`CSP_MODE` env; auto-relaxed while Vite's `public/hot` exists),
  `frame-ancestors 'none'`, HSTS, `no-store` on authenticated pages. Violations POST to `/csp-report`
  (throttled, log-only, CSRF-exempt).
- `trustProxies` is pinned to loopback/private ranges plus Cloudflare's published CIDRs, never `*`,
  so forged `X-Forwarded-For` cannot defeat the IP-keyed throttles or poison audit IPs.
- Public routes: login (username **or** email, `throttle:auth`), phased registration, forgot
  password, forgot username, `/mfa/challenge`, and the `/privacy` notice.
- Authenticated group: `auth → session.timeout → email.verify → mfa.enroll → pwd`. Every user must
  have a verified email, an enrolled TOTP authenticator and a password younger than three months
  (NULL counts as expired) before any clinical page renders.
- `admin` group inside it: registry, statistics, reports, recent activity, import, control panel,
  audit viewer, trash, security page, data quality, patient merge, style guide.
- `stepup` (fresh password re-check, `throttle:stepup`) on: reverse discharge, delete admission,
  Control → System save and test email, delete user, patient merge.
- Session-less machine routes in `routes/public.php`: `/up` (Coolify liveness), `/health` (DB +
  storage + scheduler heartbeat; `200 ok` / `503 degraded`, no PHI), `/.well-known/security.txt`.

**Inertia** shares `auth.user` (id, name, role, `is_admin`, `mfa_enrolled`, `email_verified`, the four
`can.*` flags), the idle/absolute timeout minutes and `flash` on every page
(`HandleInertiaRequests`). The browser renders everything; the `useSessionTimeout` composable
warns and logs out on idle.

**Runtime config.** `RuntimeConfigServiceProvider` overrides `mail.*` and the timezone at boot from
the `settings` row edited in Control → System, so SMTP and timezone changes need no restart. The SMTP
password is stored encrypted and is write-only in the UI. `.env` values are the fallback.

**Scheduler.** Nothing in the container runs it. A host root cron runs `php artisan schedule:run`
every minute via `docker exec` on the container found **by label**. Scheduled: `scheduler:heartbeat`
every minute, `audit:ship` hourly, `audit:verify-daily` 02:30, `backup:verify` 06:30, `dq:notify`
07:00, the monthly report on the 1st at 06:00.

**Audit trail.** `App\Support\Audit::log()` writes an `audit_log` row `{actor, action, entity,
details JSON, ip}` for every write and for PHI reads (record opens when `log_record_opens` is on,
handover reads, exports, registry searches). Rows are **hash-chained** (`prev_hash` + sha256
`row_hash`, taken under a row lock), verified nightly, and shipped hourly as write-once NDJSON to the
in-Kingdom bucket `dmc-audit-log`. `AuditDiff` records before/after on updates.

---

## 6. Data model

Authoritative source: `laravel/database/migrations/`. Full data dictionary and what every button
writes: [`laravel/docs/DATABASE-AND-BEHAVIOR.md`](laravel/docs/DATABASE-AND-BEHAVIOR.md).

| Connection | Database | Use |
|---|---|---|
| `mysql` (prod) | `dmc_demo`, app user `dmc_demo` (not root) | the live application database |
| `mysql` (local default) | `dmc_laravel` | local development |
| `mysql` (testing) | `dmc_test` | PHPUnit `RefreshDatabase` |
| `legacy` (read-only) | `dmc_prod` | input of `legacy:import` only; the app never writes here |

**Core tables**

- `users` — staff. `role` (0 Admin, 2 Registrar, 3 Consultant, 4 Resident, 5 Observer),
  `specialty_id`, `active`, `on_service`, capability flags `can_assign / can_add / can_manage /
  can_modify / can_coordinate_consultations`, `pass_exp_date`, TOTP fields (`mfa_secret` encrypted,
  `mfa_recovery_codes`, `mfa_enrolled_at`, `mfa_last_counter` replay guard), `email_verified_at`,
  `tour_completed_at`, `legacy_id`. Soft-deleted.
- `patients` — one row per MRN (identity + demographics). Soft-deleted; admin **patient merge**
  reconciles duplicates.
- `admissions` — one row per **episode**; a readmission or ward ↔ ICU transfer opens a new row.
  `consultant_id` NULL = unassigned. `medical_discharge_date` (phase 1) and `discharge_date`
  (phase 2; NULL = active). `discharge_to` is a destination, `outcome` is strictly Alive/Dead.
  `transfer_type`, `current_location` (ER/Ward/ICU), `is_longterm`, `assigned_at` (drives the
  24-hour "New" badge), `admitted_by` / `discharged_by` from the session. Soft-deleted.
- `admission_diagnoses` — one ICD-10 row per diagnosis (unique per admission).
- `consultations` — the ledger: `status` ∈ `new / active / ongoing / signed_off`, indication JSON,
  `to_service`, receiving `consultant_id`, `entered_by`, `signoff_date`, encrypted `response_note`.
  Plus `consultation_followups` (encrypted daily `note`). Soft-deleted.
- Handover subsystem — `handovers` (one current row per admission, encrypted `body`, six
  checkpoint flags incl. `code_status`), `handover_revisions` (append-only, encrypted), `handover_signatures`
  (created on consultant-to-consultant moves; bound to the revision actually read; voided when
  superseded or when the patient's last episode closes), `notifications` (the bell; `resolved_at`,
  `admission_id`).
- `audit_log` — append-only, hash-chained (§5). `setting_changes` — history of every settings edit.
- Auth support — `pending_registrations` (phased sign-up state before a user row exists),
  `trusted_devices`, `password_reset_tokens`, `sessions`.
- `settings` (single row) — LOS bands (`short_los` 5, `long_los` 11), shuffle min/max per pool,
  `ward_beds` / `icu_beds` (still placeholders), `readmission_window_days` (3, clinically confirmed),
  alert thresholds, `log_record_opens`, idle/absolute session timeouts (default 30 min / off),
  `failed_login_threshold`, `dq_los_multiplier`, runtime mail + timezone, audit ship/retention,
  `consultations_source_of_truth` (§11), `mfa_enforcement` (inert: MFA is mandatory regardless).
- Reference — `icd10` (~72k), `tb_diagnoses`, `specialties` (`is_subspecialty`, `is_external`),
  `consultation_reasons`, `countries`, `report_recipients`.

**Derived admission states** (no status column): Unassigned = active + no consultant; Active Ward /
Active ICU by `current_location`; Medically discharged ("still in") = `medical_discharge_date` set,
`discharge_date` NULL; Long-term and TB are cross-cutting flags; Discharged = `discharge_date` set.

---

## 7. Roles, capabilities and authorization

- **Page access by role.** Clinical pages: Admin, Registrar, Consultant, Resident. **Observer is
  read-only** everywhere. Admin-only: everything in the `admin` route group (§5).
- **Per-action by capability.** `can_add` admits; `can_assign` assigns to a chosen consultant,
  shuffles, bulk-reassigns; `can_manage` transfers/discharges any patient; `can_modify` edits patient
  details; `can_coordinate_consultations` coordinates the ledger. The **primary consultant** may
  manage their own admission without `can_manage` (`User::canManageAdmission`). Assign-to-me is open
  to any clinical role, never Observer.
- **Enforced server-side** in controllers and FormRequests, not just by hidden buttons. Capability
  grants are broad by owner decision (e.g. Residents with Can-Manage); do not "tidy" them.
- **Auth lifecycle:** mandatory TOTP MFA for every user (challenge expires after 5 minutes, 8
  attempts, replay-guarded); an MFA login is never remembered; self-disable of MFA is removed, only
  Control → Reset MFA clears it; mandatory email verification; phased self-registration (email
  OTP + authenticator confirmed **before** the account row exists, then `active=0` pending admin
  activation, role never Admin); password expiry at three months; idle timeout; step-up for §5's
  sensitive actions; failed-login throttling keyed by IP and username.

---

## 8. Core flows

Each flow names its controller; per-endpoint database effects are in DATABASE-AND-BEHAVIOR.md §5.

1. **Admission** (`AdmissionsController`, `/admissions`): admit with demographics + ICD-10 typeahead →
   `patients` upsert + new `admissions` row + diagnoses, consultant NULL → unassigned queue.
2. **Assignment**: assign-to-primary, assign-to-me, or **shuffle** (`ShuffleService` balances
   unassigned patients across on-service consultants using the settings min/max pools). Bulk
   reassign moves a selected subset of one consultant's patients and is gated by the **same-day
   handover rule**: a patient may move to a different consultant only if their handover was updated
   today (first assignments exempt).
3. **Board actions** (`PatientsController`, `PatientActionController`, `/patients`): modify,
   long-term toggle, ward ↔ ICU transfer (closes the episode and opens a new one in a transaction,
   bed and diagnoses carried), specialty transfer (external specialties close without reopening),
   two-phase discharge (medical → complete) or one-step ICU discharge with outcome, admin
   same-day reverse discharge, admin delete (step-up). `/active-list` is the printable census.
4. **Handovers** (`HandoverController`): edit the note + checkpoints per admission; every save is a
   revision; consultant-to-consultant moves create a signature the receiver must sign after reading
   the bound revision; bell notifications and an `/handovers` inbox; persistent "incomplete
   handover" reminders. Governance reference: `docs/HANDOVER-COMPLIANCE.md`.
5. **Consultation ledger** (`ConsultationsController`, `ConsultationDashboardController`): create,
   status moves through new → active → ongoing → signed off, daily follow-ups, sign-off with an
   encrypted response note, admin same-day reverse sign-off, a per-service handover sheet and a
   physician dashboard. This app is the **source of truth** for consultations since cutover (§11).
6. **Registry and export** (`RegistryController`, admin): POST-only search over admissions,
   diagnoses and consultations; CSV and XLSX export; every search and export is audited.
7. **Dashboard, statistics, reports** (`DashboardController`, `StatisticsController`,
   `ReportsController`, `Concerns\MetricQueries`): live KPIs, date-range statistics, annual A4
   booklet and monthly report rendered to PDF with dompdf; the monthly job emails the prior month to
   `report_recipients`. Every number's formula and caveats: `docs/DASHBOARD-AND-STATISTICS-METRICS.md`.
   Conventions: active = `discharge_date IS NULL`; LOS = `DATEDIFF` in whole days; mortality counts
   `outcome='Dead'`; readmission = same patient within `readmission_window_days` of a real discharge.
8. **Recent activity** (`RecentController`, admin): yesterday + today; undo discharge / undo sign-off.
9. **Administration** (`ControlController` and friends): settings, users, roles, capabilities,
   specialties, indications, runtime SMTP/timezone, MFA reset, password-reset mail, bulk historical
   import with preview (`ImportController`), patient merge, data-quality review, trash, audit viewer,
   security page, in-app tour.

---

## 9. Security and privacy controls (what is actually in place)

- **Transport:** Cloudflare proxy, minimum TLS 1.2, HSTS; the origin's 80/443 accept **only
  Cloudflare ranges** (an unproxied DNS record or a direct curl gets nothing).
- **Headers:** nonce CSP enforced + static header set (§5); `SESSION_SECURE_COOKIE`,
  `SESSION_ENCRYPT=true`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
- **Encryption at rest:** the four narrative columns (`handovers.body`, `handover_revisions.body`,
  `consultations.response_note`, `consultation_followups.note`) via `App\Casts\EncryptedNarrative`
  (AES-256-CBC + HMAC under `APP_KEY`; tolerant of legacy plaintext on read, logging it). Also
  encrypted: `users.mfa_secret`, `settings.mail_password`. **`APP_KEY` is the root of trust**: a
  backup without the key at the time of the dump is incomplete; never run `key:generate` on a live
  environment. Rotation procedure and developer rules: `docs/ENCRYPTION-AT-REST.md`.
- **Never filter, sort, group or join on an encrypted column in SQL**, and never add one to an
  export, notification, log, dashboard or email payload. Raw reads (`DB::table`, `selectRaw`) return
  ciphertext; decrypt explicitly and add a test.
- **Backups:** nightly (02:15 host time) encrypted off-box dump to the in-Kingdom bucket
  `dmc-db-backups`; RPO ≤ 24 h; RTO is whatever the drill prints, plus the human steps; local
  encrypted copy 2 days, bucket 90 days (**placeholder pending legal**); `backup:verify` alerts admins
  in-app when the heartbeat is stale; monthly restore drill logged in `docs/BACKUP-AND-RESTORE.md` §8.
- **Audit:** tamper-evident chain, nightly verification, hourly off-box shipping (§5). Retention
  window is a setting; pruning is manual.
- **Repository hygiene:** git history purged of the three historically leaked secrets; GitHub secret
  scanning + push protection + Dependabot on; `.gitignore` blocks `.env*`, `*.sql`, logs; gitleaks
  and Semgrep run in CI; `.well-known/security.txt` and `SECURITY.md` carry the disclosure contact.
- **Login trust badges** state six truthful claims only. **No framework badges** (ISO, SOC 2, CBAHI)
  until a certificate exists.

---

## 10. Operations

Runbooks: [`DEPLOY-LARAVEL.md`](laravel/docs/DEPLOY-LARAVEL.md) (topology, deploy, rollback, env
vars, scheduler, import), [`BACKUP-AND-RESTORE.md`](laravel/docs/BACKUP-AND-RESTORE.md),
[`RELEASE-CHECKLIST.md`](laravel/docs/RELEASE-CHECKLIST.md). Host access, the Coolify API method and
environment gotchas are in session memory.

- **Deploy:** Coolify builds `laravel/` with Nixpacks from `main` HEAD, tags the image with the
  commit SHA, runs `migrate --force`, swaps the container. Coolify does **not** back up, test or smoke
  for you. Verify with `scripts/smoke.sh` against the public hostname, then `/health`, the audit
  chain and the scheduler heartbeat.
- **Rollback:** app-only = redeploy the previous image. App + DB = restore the pre-deploy dump, then
  roll the app back. **`migrate:rollback` is never the answer.** Keep migrations additive.
- **Env vars:** `APP_URL` and `AUDIT_S3_*` are build-time (need a rebuild); everything else is
  runtime (restart). The field name in the API is `is_buildtime`. `APP_TIMEZONE=Asia/Riyadh` is the
  fallback for the in-app timezone; date columns are timezone-naive, so UTC drifts every "today" rule
  by 3 hours.
- **Sessions and logs live in the container** (`SESSION_DRIVER=file`): a redeploy signs everyone out
  and discards the old container's logs unless `storage/` is a persistent volume.
- **Data reload** (`php artisan legacy:import`): the most destructive operator command. It
  **truncates target tables before its first legacy read**, resets user ids and MFA enrolment,
  truncates handovers and notifications, needs `GRANT SELECT ON dmc_prod.*` for `dmc_demo`, and
  respects the consultations cutover flag (§11). Always dump first; follow the keep-MFA snapshot →
  import → verify → restore path in DEPLOY-LARAVEL.md §8; clear file sessions after.
- **Local dev:** `laravel/README.md` has the full recipe (`composer install`, `npm ci`, `.env`,
  `migrate`, `db:seed --class=DemoSeeder` or a local legacy import, first admin via tinker, `npm run
  dev` + `php artisan serve --port=8001`). The Browser pane config `dmc-laravel` in `.claude/launch.json`
  serves it on port 8001. Local dev must never point at production data.

---

## 11. Consultations cutover flag

`settings.consultations_source_of_truth` records that **this app owns consultation data**. While ON
(the production state) `legacy:import` preserves `consultations` and `consultation_followups`,
re-pointing them to rebuilt patient/user rows by natural key, and refuses `--wipe-consultations`.
Turning it OFF re-arms the next import to **destroy the ledger**. Never flip it without reading
DEPLOY-LARAVEL.md §7. A failed import while ON requires a database restore before retrying. After
any restore, check the flag.

---

## 12. Testing, CI and quality gates

**CI** (`.github/workflows/laravel-ci.yml`, workflow "Laravel CI", path-scoped to `laravel/**`) has
four jobs, all under a read-only token and SHA-pinned actions:

| Job | Gates |
|---|---|
| `frontend` | `npm ci`, `npm audit --omit=dev`, Vitest (+ axe), `npm run build`, Tailwind `@source` allow-list drift guard, contrast/perceptual-distance gate, build-reproducibility (`public/build` must be unchanged after a rebuild) |
| `backend` | PHPUnit two-pass against MySQL 8 (everything except `pdf`, then `pdf` alone because dompdf segfaults in a shared process), `composer audit` arbitrated by `scripts/composer-audit-gate.php` (high/critical advisories block unless allow-listed with a reason) |
| `secrets` | gitleaks |
| `sast` | Semgrep, ERROR severity blocks |

CI was **green on 2026-09-03** (checked via `gh run list`). It only runs while **GitHub Actions
billing is enabled**; when billing lapses, jobs are created with zero steps and prove nothing, so
the same gates must be run locally per RELEASE-CHECKLIST.md. Required status checks on `main` are
not yet configured (CI.md preamble). The legacy `ci.yml` is a separate pipeline; never merge them.

**Baselines (2026-09-03):** PHPUnit ~926 tests (+56 in the `pdf` group), Vitest 717.

**Run locally from `laravel/`:**

```bash
php artisan test --exclude-group pdf      # includes the slow-import group — never ship without it
php artisan test --group pdf
npx vitest run
npm run build && git status --porcelain -- public/build   # must print nothing
npm run check-allowlist && npm run contrast
composer audit && npm audit --omit=dev
```

**Test conventions:** Feature tests build fixtures inline (idiom: `tests/Feature/Round5J1Test.php`),
not via factories. Because MFA and email verification are mandatory, any fixture user needs
`mfa_secret`, `mfa_enrolled_at`, `email_verified_at` and a recent `pass_exp_date` or the middleware
redirects the test. Run the isolated suite on a throwaway database to avoid races on shared `dmc_test`.
`tests/Feature/LegacyImportTest.php` (`slow-import`) is what proves a reload cannot destroy the ledger.

---

## 13. Conventions and gotchas

- **Tailwind v4 extractor mints utilities from `.vue` comments and string literals.** The
  `@source` allow-list snapshot is the guard; de-spell class-like tokens in comments/strings.
  Never write `*/` inside a comment in `app.css` (it ends the block and breaks cold builds; the Vite
  cache masks it once).
- **Publish vendor configs whose defaults embed paths** (Inertia's page directory).
- **Eloquent `encrypted` casts decrypt on `toArray()`**; use `$hidden` for anything that must not ship
  to the client (pattern: `Setting::$mail_password`).
- **`Schema::hasTable` throws (not false) when the DB is unreachable** inside a boot-time provider;
  `RuntimeConfigServiceProvider` wraps it, keep it that way (it crashed `package:discover` in CI once).
- **Theme tokens:** brand solid `#00727b`; status tints use theme-invariant `bg-tint-X text-on-X`
  steps. Tailwind v4 Preflight makes `<button>` cursor default; a base-layer rule restores pointer.
- **Attribution is session-sourced** everywhere (`admitted_by`, `discharged_by`, `entered_by`); never
  accept a user id from the request for it.
- **"Active" is `discharge_date IS NULL`** canonically; per-page ICU-excluding variants are
  intentional. Confirm the definition before changing any count.
- **Readmission window, LOS bands and shuffle limits are clinically confirmed** (2026-06-09) and
  admin-tunable; `ward_beds` / `icu_beds` are still placeholders.
- **Hard deletes of patient data** happen only via the explicit admin delete (step-up, audited) and
  consultation delete; routine flow never destroys rows. Recovery is via backups and the trash page.
- **`laravel/.prod-ready/` and the root `docs/` folder** (metrics notes, deferred backlog,
  renovation history) are working notes, not product code.
- Legacy identifiers (`PICU`, `picupatients`, `specilaity`) survive only in the `legacy` connection
  and import code; the Laravel schema uses the names in §6.

---

## 14. Compliance and audit-readiness status

PDPL / SDAIA applies; the hospital may also pursue NCA ECC / DCC and, if chosen, ISO 27001, SOC 2 or
CBAHI. State on 2026-09-03:

- **Drafted, awaiting facts:** nine documents in [`laravel/docs/compliance/`](laravel/docs/compliance/)
  (privacy notice EN/AR, RoPA, DPIA, incident response, retention, classification, DPO, DPAs and
  transfers) with **529 open markers** catalogued by file and line in
  [`OPEN-ITEMS.md`](laravel/docs/compliance/OPEN-ITEMS.md). Fill each only after the owner or the
  hospital's legal/DPO confirms it. Never invent a legal citation, retention period or entity name.
- **Known compliance-relevant facts:** US-based SMTP relay for outbound mail (a transfer question);
  in-Kingdom hosting, backups and audit archive; a 90-day backup retention placeholder;
  `APP_KEY` escrowed by the owner; SSH IP allow-list deferred by the owner.
- **Readiness scoring:** re-scored **2026-09-03** after the remediation: **BLOCKED 58/100**
  (emphasis view 70), up from 27/37 on 2026-09-02. The block is two CI/CD process Criticals
  (no required status checks on `main`; manual smoke/rollback), both cheap to close. Full report:
  [`laravel/docs/compliance/evidence/prod-ready-2026-09-03.md`](laravel/docs/compliance/evidence/prod-ready-2026-09-03.md)
  (the working cache in `laravel/.prod-ready/` is git-ignored). External header/TLS grades the same
  day: Laravel app **A**, legacy daily site **F** (`evidence/sec-web-2026-09-03.md`). Re-score only
  when it is genuinely useful.
- **Evidence pack:** to be built as items close, mapping each required control to where it is
  satisfied in this repo and infrastructure (HANDOFF.md item 3).

---

## 15. Doc map

| Area | Files |
|---|---|
| Ground truth / what remains | `HANDOFF.md` |
| Product overview, local setup | `laravel/README.md`, `laravel/SECURITY.md` |
| Deploy / ops | `laravel/docs/{DEPLOY-LARAVEL, BACKUP-AND-RESTORE, ENCRYPTION-AT-REST, CI, RELEASE-CHECKLIST}.md` |
| Behaviour / metrics | `laravel/docs/{DATABASE-AND-BEHAVIOR, DASHBOARD-AND-STATISTICS-METRICS, HANDOVER-COMPLIANCE, RECONCILIATION, UAT-TEST-PLAN}.md` |
| Compliance (PDPL paper trail) | `laravel/docs/compliance/` + `OPEN-ITEMS.md` |
| Legacy app (history only) | repo root `README.md`, `REVIEW-FINDINGS.md`, `RENOVATION-PLAN.md`, `PERMISSION-MATRIX.md`, `PROJECT-*.md`, `DEPLOY.md`, `SECURITY-*.md` |

---

## 16. Legacy appendix

The procedural PHP + MySQL app at the repository root was the original system. It was security-
hardened on the `renovation` branch, reconciled row-for-row against the Laravel app
(`laravel/docs/RECONCILIATION.md`), and then retired when the Laravel app went live. Its data was
loaded through `legacy:import` and its identifiers map via the `legacy_id` columns. Do not edit,
deploy or reason from the root PHP files for product work. The full legacy mental model that used
to live in this file is in git history at commit `31f0bfb` and on the `renovation` branch.
