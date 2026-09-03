# Record of Processing Activities (ROPA) — DMC Internal Medicine patient-flow hub

> **DRAFT — for review by the hospital's legal / data-protection officer and clinical governance; not legal advice.**
>
> Version 0.1 · 2026-09-03 · Prepared from the codebase as it stands (`laravel/` on `main`).
> Review owner: [PLACEHOLDER — DPO]. Next review: [PLACEHOLDER — date, at most annually or on any change to the activities below].

This register describes every processing activity the hub performs, in the sense required of a
controller under the Kingdom's Personal Data Protection Law and its Implementing Regulations
(the duty to keep a record of processing activities and to make it available to the competent
authority on request) [VERIFY ARTICLE — PDPL record-keeping duty and the Implementing Regulations'
required contents of the record]. Health data is "sensitive data" under the law
[VERIFY ARTICLE — definition of sensitive data including health data], so every activity below
that touches a patient row is flagged **SENSITIVE**.

The data-category column is built from the **actual schema** in
[`../../database/migrations/`](../../database/migrations/), cross-checked against
[`../DATABASE-AND-BEHAVIOR.md`](../DATABASE-AND-BEHAVIOR.md) and
[`../HANDOVER-COMPLIANCE.md`](../HANDOVER-COMPLIANCE.md). Nothing in the "security measures"
columns is aspirational: each entry names the file that implements it.

---

## 0. Legend

| Tag | Meaning |
|---|---|
| **[VERIFY]** | A legal or regulatory statement that must be confirmed against the current text of the law/regulation by the DPO or counsel. Article numbers, periods and thresholds are deliberately written in words, never asserted as fact. |
| **[NEEDS LEGAL CONFIRMATION]** | A retention period or obligation that the hospital's legal function must set; the document does not propose a number. |
| **[PLACEHOLDER]** | A name, contact, date or contractual reference the owner must fill in. |
| **SENSITIVE** | Health data or other sensitive-category data under the law [VERIFY]. |
| **GAP** | A control that does not exist yet (being built, planned, or recommended). |

---

## 1. Controller, roles and contacts

| Role | Holder | Contact |
|---|---|---|
| Controller (legal entity) | [PLACEHOLDER — hospital legal name, commercial/licence registration] | [PLACEHOLDER] |
| Data Protection Officer | [PLACEHOLDER] | [PLACEHOLDER] |
| Clinical owner of the hub | [PLACEHOLDER — Head of Internal Medicine] | [PLACEHOLDER] |
| System owner (IT / application) | [PLACEHOLDER] | [PLACEHOLDER] |
| Information-security lead | [PLACEHOLDER] | [PLACEHOLDER] |
| Vendor / maintainer of the application code | [PLACEHOLDER — engineering contact] | [PLACEHOLDER] |

Whether a DPO appointment is mandatory for this controller (a health-care provider processing
sensitive data at scale) must be confirmed [VERIFY — Implementing Regulations criteria for
mandatory DPO appointment].

---

## 2. Systems, processors and locations (the "where")

