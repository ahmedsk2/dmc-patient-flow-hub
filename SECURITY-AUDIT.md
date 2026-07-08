# Security Audit — DMC Patient-Flow Hub

> **Date:** 2026-06-25 · **Tool:** `security-pan-check /security-audit` (flagship full-stack) ·
> **Method:** static analysis + config/schema/migration reading + git-history inspection. **No live URL
> was fetched; no files were modified; secret values are masked.** This is a static-analysis aid, **not** a
> penetration test, certification, or compliance attestation. Items that cannot be proved from the repo
> are marked `tentative` with the dynamic tool that would confirm them.

## Scope & stack

Two apps share one MySQL backend:

| App | Path | Stack | Posture | Audit depth |
|---|---|---|---|---|
| **Legacy PHP** | repo root | PHP 8.3 procedural, mysqli, AdminLTE/jQuery, custom `guard.php`/`csrf.php` | **Being decommissioned** — broadly remediated since CLAUDE.md was written | Summary pass |
| **Laravel re-platform** | `laravel/` | Laravel 13 + Inertia 2 + Vue 3 + MySQL/Eloquent, dompdf, TOTP MFA | **Production-bound** (demo.dmc-im.com) | Deep pass |

**Domains run:** code (SAST + secrets), web (HTTP/TLS/headers), supply-chain (deps + CI/CD), database (MySQL),
api (OWASP API Top 10), threat-model (STRIDE + A04 Insecure Design).
**Not assessed (no signal in repo):** container (no Dockerfile), iac (no Terraform/k8s/CFN), ai-llm (no LLM
SDK), mobile (no Android/iOS). These appear as "not assessed", not as passing.

## Headline

**No critical or exploitable-from-the-internet finding in either app's current code.** This is a genuinely
security-conscious rebuild: every Laravel route sits behind `auth + session.timeout + mfa.enroll + pwd`;
**every** clinical mutation is server-authorized, transactional, attributed from the session (never the
request body), and written to an **append-only, SHA-256 hash-chained audit log**; all SQL is parameterized
(zero injection sinks reach user input); passwords are bcrypt, the MFA secret is AES-256-GCM encrypted, and
the password-reset flow uses Laravel's signed/expiring/single-use broker (the legacy md5-of-hash injectable
token is gone). The legacy app's historically-unauthenticated endpoints are now genuinely gated
(`require_role()` + `csrf_verify()` + prepared statements).

The residual risk is concentrated in **deployment posture and secure-design gaps**, not code defects:
transport-security enforcement, PHI-at-rest encryption, least-privilege DB credentials, auditing of PHI
*reads*, and CI/CD hardening.

**Counts (consolidated, de-duplicated):** Critical 0 · High 6 (4 Laravel + 2 legacy) · Medium 11 · Low 16 · Info/positive 10.

---

## Remediation tiers

### Tier 1 — Immediate (before/at production cutover, Laravel)

These are deploy-time switches and one config layer; high leverage, low effort.

- **H1 — Enforce transport security.** Set `SESSION_SECURE_COOKIE=true`, add HSTS + an HTTP→HTTPS
  redirect, and configure `TrustProxies` so Laravel sees HTTPS behind the TLS terminator. Today the
  app ships **no** HSTS, no HTTPS forcing, and `SESSION_SECURE_COOKIE` unset → the session cookie for a
  PHI app can travel over cleartext on any downgrade. *(merges SPC-WEB-002, SPC-WEB-008, SPC-CODE-002, SPC-TM-002)*
- **M3 — Production env defaults.** The deployed `.env` must set `APP_ENV=production`, `APP_DEBUG=false`,
  `LOG_LEVEL=warning`. `.env.example` ships `APP_DEBUG=true`/`LOG_LEVEL=debug` (with a comment telling you to
  flip them) — make the safe values the default and add a boot/CI assertion that aborts if
  `APP_ENV=production && APP_DEBUG=true`. With debug on, a query error renders the SQL + bound PHI to the
  browser. *(merges SPC-CODE-001, SPC-DB-007, SPC-API-004)*
- **H3 — Least-privilege DB credential + DB TLS.** Stop connecting as `root`. Create an app user with
  only `SELECT/INSERT/UPDATE/DELETE` on the app schema (no `DROP/ALTER/FILE/SUPER`), a **separate**
  migration/import account, and require + verify TLS on the connection (`MYSQL_ATTR_SSL_CA` +
  `ATTR_SSL_VERIFY_SERVER_CERT`). *(SPC-DB-002, SPC-DB-003; underpins SPC-TM-005)*
