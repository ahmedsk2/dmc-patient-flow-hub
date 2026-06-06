# DMC Renovation — Deployment Runbook

> Companion to [`PROJECT-TRACKER.md`](PROJECT-TRACKER.md). This is the **ordered, do-this-at-deploy**
> checklist for shipping the `renovation` branch to the live PHP 8.3 server. Everything here was
> deferred from the code batches because it touches production credentials, the database, or the
> server config. **Nothing in the renovation has been runtime-tested** (no local PHP), so treat the
> first deploy as a supervised change with a tested rollback.

---

## 0. Before you touch production

- [ ] **Take a verified, restorable database backup** (and confirm you can restore it to a scratch DB).
- [ ] Deploy to a **staging copy first** if at all possible — this is a large change set across security,
      data integrity and clinical workflow. Run the smoke tests (§6) on staging before production.
- [ ] Have a **rollback plan**: the previous code revision + the pre-deploy DB backup. The migrations in
      §3 are additive (new tables/columns/indexes + an engine change); note that the MyISAM→InnoDB
      conversion in migration 03 is **not trivially reversible** on a large table, so the DB backup is
      your rollback for that step.

## 1. Rotate the exposed credentials (treat as compromised)

The DB and SMTP passwords were hard-coded in tracked source before Batch 1 and must be considered leaked.

- [ ] Rotate the **MySQL** password for the app DB user.
- [ ] Rotate the **SMTP** password for the `info@dmc-im.com` mailbox.
- [ ] Do NOT put the new secrets in tracked files — they go in `config.local.php` or env vars (§2).

## 2. Application configuration (secrets)

Secrets now load from `config.local.php` (git-ignored) or environment variables; `config.php` is the loader.

- [ ] Copy `config.local.sample.php` → `config.local.php` on the server and fill in the **rotated** DB +
      SMTP credentials. (Alternatively set the `DMC_DB_*` / `DMC_SMTP_*` environment variables.)
- [ ] Confirm `config.local.php` is present on the server and is **not** world-readable.
- [ ] Confirm `config.local.php` is NOT in the deployed git tree / not served (it is git-ignored and
      blocked in `.htaccess`).

## 3. Database migrations (run once each, in order, AFTER the backup)

Run from `migrations/` — e.g. `mysql <dbname> < migrations/01-audit-log.sql`. See `migrations/README.md`.

- [ ] `01-audit-log.sql` — creates the `audit_log` table (audit trail). Until applied, `audit_log()`
      degrades gracefully (logs to the PHP error log; never breaks the action).
- [ ] `02-password-resets.sql` — creates `password_resets` and widens `members.member_password` to
      `varchar(255)`. **Required** before the new password-reset flow works.
- [ ] `03-innodb-and-indexes.sql` — converts the clinical/lookup tables MyISAM→InnoDB and adds indexes.
      **Required** for the transaction rollbacks (transfers/imports) to be atomic. Briefly locks
      `picupatients` during the engine conversion — run in a low-traffic window.
- [ ] After 03, spot-check: `SHOW TABLE STATUS` shows `picupatients` / `picupatients_temp` as `InnoDB`,
      and `SHOW INDEX FROM picupatients` lists the new MRN/consultant/location indexes.

## 4. Server / PHP configuration

- [ ] Install / confirm a valid **TLS certificate**. The renovation assumes HTTPS:
      - `.htaccess` force-redirects HTTP→HTTPS and sends **HSTS** — without a valid cert the site will
        be unreachable, so install the cert FIRST (or temporarily relax `.htaccess` while testing).
      - Session cookies are set `Secure` (see below), so they are only sent over HTTPS.
