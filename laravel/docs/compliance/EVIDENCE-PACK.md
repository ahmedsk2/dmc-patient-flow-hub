# Audit-readiness evidence pack — DMC Internal Medicine patient-flow hub

> **DRAFT v0.1 · 2026-09-03.** Maps each control an external auditor will ask about to where it is
> satisfied in this repository and the production infrastructure, and how to verify it. Companion
> to the nine PDPL drafts in this folder and to [`OPEN-ITEMS.md`](OPEN-ITEMS.md). Frameworks in
> scope: **PDPL / SDAIA** (definite), **NCA ECC / DCC** (definite), **ISO 27001 / SOC 2 / CBAHI**
> (only if the hospital decides to pursue them). Legal citations are **proposed for counsel to
> verify** and are marked `[PROPOSED]`; nothing legal here is asserted as fact.

## 0. How to read this pack

| Status | Meaning |
|---|---|
| **IMPLEMENTED** | Control exists in code or infrastructure; the evidence column names the file, command or console page that proves it. |
| **PARTIAL** | Exists, with a named residual gap. |
| **DOC-ONLY** | Satisfied by a document (policy, register, runbook) that still needs owner/legal sign-off. |
| **OWNER** | Only the hospital can supply or sign this (names, contracts, decisions). |
| **GAP** | Not in place; tracked in §6. |

Paths are relative to `laravel/`. "Console" evidence (Cloudflare, OCI, GitHub, Coolify) is captured
as a dated screenshot or API export into the evidence folder the owner designates **outside the
repository** (the repo must hold no secrets, tokens or PHI).

## 1. System description and scope

> **Confirmed parties (2026-09-03, see [`CONFIRMED-FACTS.md`](CONFIRMED-FACTS.md)).** Controller:
> **Dammam Medical Complex**, under the **Eastern Health Cluster** (Saudi Health Holding Company) —
> public-sector ownership, registering entity for counsel to confirm. Primary processor: **the
> developer/operator company** (holds the code, OCI tenancy and domain; no controller–processor
> contract yet — top action). Sub-processors: OCI (Oracle Systems Limited, Riyadh), Cloudflare Free
> (US), SiteGround SMTP relay (US). **Daily system is still the legacy PHP app on `dmc-im.com`
> (SiteGround, US) in its original un-hardened build**; the Laravel app runs in parallel until cutover.

| Item | Evidence |
|---|---|
| What the system is, data held, hosting | [`../../CLAUDE.md`](../../CLAUDE.md) §1–§6 (repo root), [`ROPA.md`](ROPA.md) §1–§3, [`CONFIRMED-FACTS.md`](CONFIRMED-FACTS.md) |
| Data inventory and classification | [`DATA-CLASSIFICATION.md`](DATA-CLASSIFICATION.md) §3 (assets table), schema in `database/migrations/` |
| Processors and data flows | [`DPA-AND-TRANSFERS.md`](DPA-AND-TRANSFERS.md) §1; [`DPIA.md`](DPIA.md) data-flow section |
| Approximate volumes | ~17k patients, ~37k admission episodes, ~330 staff accounts ([`../../HANDOFF.md`](../../HANDOFF.md)) |

## 2. PDPL / SDAIA obligations → evidence

Article references are filled from the proposed-citation research (see §7) and stay `[PROPOSED]`
until counsel confirms them.