- **MFA default.** Default `mfa_enforcement` to at least `admins` for a PHI system so it cannot go live
  single-factor if an admin skips the Control-panel step. *(SPC-TM-002)*

### Tier 2 — Short-term (first sprint, Laravel)

- **H2 — PHI at rest.** Decide and **document** the at-rest control: InnoDB tablespace encryption (TDE) +
  encrypted backups at minimum; evaluate Laravel field-level `encrypted` casts for free-text name (pair with
  a blind-index column if exact-match search must survive). ~15k identifiable patients are plaintext today.
  *(SPC-DB-001, SPC-TM-007)*
- **H4 — Audit PHI reads.** The everyday board (`/patients`) and `/api/patients/quick-search` write **no**
  audit row, so a clinician browsing out-of-care patients is invisible. Log a lightweight
  `patients.view`/`quick_search` event (actor, scope, result count, MRN redacted), as the registry already
  does; consider enabling `log_record_opens` by default. *(SPC-TM-001)*
- **M1 — Security-header layer.** Add one global middleware (or `laravel/public/.htaccess`) setting CSP
  (prefer nonce-based), `X-Frame-Options/frame-ancestors`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy`, `Permissions-Policy` — mirror the legacy app's already-good `.htaccess`.
  *(SPC-WEB-001/003/004/005/006)*
- **M2 — Handover read object-scope (BOLA).** `GET /admissions/{admission}/handover` returns the handover
  narrative + 20 revisions for **any** admission id to any authenticated user; the interactive board scopes
  consultants to their own patients but this endpoint does not. Apply the same `canManageAdmission()`/scope
  check (or confirm cross-consultant read is intended and downgrade). *(SPC-API-001)*
- **M4 — Export rate/volume controls.** Exports (`/registry/export*`, `/statistics/export*`, `/reports/pdf`)
  are unpaginated and unthrottled; the annual booklet issues ~1,800+ queries. Move heavy exports to the
  existing queued-job pattern, add a row cap, throttle the routes, and raise a Security-panel alert on
  bulk/after-hours extraction (the departing-employee case). *(SPC-API-003, SPC-TM-003)*
- **M8 — CI/CD hardening.** Add `permissions: { contents: read }` to both workflows; pin every `uses:` to a
  full commit SHA (esp. third-party `shivammathur/setup-php`); drop the `|| true` that neuters
  `composer audit`; set `npm audit --audit-level=high`; add Dependabot for composer/npm/actions. *(SPC-SC-003/004/005/009)*

### Tier 3 — Medium-term (governance & defense-in-depth)

- **M5 — Segregation of duties.** A single admin (or one stolen admin session) can grant admin, delete
  users, change clinical KPI thresholds, and reverse discharges with only same-actor step-up. Add
  maker-checker / second-admin approval (or mandatory reason + real-time alert to other admins) for the
  highest-risk actions. *(SPC-TM-004)*
- **M6 — Object-level authz on assign/bed/long-term.** These are capability-gated, not owner-gated — likely
  intentional unit-wide actions; confirm the policy and document it, or add a scoped check. *(SPC-API-002)*
- **M7 — Import provenance.** Record an import-batch fingerprint (count + normalized-row hash + created
  admission ids, MRNs redacted) and tag created rows with the batch id for clean rollback. *(SPC-TM-006)*
- **Schedule `audit:verify`** nightly with alerting on failure (the hash chain is only as good as its
  verification). *(SPC-TM-009)*
- **DB-tier audit plugin** (MariaDB `server_audit` / MySQL Enterprise Audit) as a backstop to the app-level
  trail for privileged/direct access. *(SPC-DB-010)*
- **Data-governance appendix:** PDPL/HIPAA data-residency pinning (DB/backups/SMTP in-KSA), encrypted
  offsite backups + tested restore + retention, and an incident/breach-notification runbook. *(SPC-TM-010)*
- **Lower-risk items:** `throttle` on `POST /register`, `password.update`, and quick-search *(SPC-API-005,
  SPC-TM-008)*; ≥10-char CSPRNG MFA recovery codes *(SPC-CODE-004)*; `__Host-` session-cookie prefix
  *(SPC-WEB-009)*; `Cache-Control: no-store` on authenticated PHI responses *(SPC-WEB-015)*; prefer
  POST/opaque ids over MRN-in-querystring *(SPC-TM-011)*; switch `User`/`Admission` `$guarded=['id']` to
  explicit `$fillable` *(SPC-CODE-006)*.

### Tier 4 — Legacy app (decommissioning — only if it stays live during cutover)

- **H5** — remember-me cookies (`index.php:61/64/67`) set without `Secure`/`HttpOnly`/`SameSite` → use the
  options-array `setcookie()` form. *(SPC-WEB-010)*
- **H6** — vendored **PHPMailer 6.4.1** (reachable from the password-reset email flow) is stale; jQuery
  3.7.1 / Chart.js 4.4.x / Select2 4.0.13 / moment 2.30.1 are version-frozen but invisible to any SCA →
  drop-in upgrade PHPMailer; record vendored libs in an SBOM and run `retire.js`/`osv-scanner`. *(SPC-SC-001/002)*
- **M10** — MyISAM→InnoDB, missing FKs, missing indexes (MRN/consultant_id/current_location), mixed
  latin1/utf8mb3 charsets, int-vs-text MRN mismatch. **Fix migrations 03/04/05/11 already exist** but are
  unapplied (the `Demo.sql` dump reflects pre-migration state) → run them in a backed-up maintenance window.
  *(SPC-DB-004/005/006)*
- **M11** — legacy CSP allows `'unsafe-inline'` in `script-src`/`style-src` → migrate inline scripts to
  per-request nonces. *(SPC-WEB-011)*

### Local-machine hygiene (not a repo leak)

- **`Demo.sql`** (10.9 MB) is a **real-PHI** dump (patient names, MRNs, stray mobile numbers, diagnoses) in
  the working tree. **Verified: gitignored and never committed** (`git ls-files`/`git log --all` clean) — so
  it is **not** a VCS exposure, but it is local-disk PHI: keep it access-controlled, use a de-identified
  fixture for routine dev, and delete the real dump after the one-time legacy import. The previously-flagged
  `php_errorlog` is already gone from disk. `laravel/.env` (with a dev `APP_KEY`) is gitignored — ensure prod
  generates its own key and does not reuse this one. *(reconciled SPC-DB-011/012, SPC-CODE-003)*

---

## Positive controls (verified — preserve these)

- **No SQL injection sink** in the Laravel app — every `whereRaw/selectRaw/DB::raw/DB::statement` traced to a
  bound parameter, an int cast, or an internal constant *(SPC-DB-014)*.
- **No mass-assignment hole** — no `$request->all()`/`->only()` into a model; every write is a validated
  allow-list; `role` constrained `in:0,2,3,4,5`; attribution from `Auth::id()` *(API3 pass)*.
- **No SSRF / no unsafe upstream** — no endpoint fetches a user-supplied URL *(API7/API10 pass)*.
- **Auth/authz** — active-only login + bcrypt + TOTP MFA (replay-guarded, attempt-budgeted, never
  "remembered"), throttle keyed username+IP (avoids whole-hospital NAT lockout), enumeration-safe reset,
  self-registration creates **inactive non-admin** accounts only, step-up re-auth on delete/reverse/merge/
  admin-grant, idle+absolute session timeout *(SPC-TM-012)*.
- **Audit log** append-only + SHA-256 hash chain + ORM immutability guard + `audit:verify` *(SPC-DB-010, SPC-TM-012)*.
- **Exports de-identified** (no patient name; MRN as clinical id) with CSV formula-injection neutralized *(API/AC-6)*.
- **Supply-chain hygiene** — both lockfiles committed with SHA-512 integrity, frozen installs (`npm ci`/
  `composer install`), `.npmrc ignore-scripts=true`, no Poisoned-Pipeline-Execution surface *(SPC-SC-010)*.
- **Legacy app** now uses `guard.php` (`require_role`/`require_capability`/`require_patient_access`) +
  `csrf_verify()` + prepared statements; HTTPS-forcing `.htaccess` with HSTS/CSP/X-Frame/nosniff; env-based
  secrets; `eval()` dashboard and dev artifacts removed.

---

## Compliance mapping (coverage & honest "not assessed")

| Framework | What this audit covered | Notable gaps surfaced | Needs dynamic/runtime confirmation |
|---|---|---|---|
| **OWASP Top 10 2021/2025** | A01 BrokenAccess, A02 Crypto/Transport, A03 Injection, A04 InsecureDesign, A05 Misconfig, A07 Auth, A08 Integrity, A09 Logging | A02 (transport/at-rest), A04 (SoD, read-audit), A05 (headers/debug), A09 (PHI-read logging) | A01 BOLA reachability per role (Burp/ZAP) |
| **OWASP API Top 10 2023** | API1 BOLA, API2 Auth, API3 MassAssign, API4 Resource, API5 BFLA, API6 BusinessFlows, API7 SSRF, API8 Misconfig, API9 Inventory, API10 Upstream | API1 (handover read), API4 (exports) | Authenticated multi-role fuzzing (schemathesis/ZAP) |
| **OWASP ASVS** | V2 Auth, V3 Session, V4 Access, V5 Validation, V7 Crypto, V9 Comms | V3 (Secure cookie), V9 (HSTS/DB-TLS) | live header/TLS capture |
| **CWE** | 311/312, 319, 269/250, 614/1004/1275, 778, 639, 770/799, 209/532, 89(pass) | as per findings | — |
| **CIS MySQL Benchmark** | engine, charset, least-privilege, TLS, audit, network (from config/schema) | InnoDB/FK/index (legacy), least-priv, TLS, audit plugin | `SHOW GRANTS`, `Ssl_cipher`, `innodb_encrypt%`, `bind_address`, `SHOW PLUGINS` on a live authorized DB |
| **HIPAA Security Rule** | §164.312(a)(2)(iv) encryption, (b) audit, (e) transmission | at-rest encryption, PHI-read audit, transmission TLS | infra/host review |
| **PDPL (Saudi)** | Art.19 protection, data residency | residency/localization, breach-notification runbook | governance session |
| **SLSA / NIST SSDF** | build provenance, dependency hygiene, CI least-privilege | no SBOM/signing, unpinned actions, non-gating SCA | — |

**Not assessed (no signal):** container, iac, ai-llm, mobile.

---

## Canonical machine-readable summary

```json
{
  "tool": "security-pan-check",
  "command": "security-audit",
  "target": "C:/Users/ahmed/Downloads/DMC (legacy PHP root + laravel/)",
  "date": "2026-06-25",
  "stackDetected": {
    "languages": ["php", "javascript"],
    "frameworks": ["laravel-13", "inertia-2", "vue-3", "procedural-php (legacy)"],
    "datastores": ["mysql-8"]
  },
  "domainsRun": ["code", "web", "supply-chain", "database", "api", "threat-model"],
  "domainsNotAssessed": ["container", "iac", "ai-llm", "mobile"],
  "toolsUsed": ["pattern-fallback (Read/Grep/Glob)", "git history inspection", "manual STRIDE/DFD"],
  "counts": { "critical": 0, "high": 6, "medium": 11, "low": 16, "info": 10 },
  "topFindings": [
    "H1 transport security not enforced (no HSTS/HTTPS-redirect/Secure-cookie) — laravel",
    "H2 PHI plaintext at rest, encryption undocumented — laravel",
    "H3 app connects to MySQL as root + DB connection TLS not required — laravel",
    "H4 PHI reads (board/quick-search) not audited — laravel",
    "H5 legacy remember-me cookies set without Secure/HttpOnly/SameSite — legacy",
    "H6 stale vendored PHPMailer 6.4.1 reachable from reset flow — legacy"
  ],
  "note": "Static analysis finds candidates. Confirm transport/TLS, DB grants/encryption, and BOLA reachability dynamically against an AUTHORIZED staging host (testssl.sh, SHOW GRANTS, Burp/ZAP multi-role)."
}
```

## Coverage note — what static analysis cannot prove

Confirm against an **authorized** staging/prod environment (never production-without-authorization):
- **Live HTTP headers + TLS** actually emitted (shared hosting/CDN may strip/override) — `curl -sSI`,
  Mozilla Observatory, `testssl.sh`/SSL Labs.
- **DB runtime** — actual grants (`SHOW GRANTS FOR CURRENT_USER()`), TLS in use (`SHOW STATUS LIKE
  'Ssl_cipher'`), at-rest encryption (`SHOW VARIABLES LIKE 'innodb_encrypt%'`), `bind_address`, audit plugin
  (`SHOW PLUGINS`), and which legacy migrations are applied (`SHOW TABLE STATUS`).
- **BOLA reachability** (SPC-API-001/002) and the whole role→function matrix — authenticated multi-role
  testing with Burp/ZAP; `schemathesis` against the `/api/*` + export endpoints.
- **Dependency CVEs** for stale/vendored libs — `composer audit`, `npm audit`, `retire.js`, `osv-scanner -r .`.
- **Deployed `.env`** debug/secure-cookie posture — verify on the live host.