| Component | Provider / location | Role under the law [VERIFY] | Personal data it can see | Safeguard in place | Contract |
|---|---|---|---|---|---|
| Application + MySQL 8.4 (Docker) on one compute instance | Oracle Cloud Infrastructure, region **me-riyadh-1** (in-Kingdom) | Processor (infrastructure) | Everything (the live database and file sessions live here) | In-Kingdom region; origin accepts traffic only via Cloudflare [VERIFY firewall rule]; OS/volume encryption by the cloud provider [VERIFY default at-rest encryption applies to the boot/block volume] | [PLACEHOLDER — OCI DPA / order] |
| Audit-log archive bucket (S3-compatible object storage, WORM, seven-year lock) | OCI Object Storage, region defaults to **me-riyadh-1** (`config/services.php` → `AUDIT_S3_REGION`) | Processor (storage) | Audit rows only — actor names, IPs, actions, entity ids and `details` JSON (which can carry an MRN, e.g. `handover.read`) | Immutable retention rule; separate access keys (`AUDIT_S3_*`) | [PLACEHOLDER] |
| Cloudflare (DNS, reverse proxy, TLS termination, WAF) | Cloudflare, Inc. — **non-Saudi entity**; an in-Kingdom point of presence has been observed serving the site | Processor (transit) | **All HTTP traffic in clear text at the edge**, including PHI in page responses and POST bodies (URLs are PHI-free by design) | TLS 1.2 minimum at the edge; HSTS; proxied-only DNS records; [VERIFY — Cloudflare DPA, data-localisation / regional-services option, and whether edge decryption constitutes a transfer outside the Kingdom] | [PLACEHOLDER — Cloudflare DPA] |
| SMTP relay | [PLACEHOLDER — provider name], hosted in the **United States** | Processor (messaging) | Staff e-mail addresses, usernames, one-time codes, password-reset links, the aggregate monthly PDF, audit-integrity alert text | No patient PHI is sent by design (see A8); credentials stored encrypted (`settings.mail_password`, `App\Models\Setting` `encrypted` cast) | [PLACEHOLDER — relay DPA; cross-border transfer safeguard — see §4] |
| GitHub (source repository) | GitHub, Inc. — non-Saudi | Not a processor of personal data by design (code only) | None by design. Historical exposure: legacy DB/SMTP credentials were committed and have been rotated ([`../DEPLOY-LARAVEL.md`](../DEPLOY-LARAVEL.md) §2); the legacy working folder historically held a `Demo.sql` dump containing real PHI (per the root `CLAUDE.md`); it is not in the working tree and has no entry in git history as of this draft [VERIFY every local copy has been destroyed] | Private repository | [PLACEHOLDER] |
| Coolify (deployment orchestrator) | Self-hosted on the same OCI instance [VERIFY] | Internal tooling | Environment variables incl. DB credentials and `APP_KEY` | Host-local | n/a |
| Staff browsers | Hospital workstations / personal devices [VERIFY device policy] | End users | Rendered PHI; downloaded exports | MFA; session timeouts; no client-side PHI cache beyond the page | n/a |

---

## 3. Data inventory by table (schema-derived)

Columns are listed exactly as created by the migrations. "Category" uses the law's vocabulary in
words; **SENSITIVE** = health data or data revealing a sensitive characteristic [VERIFY].

### 3.1 Patient and clinical tables — **SENSITIVE**

| Table (migration) | Columns holding personal data | Category / sensitivity |
|---|---|---|
| `patients` (`2026_06_08_120003`, soft-delete `2026_06_14_010007`) | `mrn` (unique identifier), `name`, `gender`, `age`, `nationality`, `deleted_at` | Identity + demographics of a patient. **SENSITIVE by association** (every row is a hospital patient); `nationality` may be a special category in its own right [VERIFY]. |
| `admissions` (`2026_06_08_120004`, + `assigned_at`, soft-delete, check constraints) | `patient_id`, `bed`, `admitted_from`, `admit_date`, `current_location` (ER/Ward/ICU), `consultant_id`, `admitted_by`, `discharged_by`, `medical_discharge_date`, `discharge_date`, `discharge_to` (incl. LAMA / Absconded / Mortuary), `outcome` (**Alive/Dead**), `delay_reason`, `transfer_type`, `is_longterm`, `assigned_on`, `assigned_at`, `deleted_at`, `legacy_id` | Episode of care, location, **outcome incl. death**, treating consultant. **SENSITIVE**. |
| `admission_diagnoses` (`2026_06_08_120004`, unique `2026_06_09_000002`) | `admission_id`, `seq`, `icd10_code` | **Diagnoses (ICD-10)**. **SENSITIVE**. Combined with `tb_diagnoses` it reveals a tuberculosis classification — an infectious-disease flag, the most sensitive derived field in the system. |
| `consultations` (`2026_06_08_120005`, ledger columns `2026_08_21_000100`) | `mrn`, `patient_id`, `admission_id`, `patient_name`, `age`, `bed`, `current_location`, `consultation_date`, `requested_at`, `consultation_from`, `to_service`, `owning_specialty_id`, `indication` (JSON of reason ids), `other_indication` (free text), `status`, `consultant_id`, `entered_by`, `signoff_date`, `signed_off_at`, `signed_off_by`, `response_disposition`, `response_followup_needed`, **`response_note` (free-text clinical narrative)**, `deleted_at` | Referral and specialist response. **SENSITIVE**; free-text fields can contain anything a clinician types. |
| `consultation_followups` (`2026_08_21_000200`) | `consultation_id`, `followup_date`, `note` (free text), `author_id`, `created_at` | Daily specialist review log. **SENSITIVE**. Append-only. |
| `handovers` (`2026_06_11_000001`, checkpoints `2026_07_13_010000`) | `admission_id`, **`body` (free-text clinical narrative)**, `checkpoints` JSON (`vte_completed`, `ready_for_discharge`, `high_risk`, `needs_workup`, `workup_pending`, **`code_status` = full/DNR/DNI**), `updated_by`, `updated_at` | Current cross-cover note incl. **resuscitation status**. **SENSITIVE**. |
| `handover_revisions` (`2026_06_11_000001`) | `admission_id`, `body`, `checkpoints`, `author_id`, `created_at` | Full history of every handover version. **SENSITIVE**. Append-only. |
| `handover_signatures` (`2026_06_11_000001`) | `admission_id`, `from_consultant_id`, `to_consultant_id`, `revision_id`, `required_at`, `signed_at`, `signed_by`, `voided_at` | Who took over care and when. Staff data linked to a patient episode → **SENSITIVE by linkage**. |
| `notifications` (`2026_06_11_000001`, `resolved_at`, `admission_id`) | `user_id`, `type`, `admission_id`, **`payload` JSON — for `handover.incomplete` it holds `patient_name`, `mrn`, `from_name`, `to_name`**, `read_at`, `resolved_at`, `created_at` | In-app inbox; some rows carry patient identifiers. **SENSITIVE for those rows**. Retained deliberately (audit trail, see HANDOVER-COMPLIANCE §4). |