| # | Obligation (in words) | Article `[PROPOSED]` | Evidence | How to verify | Status |
|---|---|---|---|---|---|
| P1 | Lawful basis for processing health data as a health-services provider; purpose limitation | | `ROPA.md` activities A1–A6 (basis column); `PRIVACY-NOTICE.en.md` §legal basis | Read the basis column against counsel's confirmed article | DOC-ONLY |
| P2 | Transparency: privacy notice available to data subjects, in Arabic and English | | Route `GET /privacy` (`routes/web.php`, `LegalController`, `resources/js/Pages/Legal/`); texts `PRIVACY-NOTICE.en.md` / `.ar.md`; login-page link | Open `/privacy` on the live site; diff the page text against the approved notice | PARTIAL (draft text, markers open) |
| P3 | Data-subject rights: access, copy, correction, destruction, complaint | | `DPO.md` R6 workflow; HIM office as the verification point (owner) | Owner supplies the HIM procedure and a request log | OWNER |
| P4 | Data minimisation | | PHI-free URLs by design (`routes/web.php`: registry and merge are POST-only); exports admin-only (`admin` group); aggregate-only monthly PDF (`resources/views/reports/monthly-pdf.blade.php` carries no MRN or name) | `grep -n "Route::post('/registry'" routes/web.php`; inspect the PDF template | IMPLEMENTED |
| P5 | Accuracy | | Admin patient merge (`PatientMergeController`, step-up); daily data-quality digest (`dq:notify`, `DataQualityController`); check constraints migration `add_check_constraints` | Run `php artisan dq:notify --help`; open `/data-quality` as admin | IMPLEMENTED |
| P6 | Storage limitation / retention schedule | | `DATA-RETENTION.md`; `settings.audit_retention_years` (default six); `audit:prune --confirm` (never scheduled); soft deletes on patients, admissions, consultations, users | Read `routes/console.php` (no prune schedule); `php artisan audit:prune` dry run | PARTIAL (clinical-record period pending legal) |
| P7 | Security of processing: technical measures | | TLS 1.2+ and HSTS at Cloudflare; `app/Http/Middleware/SecurityHeaders.php` (nonce CSP, `frame-ancestors 'none'`, `no-store`); mandatory TOTP MFA (`EnsureMfaEnrolled`, `app/Support/Totp.php`); idle/absolute session timeout (`SessionTimeout`); step-up re-auth (`RequireStepUp`); `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE`; encrypted narratives (`app/Casts/EncryptedNarrative.php`, `ENCRYPTION-AT-REST.md`); proxies pinned to Cloudflare ranges (`bootstrap/app.php`); login/MFA/step-up throttles | `scripts/smoke.sh` header output; `php artisan route:list --middleware`; Cloudflare SSL/TLS page (min TLS); **graded external header/TLS audit 2026-09-03: Grade A** — [`evidence/sec-web-2026-09-03.md`](evidence/sec-web-2026-09-03.md) (nonce CSP, 1-year preloaded HSTS, TLS 1.3, Secure/HttpOnly/SameSite session cookie) | IMPLEMENTED |
| P8 | Security of processing: organisational and lifecycle measures | | CI gates: gitleaks, Semgrep, `composer audit` gate, `npm audit`, Vitest/PHPUnit (`.github/workflows/laravel-ci.yml`, `docs/CI.md`); GitHub secret scanning + push protection + Dependabot; SHA-pinned actions; `RELEASE-CHECKLIST.md`; **the four CI jobs are required, admin-enforced status checks on `main`** (applied and read back 2026-09-03; `main` accepts pull requests only); Pint style gate and Vitest coverage thresholds in CI | `gh run list --workflow=laravel-ci.yml`; `gh api repos/ahmedsk2/dmc-patient-flow-hub/branches/main/protection`; GitHub → Settings → Code security (screenshot) | IMPLEMENTED |
| P9 | Restrict health-data access to the minimum staff; extra controls for health data | | Roles + capability flags (`app/Models/User.php`, `DATABASE-AND-BEHAVIOR.md` §4); `admin` route group; Observer read-only; `log_record_opens` break-glass logging; handover reads audited (`HandoverController`) | `/control` users table export; `php artisan route:list` for the admin group | IMPLEMENTED (default of `log_record_opens` is an owner decision) |
| P10 | Personal-data breach detection, response and notification | | `INCIDENT-RESPONSE.md` (runbook, SEV levels, notification table); detection tooling: `audit:verify-daily`, `/csp-report` sink, `/health`, `backup:verify` alerts | Table-top exercise record (owner); `php artisan audit:verify` output | DOC-ONLY + tooling |
| P11 | Data protection impact assessment | | `DPIA.md` | Signed and dated by the DPO/governance | DOC-ONLY |
| P12 | Designation of a personal-data protection officer | | `DPO.md` template; published contact on `/privacy` | Designation letter (owner) | OWNER |
| P13 | Record of processing activities | | `ROPA.md` | DPO review and sign-off | DOC-ONLY |
| P14 | Registration with the competent authority / national platform | | — | Registration confirmation (owner) | OWNER |
| P15 | Cross-border transfer conditions and safeguards | | `DPA-AND-TRANSFERS.md`: OCI Riyadh (in-Kingdom), Cloudflare edge (transit), US SMTP relay (staff data) | Signed DPAs and transfer assessments (owner/legal) | PARTIAL |
| P16 | Controller–processor contracts and due diligence | | `DPA-AND-TRANSFERS.md` §processor table | Executed agreements on file (owner) | OWNER |
| P17 | Accountability: tamper-evident audit trail of writes and PHI reads | | `app/Support/Audit.php`, `app/Models/AuditLog.php` (sha256 chain `prev_hash`/`row_hash`); viewer + export (`AuditController`); nightly `audit:verify-daily`; hourly `audit:ship` to in-Kingdom bucket `dmc-audit-log` | `php artisan audit:verify`; OCI bucket object listing (console) | IMPLEMENTED |
| P18 | Availability and integrity: backups, restore capability | | `BACKUP-AND-RESTORE.md`; `scripts/backup/db-backup.py`, `db-restore-drill.sh`; `backup:verify` (06:30); nightly encrypted dump to in-Kingdom bucket `dmc-db-backups`; drill log §8 | Latest `LATEST.json` heartbeat; drill log entry with measured RTO | IMPLEMENTED (retention period pending legal) |
| P19 | Destruction / secure disposal | | Soft deletes + admin hard delete with step-up and audit row; `DATA-RETENTION.md` §5; bucket lifecycle rules; legacy dump shredding (`BACKUP-AND-RESTORE.md` §2.7) | Owner confirms inventory of legacy dump copies | PARTIAL |
| P20 | Secrets management and key custody | | No secrets in repo (`.gitignore`, gitleaks, push protection; history purged); `APP_KEY` escrow by owner (`ENCRYPTION-AT-REST.md` §3); Coolify env store | Escrow record (owner); `gitleaks detect` clean run | IMPLEMENTED |