- [ ] Deploy the repo **`php.ini`** (or merge its directives into the server's php.ini):
      - `session.cookie_httponly=1`, `session.cookie_secure=1`, `session.cookie_samesite=Lax`,
        `session.use_strict_mode=1`, `session.gc_maxlifetime=3600`
      - `display_errors=Off`, `log_errors=On`  ← errors must go to the log, never the page
      - `mysqli.max_links=200`
- [ ] Confirm PHP **8.3** with the `mysqli`, `json`, `mbstring`, and `openssl` extensions enabled.
- [ ] PHP 8.3 mysqli error mode: the code now sets `mysqli_report(MYSQLI_REPORT_OFF)` in `dbconnect.php`
      (Batch 16). No action needed, but be aware the app relies on this — do not re-enable strict mysqli
      exceptions without migrating the error handling.
- [ ] Confirm the PHP process can **write to the error log** path (audit fallback + server-side error
      logging depend on it).

## 5. Deploy the code

- [ ] Deploy the `renovation` branch.
- [ ] Confirm the blocked file types are not served: requesting `/Demo.sql`, `/CLAUDE.md`,
      `/config.local.php`, `/.git/config` should all be denied (403/404) by `.htaccess`.
- [ ] Confirm the deleted debug endpoints are gone: `/reset-testcount.php` and `/test-trans.php` → 404.

## 6. Post-deploy smoke tests (do these before announcing "live")

Sign in as test users of each role where possible. Watch the PHP error log alongside.

**Auth & session**
- [ ] Log in with valid creds; log in with bad creds (lockout/`active=0` behaviour as expected).
- [ ] After login, session cookie has `Secure`, `HttpOnly`, `SameSite=Lax` (check dev-tools).
- [ ] `session_regenerate_id` on login (session id changes after authenticating).
- [ ] Password reset end-to-end: request reset → email arrives with an **https `?token=` link** →
      link sets a new password → token is single-use (reusing it fails) → old `?key=&reset=` style
      links no longer work.
- [ ] Admin "send reset to user" still works (control panel).

**CSRF**
- [ ] A normal action submits fine (token present). A forged POST without the token is rejected (419).

**Clinical actions — each must (a) show a confirmation with patient name+MRN, (b) only update the UI
on confirmed success, (c) be written atomically, (d) appear in `audit_log`):**
- [ ] New admission (create patient) — invalid age / bad date are rejected server-side.
- [ ] Ward discharge, ICU discharge, complete (close-file) discharge — confirm dialog shows the right
      patient; on a forced server error the row is NOT silently marked done.
- [ ] Transfer to specialty (and the ICU sub-case) — patient ends up on exactly one active record
      (not duplicated, not lost). Verify the transaction by simulating a mid-transfer failure on staging.
- [ ] Reverse discharge — re-activates the correct patient.
- [ ] Delete patient / delete consultation (Admin) — confirm dialog shows identity; row only disappears
      on confirmed success.
- [ ] Inline list edits — green flash only on confirmed save; editing **MRN or patient name** prompts an
      identity-change confirm and reverts on cancel.
- [ ] Consultation: add, sign-off, undo-sign-off, undo-discharge (48h views).
- [ ] Old-patient import: add to staging, then "Confirm Patients" — each patient moves to the live table
      and is removed from staging; a forced failure leaves the patient in staging (not lost).

**Reads / reports**
- [ ] Active list, new admissions, consultations, 48h views, registry search, statistics pages, dashboard
      all load without errors in the log.
- [ ] Exports (Excel/CSV/print) still produce output.

**Audit**
- [ ] `SELECT * FROM audit_log ORDER BY id DESC LIMIT 20` shows the actions you just performed, with the
      correct actor (from the session, not client input).

## 7. Known follow-ups still open (see PROJECT-TRACKER.md "Decisions needed")

These are intentionally **not** done and need clinical/product input before they are safe to implement:
statistics sargability + the `MONTH(DISDATE)`/`YEAR(ADMDATE)` cross-column anomalies (CLIN), server-side
object-ownership authorization (permission model re-confirmation), the canonical "active patient"
definition for de-duplication, soft-delete policy, the UI/UX overhaul, CSP + removing `eval()`
(needs the inline-JS refactor), and the PHPExcel→PhpSpreadsheet swap (needs Composer).