### 3.2 Staff / account tables — personal data of employees

| Table | Columns holding personal data | Category |
|---|---|---|
| `users` (`0001_01_01_000000`, `2026_06_08_120001`, `mfa_last_counter`, soft-delete, `tour_completed_at`, `can_coordinate_consultations`, e-mail unique restored `2026_07_11_000002`) | `username`, `name`, `full_name`, `email`, `email_verified_at`, `password` (bcrypt), `role`, `specialty_id`, `active`, `on_service`, `can_assign`/`can_add`/`can_manage`/`can_modify`/`can_coordinate_consultations`, **`mfa_secret` (encrypted at rest — Eloquent `encrypted` cast in `App\Models\User`)**, `mfa_recovery_codes` (hashed), `mfa_enrolled_at`, `mfa_last_counter`, `pass_exp_date`, `remember_token`, `tour_completed_at`, `legacy_id`, `deleted_at` | Identity, contact, role, authentication secrets. Confidential; secrets are security-critical. |
| `pending_registrations` (`2026_07_11_000001`) | `token`, `email`, `email_code_hash`, `email_*` counters/timestamps, `totp_secret` (encrypted), **`totp_recovery_codes` — held in plaintext until account creation (per the migration comment)**, `totp_confirmed_at`, `expires_at` (thirty-minute TTL) | In-progress sign-up. Short-lived; contains an authentication secret in clear for up to thirty minutes. |
| `trusted_devices` (`2026_07_19_000000`) | `user_id`, `selector`, `validator_hash` (SHA-256), `label`, `ip`, `expires_at`, `revoked_at`, `last_used_at` | Device trust for MFA skip (window `settings.mfa_trusted_device_hours`, default twenty-four hours; zero disables). IP address = personal data. |
| `sessions` table (`0001_01_01_000000`) **or** file sessions in `storage/framework/sessions` (production uses the file driver [VERIFY `SESSION_DRIVER`]) | `user_id`, `ip_address`, `user_agent`, `payload` (serialised session incl. flashed form input) | Session state. Lifetime `SESSION_LIFETIME` = one hundred and twenty minutes; app-level idle timeout `settings.idle_timeout_minutes` (default thirty). |
| `password_reset_tokens` | `email`, `token` (hashed by the framework), `created_at` | Reset flow. |
| `report_recipients` (`2026_06_14_000005`) | `email`, `active`, `added_by_id` | Recipients of the monthly PDF and integrity alerts. |

### 3.3 Audit, configuration and reference tables

| Table | Columns holding personal data | Category |
|---|---|---|
| `audit_log` (`2026_06_08_120006`, hash chain `2026_06_14_000004`, composite index) | `actor_id`, `actor_name`, `action`, `entity_type`, `entity_id`, **`details` JSON** (before/after values; `handover.read` stores the patient `mrn`; `patient.modify` can store names), `ip`, `prev_hash`, `row_hash`, `created_at` | Accountability record. Contains staff identity + IP on every row and **patient identifiers on some rows → treat as SENSITIVE**. Append-only at the ORM layer (`App\Models\AuditLog` `updating`/`deleting` hooks throw). |
| `setting_changes` (`2026_06_09_000003`) | `field`, `old_value`, `new_value`, `changed_by`, `created_at` | Configuration history (staff attribution). |
| `settings` (many migrations) | No personal data except `mail_username`, `mail_from_address`, and **`mail_password` (encrypted)**; operational flags incl. `log_record_opens`, `audit_shipped_through_id`, `audit_retention_years`, `failed_login_notify_threshold`, `idle_timeout_minutes`, `abs_timeout_minutes`, `mfa_trusted_device_hours`, `app_timezone` | Configuration; one credential. |
| `specialties`, `icd10`, `consultation_reasons`, `tb_diagnoses`, `countries` | None (reference data) | Public / non-personal. |
| `cache`, `jobs`, `migrations` | Transient framework state; the queued monthly-report job carries the generated PDF bytes while queued [VERIFY queue driver] | Framework. |