## 3. NCA ECC / DCC → evidence

Control identifiers to be inserted from the framework research (§7). Evidence families already
available, by domain:

| Domain | Evidence available today |
|---|---|
| Cybersecurity governance, roles | `DATA-CLASSIFICATION.md` §5 responsibilities (owner to name people); `SECURITY.md` disclosure policy |
| Asset and data classification, labelling | `DATA-CLASSIFICATION.md`; labelling of exports is a **GAP** |
| Identity and access management | Mandatory MFA, roles/capabilities, step-up, session timeout, account deactivation in Control → Users, failed-login threshold setting |
| Privileged access | `admin` route group; host `sudo` restricted to the owner's SSH key (OCI); Coolify dashboard internal-only |
| Cryptography | TLS at edge; AES-256-CBC + HMAC narrative encryption under `APP_KEY`; encrypted MFA secrets and SMTP password; encrypted backups with separately held key |
| Network security | Origin accepts only Cloudflare ranges; Cloudflare WAF, edge rate-limit, geo-challenge on auth pages; `trustProxies` pinned |
| Secure development and change | CI gates; branch protection (no force-push); `RELEASE-CHECKLIST.md`; build-reproducibility check |
| Vulnerability and patch management | `composer audit` gate, `npm audit`, Dependabot; host patching record (owner) |
| Logging and monitoring | Hash-chained audit log, hourly off-box shipping, nightly verification; `/health`; scheduler heartbeat; CSP report sink; `LOG_LEVEL=warning` daily channel |
| Backup and recovery | §2 P18 |
| Incident management | `INCIDENT-RESPONSE.md` |
| Cloud and third parties | `DPA-AND-TRANSFERS.md`; OCI region and encryption facts (§7) |
| Physical security | Inherited from OCI (provider attestation, owner to collect) |

