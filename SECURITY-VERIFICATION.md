# DMC — Security Verification Report

> **Adversarial runtime verification** that the renovation's headline security controls hold —
> i.e. the attacks the original review flagged as **Critical** (see [`REVIEW-FINDINGS.md`](REVIEW-FINDINGS.md))
> are now blocked. Run after this cycle's changes, so it doubles as a **regression check** on the
> security layer. Companion to [`PERMISSION-MATRIX.md`](PERMISSION-MATRIX.md) and [`DEPLOY.md`](DEPLOY.md) §6.

**Environment:** local WAMP — PHP **8.3.28**, MySQL **8.4.7**, full `Demo.sql` (~14.8k patients), app served
via `php -S 127.0.0.1:8765` with the renovation `renovation` branch.
**Date:** 2026-06-06. **Method:** unauthenticated/forged HTTP probes against the running app (read-only;
the one write-attempt — register — was checked against the DB and left no row).

> ⚠️ This was run against the **local** instance. Re-run the [`DEPLOY.md`](DEPLOY.md) §6 smoke tests against
> **staging/production** after deploy (with TLS, the production `php.ini`, and migrations 01–03 applied).

---

## Results

| # | Control (original Critical finding) | Test | Expected | Actual | Verdict |
|---|---|---|---|---|---|
| 1 | **Broken access control** — unauth read/write/delete of PHI (SEC-01/04/07–11) | Unauthenticated GET/POST to 10 action endpoints: patient **delete**, discharge-submit, registry search, **user update**, **user delete**, dashboard fragment, KPIs, shuffle, Excel export, ICD-10 typeahead | redirect to login / 401 / 403; **no data** | **All 10 → HTTP 302** to login, 24-byte bodies, **zero data leaked** | ✅ PASS |
| 2 | **SQL injection** — public reset-password sink (SEC-13) | `reset-password.php?token=' OR '1'='1` | no SQL error; no bypass | HTTP 200, **0 SQL errors**, bogus token not accepted | ✅ PASS |
| 3 | **No CSRF protection** (SEC-19) | Login `POST` with correct fields but **no `csrf_token`** | reject, no session | **HTTP 419** "CSRF token", no session set; valid token present in the form for legit logins | ✅ PASS |
| 4 | **Trivial admin takeover** via self-registration (SEC-02/03) | `register.php` `POST` with `position=0` (admin) + valid token | rejected; no account | "A valid position is required"; **no member row created** (DB checked) | ✅ PASS |
| 5 | Defense-in-depth ordering | `POST` to a clinical action endpoint with no session/token | auth check before action | HTTP 302 (auth redirect precedes the handler) | ✅ PASS |

**Also confirmed this cycle (code + earlier runtime testing):**
- **IDOR / object ownership (S1):** discharge/transfer/sign-off enforce `require_patient_access` /
  `require_consultation_access` (Admin / `manage_patient` / the patient's own consultant). The role matrix was
  runtime-tested earlier (Observer → 403 on writes; Resident-not-owner → 403; Resident-as-consultant → 200;
  Admin → 200) and every endpoint's rule is listed in [`PERMISSION-MATRIX.md`](PERMISSION-MATRIX.md).
- **Privilege escalation via user update (SEC-02):** `dmc-users-update.php` / `-delete.php` are `require_role([0])`
  (Admin only) — confirmed unauth-blocked in test #1.
- **Secrets:** no DB/SMTP credentials in tracked source (externalized to git-ignored `config.local.php`); the
  previously-exposed values were redacted from git history and rotated (Q11).
- **Prepared statements:** repo-wide grep shows 0 executed string-interpolated queries; test #2 confirms behaviourally.

---

## What this does **not** cover (still required at/after deploy)
- **TLS/HTTPS + HSTS** behaviour (the local server is plain HTTP; `.htaccess` forces HTTPS in production).
- **`Secure` cookie flag** in effect (requires HTTPS) — verify in the staging smoke test.
- Full per-role click-through of every clinical workflow (DEPLOY.md §6).
- Penetration testing by a third party — recommended for a production PHI system under HIPAA-equivalent / Saudi PDPL.