---

## 4. Register of processing activities

### 4.0 Index (one row per activity)

| # | Activity | Purpose (short) | Legal basis (in words) [VERIFY] | Data subjects | Data categories | Transfers outside the Kingdom | Retention (see [`DATA-RETENTION.md`](DATA-RETENTION.md)) | Owner |
|---|---|---|---|---|---|---|---|---|
| A1 | Admission management | Track each patient's episode from admission to discharge; assign consultants | Provision of health care by a licensed provider, minimum necessary; staff data under employment | Patients; staff | **SENSITIVE** clinical + demographics; staff identity | Cloudflare edge decryption [VERIFY] | Clinical record period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER — Head of IM] |
| A2 | Consultation ledger | Record referrals to/from other services, responses and daily follow-up | Same as A1 | Patients; staff | **SENSITIVE** incl. free-text response notes | Cloudflare edge [VERIFY] | Clinical record period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER] |
| A3 | Handover | Cross-cover narrative, checkpoints (incl. code status), consultant-to-consultant acknowledgement | Same as A1; patient-safety duty | Patients; staff | **SENSITIVE** incl. resuscitation status | Cloudflare edge [VERIFY] | Clinical record period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER] |
| A4 | Statistics and reporting | Census, KPIs, LOS, readmissions, mortality, per-consultant activity; exports; monthly PDF | Health-service management and quality; statistical purposes [VERIFY compatibility/statistics provision] | Patients (aggregated; row-level in registry exports); staff (named in per-consultant stats) | **SENSITIVE** at row level (registry exports); aggregate otherwise | Monthly aggregate PDF via US SMTP relay | Exports: user-held, short; PDF at recipients | [PLACEHOLDER] |
| A5 | User and account management | Authenticate and authorise staff; MFA; password lifecycle; roles/capabilities | Employment/contract; legal obligation to secure systems (national cybersecurity controls) [VERIFY] | Staff (incl. applicants in pending registration) | Identity, contact, credentials, IP, device trust | OTP / reset / username e-mails via US SMTP relay | Account life + post-departure period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER — System owner] |
| A6 | Audit logging (incl. break-glass, shipping, verification) | Accountability for every state change and PHI read/export; tamper evidence | Legal obligation / security controls; necessary for the health-service purpose [VERIFY — note legitimate interest is not available for sensitive data] | Staff; patients (identifiers in details) | Staff identity + IP; some patient identifiers | None (archive bucket in-Kingdom) | Six years local (`settings.audit_retention_years`); seven-year WORM copy | [PLACEHOLDER — Security lead] |
| A7 | Backups | Recover from loss or corruption | Same bases as the source data | All of the above | Everything | None planned (in-Kingdom) | Ninety days (planned) | [PLACEHOLDER — System owner] |
| A8 | Transactional e-mail | Deliver OTPs, password resets, username reminders, monthly PDF, integrity alerts | Employment/contract; security | Staff; report recipients | E-mail address, username, one-time codes, links; aggregate report | **Yes — US-hosted SMTP relay** | Provider retention [VERIFY]; local none | [PLACEHOLDER] |
| A9 | Application and security telemetry | Detect faults and attacks (application log, CSP violation reports, failed-login bursts) | Security obligation [VERIFY] | Staff (IP, username on `login.failed`); anonymous visitors (CSP reports) | IP addresses, usernames, URLs, error context | None | Log rotation (default fourteen days) | [PLACEHOLDER] |

### 4.1 A1 — Admission management (**SENSITIVE**)