## 4. ISO 27001 / SOC 2 / CBAHI

Not started. If pursued: ISO 27001 Annex A and SOC 2 Trust Services Criteria map onto §2–§3 evidence
families; CBAHI's information-management standards need the hospital's accreditation lead. Decide
first, then extend this pack.

## 5. Evidence-collection procedures

Run from `laravel/` unless noted. Capture output with date and commit hash.

```bash
git rev-parse HEAD                                  # code version under audit
php artisan audit:verify                            # audit chain integrity
php artisan audit:prune                             # dry run: shows retention window, deletes nothing
php artisan route:list --middleware                 # proves the auth/admin/stepup pipeline
gh run list --workflow=laravel-ci.yml --limit 5     # CI gates green
gh api repos/ahmedsk2/dmc-patient-flow-hub/branches/main/protection   # branch protection
bash scripts/smoke.sh https://dmc-new.towardpcc.com # headers, /up, /health, asset manifest
```

Console captures (owner or with owner present): Cloudflare SSL/TLS + WAF + rate-limit pages; OCI
bucket lifecycle rules for `dmc-db-backups` and `dmc-audit-log`; OCI boot/block volume encryption
status; GitHub code-security settings; Coolify environment-variable list **with values hidden**.

## 6. Gap register (compliance-relevant)