| Field | Content |
|---|---|
| **Description** | Admit a patient (create `patients` row if new + `admissions` + `admission_diagnoses`), queue for assignment, assign (manual, self, shuffle, bulk reassign), transfer ward↔ICU / to another specialty / external, two-phase discharge, ICU discharge, reverse discharge, long-term flag, modify demographics/diagnoses, admin delete/restore, bulk import from spreadsheet, registry search. Endpoints and exact writes: [`../DATABASE-AND-BEHAVIOR.md`](../DATABASE-AND-BEHAVIOR.md) §5. |
| **Purpose** | Operational patient-flow management for the Internal Medicine unit: knowing who is in which bed under which consultant, and their progress to discharge. |
| **Legal basis (words)** | Processing of health data that is necessary for a licensed health-care provider to deliver and manage health services, limited to the minimum needed [VERIFY ARTICLE — health-data processing conditions; whether consent is additionally required from the patient, and whether the hospital's general admission consent / privacy notice covers this secondary system]. Staff attribution fields: performance of the employment relationship [VERIFY]. |
| **Data subjects** | In-patients of the unit (≈17,400 distinct patients; ≈37,700 admission episodes); clinical staff (≈331 accounts, ≈150 active). |
| **Data categories** | §3.1 `patients`, `admissions`, `admission_diagnoses` (+ derived TB flag). Staff: `users` identity. |
| **Source** | Entered by staff at admission (from the hospital's primary record system [VERIFY name]); bulk import spreadsheets; one-time migration from the legacy PHP application (`legacy:import`). |
| **Recipients** | Clinical roles (Admin, Registrar, Consultant, Resident); Observer role reads the board only. No external recipients. |
| **Processors / transfers** | OCI (in-Kingdom). Cloudflare edge decrypts every page and POST in transit [VERIFY — transfer classification and safeguard]. |
| **Retention** | No policy today; rows are never hard-deleted by routine flow (soft deletes). Period to be set by the applicable medical-records regulation [NEEDS LEGAL CONFIRMATION] — see DATA-RETENTION.md. |
| **Security measures (implemented)** | Mandatory TOTP MFA (`EnsureMfaEnrolled`, `MfaController`); role + capability checks on every endpoint (`EnsureAdmin`, controller authorisation); step-up re-authentication for reverse-discharge and delete (`RequireStepUp`); session-sourced attribution (`admitted_by`, `discharged_by`); CSRF on all writes; audit row on every state change and on registry search/export (`App\Support\Audit`); optional per-record open logging (`settings.log_record_opens` → `registry.open`); PHI never in URLs (search terms in POST body); soft deletes; DB transactions on multi-row writes; CSP + HSTS (`SecurityHeaders`); TLS 1.2+ at the edge. |
| **Gaps** | No encryption of PHI at rest inside MySQL (planned) **GAP**; no automated backup (being built) **GAP**; privacy notice to patients about this system [VERIFY — other workstream]. |
| **Owner** | [PLACEHOLDER — Head of Internal Medicine (clinical); System owner (technical)] |

### 4.2 A2 — Consultation ledger (**SENSITIVE**)

| Field | Content |
|---|---|
| **Description** | Book a consultation to a specialty (optionally linked to a patient/admission), track status new → active → ongoing → signed-off, daily follow-up ticks with optional note, sign-off with a structured response (`response_disposition`, `response_followup_needed`, `response_note`), reverse sign-off, coordinator reassignment, service handover sheet (screen + print), physician dashboard. |
| **Purpose** | The consulted service's own bookkeeping of referrals and their disposition (not a replacement for the hospital information system). |
| **Legal basis (words)** | As A1 [VERIFY]. |
| **Data subjects** | Patients referred; requesting and responding clinicians. |
| **Data categories** | §3.1 `consultations`, `consultation_followups`; `notifications` rows of type `consultation.assigned`. |
| **Source** | Entered by clinical staff; legacy import (≈1,283 historical rows per the ledger migration). |
| **Recipients** | Clinical roles; coordinator capability (`can_coordinate_consultations`) may see and modify all consults but never sign off or delete. **Printed handover sheets** are a physical output — see DATA-CLASSIFICATION.md. |
| **Processors / transfers** | As A1. |
| **Retention** | As A1 [NEEDS LEGAL CONFIRMATION]. Follow-ups are append-only. |
| **Security measures** | As A1; `consultation.*` audit actions incl. `status_change` with from/to; consultation delete is Admin-only. |
| **Gaps** | Free-text `response_note` / follow-up `note` can carry more than the minimum — clinical guidance on content [PLACEHOLDER — governance instruction]. |
| **Owner** | [PLACEHOLDER] |

### 4.3 A3 — Handover (**SENSITIVE**)

| Field | Content |
|---|---|
| **Description** | Free-text cross-cover note + six checkpoints per admission; every save appends an immutable revision; consultant-to-consultant moves create a signature request re-bound at signing to the revision actually read; soft same-day gate raises persistent reminders; break-glass read logging when enabled. Full lifecycle: [`../HANDOVER-COMPLIANCE.md`](../HANDOVER-COMPLIANCE.md). |
| **Purpose** | Patient safety at change of responsible consultant; evidencing who wrote what and who acknowledged it. |
| **Legal basis (words)** | As A1; additionally the provider's duty of continuity of care and clinical record-keeping [VERIFY]. |
| **Data subjects** | Patients; outgoing/incoming consultants; users who receive reminders. |
| **Data categories** | §3.1 `handovers`, `handover_revisions`, `handover_signatures`, `notifications` (`handover.*` incl. `patient_name` + `mrn` in payload). |
| **Source** | Clinical staff. |
| **Recipients** | All clinical roles can read handovers by design (cross-cover). |
| **Processors / transfers** | As A1. |
| **Retention** | Revisions and signatures are retained indefinitely by design (append-only). Period [NEEDS LEGAL CONFIRMATION]. |
| **Security measures** | As A1; `handover.update` / `handover.sign` / `handover.reassign_incomplete` audit rows; `handover.read` break-glass rows when `settings.log_record_opens` is ON (records the MRN); only the primary consultant or a manager may edit while a signature is pending. |
| **Gaps** | `log_record_opens` defaults **OFF** — reads of clinical narrative are unlogged unless an admin enables it [PLACEHOLDER — governance decision on default]. |
| **Owner** | [PLACEHOLDER] |

### 4.4 A4 — Statistics and reporting

| Field | Content |
|---|---|
| **Description** | Dashboard, statistics pages, physician consultation dashboard (aggregate; per-consultant named counts), registry (row-level PHI search, Admin-only), registry CSV/XLSX export, statistics XLSX/PDF export, audit-log CSV/XLSX export, scheduled monthly PDF e-mailed on the first of each month at 06:00 (`routes/console.php` → `GenerateMonthlyReport`). |
| **Purpose** | Service management, quality and capacity monitoring, monthly reporting to management. |
| **Legal basis (words)** | Health-service management as A1; statistical/quality purposes as a purpose compatible with collection [VERIFY ARTICLE — further-processing compatibility and any statistics/research provision]. |
| **Data subjects** | Patients (aggregated except in registry exports); consultants (named in activity counts). |
| **Data categories** | Aggregates of §3.1; **row-level PHI in registry exports**; staff names in per-consultant statistics; the monthly PDF is aggregate-only [VERIFY by reviewing a generated sample — `App\Jobs\GenerateMonthlyReport`]. |
| **Recipients** | Admin role only for registry/statistics/reports/exports/audit (`admin` route group, `routes/web.php`); `report_recipients` for the monthly PDF (hospital staff/management [PLACEHOLDER — list]). |
| **Processors / transfers** | Monthly PDF leaves the Kingdom via the US SMTP relay (aggregate content). Exports are downloaded to staff devices [VERIFY endpoint/device controls]. |
| **Retention** | Exports: no server copy; user-held. PDF: at recipients' mailboxes and at the relay [VERIFY relay retention]. |
| **Security measures** | Admin-only routes; `registry.search` audited with redacted filters; `registry.export` / `registry.export_xlsx` audited with mode, redacted filters and `row_count` (`RegistryController::logExport`); pre-export row-count advisory; PHI-free URLs. |
| **Gaps** | **Statistics exports and the audit-log export are not themselves audited** (no `Audit::log` in `StatisticsController` / `AuditController`) — recommended addition **GAP**; no DLP on downloaded files **GAP**. |
| **Owner** | [PLACEHOLDER] |

### 4.5 A5 — User and account management

| Field | Content |
|---|---|
| **Description** | Phased self-registration (e-mail OTP + authenticator confirmed before an account exists — `pending_registrations`), admin activation, login (username or e-mail), mandatory TOTP MFA with recovery codes, trusted-device MFA skip for a bounded window, password expiry after three months, admin-driven password reset e-mail and MFA reset, role/capability edits, deactivation, soft-delete/restore, profile edits, session idle/absolute timeouts, step-up re-authentication. |
| **Purpose** | Identity, authentication and authorisation of staff; enforcement of least privilege. |
| **Legal basis (words)** | Employment / contractual relationship; the controller's obligation to implement security controls (national cybersecurity controls for health-sector entities) [VERIFY]. |
| **Data subjects** | Staff; sign-up applicants. |
| **Data categories** | §3.2. |
| **Source** | The individual (registration, profile); administrators (roles, capabilities). |
| **Recipients** | Administrators (Control → Users). |
| **Processors / transfers** | OTP, reset and username-reminder e-mails traverse the US SMTP relay (e-mail address, username, code/link). |
| **Retention** | Accounts: deactivated on departure, retained for attribution (FKs are `nullOnDelete`; soft-delete keeps history) — period [NEEDS LEGAL CONFIRMATION]. `pending_registrations`: thirty-minute TTL (sweep not yet confirmed [VERIFY]). `trusted_devices`: window then expired rows remain. |
| **Security measures** | bcrypt passwords; `mfa_secret` encrypted with `APP_KEY`; recovery codes hashed; TOTP replay guard (`mfa_last_counter`); IP-keyed login/MFA throttle (`bootstrap/app.php`); failed-login burst notification (`security.failed_logins`, threshold `settings.failed_login_notify_threshold`); remember-me never applied to MFA users; `SessionTimeout` middleware audited as `session.timeout`; `login.success` / `login.failed` / `logout` / `password.change` / `mfa.device_trusted` / `mfa.device_revoked` / `user.*` audit rows; self-disable of MFA removed (admin-only reset). |
| **Gaps** | Recovery codes in `pending_registrations.totp_recovery_codes` are plaintext for up to thirty minutes (by design; short-lived) — accepted risk to be recorded [PLACEHOLDER]; no scheduled sweep of expired pending rows confirmed [VERIFY]. |
| **Owner** | [PLACEHOLDER — System owner] |

### 4.6 A6 — Audit logging, break-glass, shipping and verification

| Field | Content |
|---|---|
| **Description** | Every state change, login event, PHI search/export and (optionally) record open writes one `audit_log` row through the single writer [`../../app/Support/Audit.php`](../../app/Support/Audit.php) (actor and IP from the session/request, never from the client). Rows are chained: `prev_hash` = previous row's `row_hash`, `row_hash` = SHA-256 of the canonical row (`App\Models\AuditLog`). `audit:verify` walks the chain; `audit:verify-daily` runs at 02:30 and on failure writes `Log::critical`, notifies every active admin in-app (de-duplicated) and e-mails report recipients ([`../../app/Console/Commands/AuditVerifyDaily.php`](../../app/Console/Commands/AuditVerifyDaily.php)). `audit:ship` runs hourly, sends up to five thousand unshipped rows as NDJSON to the S3-compatible archive and advances `settings.audit_shipped_through_id` only after a successful upload ([`AuditShip.php`](../../app/Console/Commands/AuditShip.php)). `audit:prune` deletes only rows already shipped **and** older than `settings.audit_retention_years` (default six; zero refuses), is dry-run by default, requires `--confirm`, and is deliberately never scheduled ([`AuditPrune.php`](../../app/Console/Commands/AuditPrune.php), [`routes/console.php`](../../routes/console.php)). Admin viewer at `/audit` with CSV/XLSX export. |
| **Purpose** | Accountability, tamper evidence, breach investigation, clinical-governance reconstruction. |
| **Legal basis (words)** | The controller's obligation to keep records enabling accountability and to secure personal data; necessary for the health-service purpose. Legitimate interest cannot be relied on for the sensitive-data portion [VERIFY ARTICLE]. |
| **Data subjects** | Staff (every row); patients (identifiers inside `details` on some actions). |
| **Data categories** | §3.3 `audit_log`; `setting_changes`. |
| **Recipients** | Administrators; active admins and `report_recipients` receive integrity alerts. |
| **Processors / transfers** | In-Kingdom object-storage bucket (WORM, seven-year lock) — no transfer outside the Kingdom. |
| **Retention** | Local six years (config); off-box seven years immutable. See DATA-RETENTION.md for the rationale and the recommendation to schedule a prune **dry-run**. |
| **Security measures** | ORM-level append-only (update/delete hooks throw); transactional chain append with row lock; nightly verification with three alert channels; hourly off-box copy to immutable storage; separate archive credentials. |
| **Gaps** | Pre-chain rows written before the hash-chain migration have NULL hashes and are not verifiable until backfilled (`AuditVerify` warns) [VERIFY backfill was run in production]; the audit-log export is not itself audited **GAP**. |
| **Owner** | [PLACEHOLDER — Security lead] |

### 4.7 A7 — Backups

| Field | Content |
|---|---|
| **Description** | **Today:** no automated backup exists ([`../DEPLOY-LARAVEL.md`](../DEPLOY-LARAVEL.md) §5 lists it as a to-do). Manual `mysqldump ... | gzip > ~/pre-deploy-*.sql.gz` is taken on the host before each deploy (§7) and before `legacy:import`. **Being built:** automated, encrypted, off-box backups with ninety-day retention **GAP**. |
| **Purpose** | Restore after loss, corruption, ransomware or a failed migration. |
| **Legal basis (words)** | Inherits the bases of the source data; the controller's duty to protect data against loss [VERIFY]. |
| **Data subjects / categories** | Everything in §3. |
| **Recipients** | Operators only. |
| **Processors / transfers** | Target storage to be in-Kingdom [PLACEHOLDER — bucket/region once built]. |
| **Retention** | Ninety days (planned). Manual pre-deploy dumps: no rule today — see DATA-RETENTION.md. |
| **Security measures** | Planned: encryption in transit and at rest, restricted keys, restore test. Today: dumps sit unencrypted in the operator's home directory on the host [VERIFY] **GAP**. |
| **Owner** | [PLACEHOLDER — System owner]; target date [PLACEHOLDER]. |

### 4.8 A8 — Transactional e-mail

| Field | Content |
|---|---|
| **Description** | Outbound only: registration verification code (`RegistrationCodeMail`), username reminder (`UsernameReminderMail`), password-reset link (framework), monthly report (`MonthlyReportMail`, PDF attached), audit-integrity failure alert (plain text, `AuditVerifyDaily`). SMTP settings are runtime-configurable (Control → System) with the password encrypted (`settings.mail_password`). |
| **Purpose** | Account security flows; management reporting; security alerting. |
| **Legal basis (words)** | Employment/contract; security obligation [VERIFY]. |
| **Data subjects** | Staff; applicants; report recipients. |
| **Data categories** | E-mail address, username, one-time code, reset token link, aggregate PDF, integrity-check output (which can name audit row ids — no PHI). **No patient data by design** [VERIFY the monthly PDF]. |
| **Recipients / processors** | [PLACEHOLDER — SMTP relay provider], **hosted in the United States** → transfer of staff personal data outside the Kingdom. Safeguard required: contractual clauses / adequacy decision / minimum-necessary assessment per the cross-border transfer regulations [VERIFY ARTICLE — transfer conditions and whether a US-hosted relay for staff e-mail qualifies; alternative: an in-Kingdom relay]. |
| **Retention** | None locally (messages are not stored; `MAIL_MAILER=log` in non-production writes them to the application log). Provider retention [VERIFY]. |
| **Security measures** | TLS to the relay [VERIFY `mail_encryption` value in production]; encrypted stored credential; codes are single-use and expire; reset links are framework-hashed tokens. |
| **Owner** | [PLACEHOLDER] |

### 4.9 A9 — Application and security telemetry

| Field | Content |
|---|---|
| **Description** | Application log (`storage/logs/laravel.log`, `daily` channel recommended, default rotation fourteen days via `LOG_DAILY_DAYS`); CSP violation reports POSTed by browsers to `/csp-report` and **logged only, never stored** (`CspReportController`: document URI, violated directive, blocked URI, source file, line — truncated); failed-login burst notifications; data-quality digest at 07:00 (`dq:notify`). |
| **Purpose** | Fault detection, attack detection, data-quality monitoring. |
| **Legal basis (words)** | Security obligation [VERIFY]. |
| **Data subjects** | Staff (IP, username on failed logins); any visitor (CSP report URLs). |
| **Data categories** | IP addresses, usernames, URLs (PHI-free by design), exception context (could incidentally include request data — see DATA-CLASSIFICATION.md). |
| **Recipients** | Operators. |
| **Retention** | Log rotation; see DATA-RETENTION.md. |
| **Owner** | [PLACEHOLDER] |

---

## 5. Data-subject rights handling [VERIFY]

The law grants data subjects rights of access, correction, destruction and to be informed
[VERIFY ARTICLE — enumeration and exceptions for health data / medical records]. In this system:

| Right | How it would be fulfilled today | Gap |
|---|---|---|
| Access | Admin registry search by MRN + export (audited) | No patient-facing channel; must be routed through the hospital's medical-records office [PLACEHOLDER — procedure]. |
| Correction | Modify patient / admission (audited, `patient.modify`) | Clinical-record corrections need governance sign-off [PLACEHOLDER]. |
| Destruction | Soft delete only; hard delete Admin + step-up | Clinical retention obligations may override [NEEDS LEGAL CONFIRMATION]. |
| Information (privacy notice) | None in this system | Other workstream [PLACEHOLDER]. |

---

## 6. Change log

| Date | Version | Change | Author |
|---|---|---|---|
| 2026-09-03 | 0.1 | Initial draft from schema and code review | [PLACEHOLDER] |