| # | Gap | Source | Owner | Target |
|---|---|---|---|---|
| G1 | ~~Statistics exports, audit-log exports and report PDFs write no audit row~~ **CLOSED 2026-09-03**: `statistics.export.{xlsx,pdf}`, `audit.export.{csv,xlsx}`, `report.pdf.{annual,consultant,governance,monthly,download}` each write one PHI-free `audit_log` row (tests in `StatisticsExportTest`, `AuditViewerTest`, `GovernancePdfTest`, `ConsultantScorecardTest`, `MonthlyReportMailTest`) | `StatisticsController`, `AuditController`, `ReportsController` | engineering | done |
| G2 | ~~Exports and printed sheets carry no classification label~~ **CLOSED 2026-09-03**: download filenames carry `SECRET-` (registry, audit-log, governance PDF) or `CONFIDENTIAL-` (statistics, annual/monthly/consultant PDFs); every PDF template has a fixed bilingual footer; the monthly e-mail subject is prefixed; the printable census and handover sheet print a "SECRET — Patient data / سري — بيانات مرضى" footer | `DATA-CLASSIFICATION.md` §4 | engineering | done |
| G3 | Structured PHI columns (names, MRN, diagnoses) rely on volume encryption, not column encryption | `ENCRYPTION-AT-REST.md` §2 | governance decision | |
| G4 | Cloudflare edge decryption: legal classification and contractual localisation unresolved | `DPA-AND-TRANSFERS.md`, DPIA R5 | legal | |
| G5 | US-hosted SMTP relay carries staff e-mail/OTPs outside the Kingdom | `ROPA.md` A5 | legal + engineering | |
| G6 | Clinical-record, audit-log and backup retention periods unconfirmed | `DATA-RETENTION.md` | legal | |
| G7 | Required status checks on `main` not configured; SSH IP allow-list deferred | `docs/CI.md`, `HANDOFF.md` | owner | |
| G8 | Legacy PHI dump copies not inventoried / destruction unconfirmed | `DATA-CLASSIFICATION.md` §3 | owner | |
| G9 | Named roles (DPO, security lead, system owner, clinical data owner) not designated | all drafts | owner | |
| G10 | **GitHub repository is public** (owner-accepted, time-boxed for free CI during development) | verified 2026-09-03 | owner | make private before go-live |
| G11 | **Live daily system `dmc-im.com` is the original un-hardened legacy build over real PHI on a US host** (all systemic defects live) | verified 2026-09-03 | owner | before / at cutover |
| G12 | **No controller–processor contract** between the hospital and the operator company | CONFIRMED-FACTS A5 | owner + legal | top priority |
| G13 | US SMTP relay (SiteGround) + Cloudflare Free carry a cross-border transfer without SCCs/TRA on file | CONFIRMED-FACTS B5/B6 | legal + engineering | |
| G14 | ~~Laravel session cookie lacks the `__Host-` prefix~~ **CLOSED and DEPLOYED 2026-09-03 (`a4dd4bd`)**: `config/session.php` prefixes the cookie with `__Host-` whenever `SESSION_SECURE_COOKIE` is true; `scripts/smoke.sh` now verifies the live `/login` cookie is `__Host-`, Secure, HttpOnly and host-only (15/15 on the deploy) | `evidence/sec-web-2026-09-03.md` SPC-WEB-001 | engineering | done |
| G16 | **Production-readiness 2026-09-03 — morning: BLOCKED 58/100** (emphasis 70; 2 Critical, 19 High); **evening re-score after the fixes: NEEDS FIXES 62/100, 0 Critical, 14 High** (emphasis 72) — [`evidence/prod-ready-2026-09-03-rescore.md`](evidence/prod-ready-2026-09-03-rescore.md) has the delta table, the auditor-variance notes and the ranked open items. **Closed the same day (PR #7, deployed as `3fbbd73`, branch rule applied):** CICD-04 (four required, admin-enforced status checks on `main`), SEC-04 + PERF-07 (`SESSION_DRIVER`/`CACHE_STORE=database`, verified live), RES-01 (SMTP timeout), I18N-02 (app-clock "today", regression-tested), UX-04 (labelled Login/Admission forms, axe-clean), CFG runtime parity (CI on PHP 8.3 / MySQL 8.4), TST-04 (Pint gate), Vitest coverage floor. **Still open:** CICD-08 (manual smoke/rollback), PHP coverage floor, OBS-01/03/04/05, DATA-02/04, OPS-02/06, CMP-03/06, G14 `__Host-` prefix. | [`evidence/prod-ready-2026-09-03.md`](evidence/prod-ready-2026-09-03.md) | engineering + owner | re-score the affected categories |
| G15 | **Legacy daily site `www.dmc-im.com` graded F**: no HSTS, `PHPSESSID` without Secure/HttpOnly/SameSite, canonical redirect passes through plaintext HTTP (SSL-strip / session-hijack path over live PHI) | `evidence/sec-web-2026-09-03.md` Host 2 | owner (SiteGround `.htaccess` fixes listed in the report; or deploy the hardened `renovation` build) | before / at cutover |

## 7. Proposed legal and framework citations

Held in [`PROPOSED-CITATIONS.md`](PROPOSED-CITATIONS.md): every legal marker in the nine drafts
mapped to a proposed citation, its source URL and a confidence rating, plus the findings that change
the drafts and the items no source could resolve. Method: the official SDAIA English texts (PDPL,
Implementing Regulations, Transfer Regulation, SCCs, BCR guidelines, DPO rules, National Register
rules, breach guide, transfer risk guideline) were downloaded and read locally; NCA and ministry PDFs
and Cloudflare/Oracle documentation were read directly; secondary law-firm sources only where
flagged. The highest-stakes PDPL rows were spot-checked line by line against the saved primary
texts on 2026-09-03. Everything there remains `[PROPOSED]` until counsel signs it off.
