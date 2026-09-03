# Record of Processing Activities (ROPA) — DMC Internal Medicine patient-flow hub

> **Confirmed inputs (2026-09-03) — see [`CONFIRMED-FACTS.md`](CONFIRMED-FACTS.md).** Controller: **Dammam Medical Complex**, under the **Eastern Health Cluster** (Saudi Health Holding Company); public-sector ownership, exact registering entity for counsel to confirm. Primary processor: **the developer/operator company** (holds the code, the OCI tenancy and the domain) — **no controller–processor contract exists yet (top action)**. DPO **not yet appointed** (treated as mandatory). The daily production system is still the **legacy PHP app on `dmc-im.com` (SiteGround, United States), original un-hardened build**; the Laravel app runs in parallel until cutover. Review: annual (next 2027-09-03), owner interim until a DPO is named. Legal citations are **[PROPOSED]** — see [`PROPOSED-CITATIONS.md`](PROPOSED-CITATIONS.md).

> **DRAFT — for review by the hospital's legal / data-protection officer and clinical governance; not legal advice.**
>
> Version 0.1 · 2026-09-03 · Prepared from the codebase as it stands (`laravel/` on `main`) and from
> the live legacy daily site as verified read-only on 2026-09-03.
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
| **[PLACEHOLDER]** | A name, contact, date or contractual reference the owner must fill in. `[COMPANY LEGAL NAME]` is the same kind of marker for the operator company's registered name and commercial registration. |
| **[PROPOSED]** | A citation or vendor fact researched and proposed for counsel to verify — never asserted as settled. See [`PROPOSED-CITATIONS.md`](PROPOSED-CITATIONS.md). |
| **SENSITIVE** | Health data or other sensitive-category data under the law [VERIFY]. |
| **GAP** | A control that does not exist yet (being built, planned, or recommended). |

---

## 1. Controller, roles and contacts

| Role | Holder | Contact |
|---|---|---|
| **Controller** (legal entity) | **Dammam Medical Complex (DMC)**, Dammam, Eastern Province — under the **Eastern Health Cluster**, a subsidiary of the **Saudi Health Holding Company** (public-sector ownership). Which entity registers with the competent authority and signs as controller, and whether it is a "Public Entity" under the law, is reserved for counsel [VERIFY — registering entity and public-entity status] | [PLACEHOLDER] |
| **Processor** (primary) | **[COMPANY LEGAL NAME] — the developer/operator company**, together with the individual developer/owner: owns the application code, holds the OCI tenancy and the domain, and hosts, operates, develops and supports the hub with full access to the data. DMC staff are its users; the hospital does **not** run the platform | [PLACEHOLDER — COMPANY LEGAL NAME, Commercial Registration No., city] |
| **Controller–processor contract** | **None exists yet.** Signing one, with the Implementing Regulation Art. 17 minimum content [PROPOSED], is the top action item — [`DPA-AND-TRANSFERS.md`](DPA-AND-TRANSFERS.md) A0 | — |
| Data Protection Officer | **Not appointed yet**; treated as mandatory because the core activity is processing sensitive health data [PROPOSED — see PROPOSED-CITATIONS.md Finding 5] | [PLACEHOLDER] |
| Clinical owner of the hub | Head of Internal Medicine, DMC — [PLACEHOLDER — NAME] | [PLACEHOLDER] |
| Staff-data owner | DMC hospital administration — [PLACEHOLDER — NAME/OFFICE] | [PLACEHOLDER] |
| System owner (application) | The developer/owner — **on the processor side**, not hospital IT | [PLACEHOLDER] |
| Information-security lead | The developer/owner — **on the processor side** | [PLACEHOLDER] |
| Approving body | DMC clinical governance / quality committee — [PLACEHOLDER — NAME] | [PLACEHOLDER] |

Note that the roles above are **split across two organisations**: the hospital is accountable as
controller for everything in this register, while every technical control it depends on is
implemented and operated by the processor. Until the Art. 17 contract exists, none of the
processor's obligations is written down. Whether a DPO appointment is mandatory for this controller
(a health-care provider processing sensitive data at scale) must be confirmed [VERIFY — Implementing
Regulations criteria for mandatory DPO appointment].

---

## 2. Systems, processors and locations (the "where")

**Two systems process the same clinical data today** (CONFIRMED-FACTS B1–B3). Every activity in §4
names which of them performs it:

1. **Legacy — the unit's daily system.** The legacy PHP application at `https://www.dmc-im.com`,
   on **SiteGround shared hosting in the United States**. The live build is the **original,
   un-hardened** one (verified 2026-09-03): no CSRF token, no security headers, a session cookie
   without Secure/HttpOnly, and the systemic defects of the original review — unauthenticated
   patient read/write/delete, SQL injection, admin self-registration, plaintext secrets — are live
   over real PHI on a US host. This is where staff work today.
2. **Laravel — live in parallel, not yet the daily system.** The application at
   `https://dmc-new.towardpcc.com` on OCI **`me-riyadh-1` (in-Kingdom)** holds a **full parallel
   copy** of the dataset (its own activity, A10). The one exception to "not the daily system" is the
   **consultation ledger**: since that module's cutover the Laravel app is the **source of truth**
   for `consultations` and `consultation_followups` (A2).

Cutover will replace the dmc-im.com code with the Laravel app on the same domain; until then the
legacy site's US hosting and the SMTP relay are the live cross-border exposures, and the two systems
carry two copies of the same patient data under two entirely different control sets.

| Component | Provider / location | Role under the law [VERIFY] | Personal data it can see | Safeguard in place | Contract |
|---|---|---|---|---|---|
| **Operator company — [COMPANY LEGAL NAME]** (and the individual developer/owner) | Wherever the company and its staff operate [VERIFY] | **Processor (primary)** — engages every other processor below as its sub-processor | **Everything**, in both systems: full application, database and host access, plus export/dump copies held on developer workstations (CONFIRMED-FACTS D1) | Owner-held `APP_KEY` escrow; MFA on the platform accounts; no hospital-side technical oversight exists today | **None — no controller–processor contract exists yet (top action, A0)** |
| **Legacy PHP app + its MySQL database — `www.dmc-im.com` (the DAILY system)** | **SiteGround shared hosting, United States** — account company-held (CONFIRMED-FACTS B6 covers the mailbox on the same domain) [VERIFY the same account carries the web hosting] | Sub-processor (hosting) — **outside the Kingdom** | **Everything the unit records daily**: patient identity, admissions, diagnoses, outcomes, staff accounts | **None of the Laravel controls apply here.** The live build is the original un-hardened one (B2); apex redirects to plain HTTP; external header/TLS grade **F** (`evidence/sec-web-2026-09-03.md`) | **No DPA on file** [PLACEHOLDER — SiteGround contracting entity, DPA, transfer safeguard] |
| Laravel application + MySQL 8.4 (Docker) on one compute instance | Oracle Cloud Infrastructure, region **me-riyadh-1** (in-Kingdom); Oracle Systems Limited, company-held pay-as-you-go tenancy [PROPOSED] | Sub-processor (infrastructure) | Everything in the **parallel copy** (the live Laravel database and sessions live here) | In-Kingdom region; origin accepts traffic only via Cloudflare [VERIFY firewall rule]; Oracle-managed AES-256 volume encryption by default [PROPOSED]; Oracle's **global** support staff may access the tenancy under Oracle's policies [PROPOSED] | **No signed DPA on file** [PLACEHOLDER — OCI DPA / order] |
| Audit-log archive bucket (S3-compatible object storage, WORM, seven-year lock) | OCI Object Storage, region defaults to **me-riyadh-1** (`config/services.php` → `AUDIT_S3_REGION`) | Sub-processor (storage) | Audit rows only — actor names, IPs, actions, entity ids and `details` JSON (which can carry an MRN, e.g. `handover.read`). **Laravel-side activity only**; the legacy daily system produces no comparable trail | Immutable retention rule; separate access keys (`AUDIT_S3_*`) | [PLACEHOLDER] |
| Encrypted database backups | OCI Object Storage bucket `dmc-db-backups`, **in-Kingdom**; local encrypted copies in `/var/backups/dmc/` | Sub-processor (storage) | Everything in the **Laravel** database | Nightly encrypted off-box dump; `backup:verify` staleness alert; ninety-day bucket retention is a **placeholder pending legal** | [PLACEHOLDER] |
| Cloudflare (DNS, reverse proxy, TLS termination, WAF) — **Laravel app only** | Cloudflare, Inc. — **non-Saudi entity**, **Free plan**; Saudi points of presence exist (Dammam, Jeddah, Riyadh) but the plan does not fix the serving region [PROPOSED] | Sub-processor (transit) | **All HTTP traffic in clear text at the edge**, including PHI in page responses and POST bodies (URLs are PHI-free by design) | TLS 1.2 minimum at the edge; HSTS; proxied-only DNS records. **Regional Services (in-Kingdom TLS termination) is not available on the Free plan** [PROPOSED]; [VERIFY — Cloudflare DPA and whether edge decryption constitutes a transfer outside the Kingdom] | Standard Customer DPA applied by reference, not negotiated [PROPOSED] |
| SMTP relay | **SiteGround** — the `dmc-im.com` mailbox (`mail.dmc-im.com:587` STARTTLS), **United States**, company-held | Sub-processor (messaging) | Staff e-mail addresses, usernames, one-time codes, password-reset links, the aggregate monthly PDF, audit-integrity alert text | No patient PHI is sent by design (see A8); credentials stored encrypted (`settings.mail_password`, `App\Models\Setting` `encrypted` cast) | **No DPA on file** [PLACEHOLDER — relay DPA; cross-border transfer safeguard — see §4] |
| GitHub (source repository) | GitHub, Inc. — non-Saudi | Not a processor of personal data by design (code only) | None by design. Historical exposure: legacy DB/SMTP credentials were committed and have been rotated ([`../DEPLOY-LARAVEL.md`](../DEPLOY-LARAVEL.md) §2); the legacy working folder historically held a `Demo.sql` dump containing real PHI (per the root `CLAUDE.md`); it is not in the working tree and has no entry in git history as of this draft [VERIFY every local copy has been destroyed] | **Public repository** — a time-boxed owner decision taken so CI runs free during development, to be made private before go-live (CONFIRMED-FACTS D3); secret scanning, push protection and gitleaks in place | [PLACEHOLDER] |
| Coolify (deployment orchestrator) | Self-hosted on the same OCI instance [VERIFY] | Internal tooling of the processor | Environment variables incl. DB credentials and `APP_KEY` | Host-local | n/a |
| Staff browsers | Hospital workstations / personal devices [VERIFY device policy] | End users (controller side) | Rendered PHI; downloaded exports | MFA, session timeouts and PHI-free URLs **in the Laravel app only**; the legacy daily system offers none of these | n/a |

---

## 3. Data inventory by table (schema-derived)

Columns are listed exactly as created by the migrations. "Category" uses the law's vocabulary in
words; **SENSITIVE** = health data or data revealing a sensitive characteristic [VERIFY].

**Which system these columns describe.** The inventory below is the **Laravel** schema, on OCI
Riyadh. The **legacy daily system on SiteGround (US)** holds the same patient, admission, diagnosis,
consultation and user data in its own (differently named) tables — that is the copy staff write to
today. It has **no handover subsystem** (no handover module exists anywhere in the legacy code
base), and the controls credited in the "security measures" rows below — hash-chained audit log,
encrypted narrative columns, mandatory MFA, CSRF tokens, security headers — are **Laravel-only**
and absent from the live legacy build (CONFIRMED-FACTS B2). A table-level inventory of the legacy
schema as it stands on SiteGround is still owed [PLACEHOLDER — legacy-schema inventory, processor
to produce].

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

The **System today** column is the key to this register: *legacy daily* = performed in the legacy PHP
app on SiteGround (United States); *Laravel parallel* = also present in the Laravel copy on OCI
Riyadh, which is **not** the system staff work in; *Laravel source of truth* = performed only in the
Laravel app, which owns the data. In every row the **controller is DMC** and the **primary processor
is the operator company**, which engages the named sub-processors.

| # | Activity | System today → where the data sits | Purpose (short) | Legal basis (in words) [VERIFY] | Data subjects | Data categories | Sub-processors | Transfers outside the Kingdom | Retention (see [`DATA-RETENTION.md`](DATA-RETENTION.md)) | Owner |
|---|---|---|---|---|---|---|---|---|---|---|
| A1 | Admission management | **Legacy daily** → SiteGround, **US**; also **Laravel parallel** → OCI Riyadh | Track each patient's episode from admission to discharge; assign consultants | Provision of health care by a licensed provider, minimum necessary; staff data under employment | Patients; staff | **SENSITIVE** clinical + demographics; staff identity | SiteGround (daily copy); OCI + Cloudflare (parallel copy) | **Yes — the daily copy is hosted in the US**; Cloudflare edge decryption for the Laravel copy [VERIFY] | Clinical record period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER — Head of IM] |
| A2 | Consultation ledger | **Laravel source of truth** (since the module's cutover) → OCI Riyadh; historical rows also remain in the legacy DB, **US** | Record referrals to/from other services, responses and daily follow-up | Same as A1 | Patients; staff | **SENSITIVE** incl. free-text response notes | OCI + Cloudflare; SiteGround for the historical rows | Cloudflare edge [VERIFY]; the legacy historical copy sits in the US | Clinical record period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER] |
| A3 | Handover | **Laravel only** → OCI Riyadh; **no legacy equivalent exists** | Cross-cover narrative, checkpoints (incl. code status), consultant-to-consultant acknowledgement | Same as A1; patient-safety duty | Patients; staff | **SENSITIVE** incl. resuscitation status | OCI + Cloudflare | Cloudflare edge [VERIFY] | Clinical record period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER] |
| A4 | Statistics and reporting | **Laravel parallel** → OCI Riyadh (computed from the parallel copy); the legacy daily system has its own statistics and export pages, **US** | Census, KPIs, LOS, readmissions, mortality, per-consultant activity; exports; monthly PDF | Health-service management and quality; statistical purposes [VERIFY compatibility/statistics provision] | Patients (aggregated; row-level in registry exports); staff (named in per-consultant stats) | **SENSITIVE** at row level (registry exports); aggregate otherwise | OCI + Cloudflare; SiteGround (relay) for the monthly PDF | Monthly aggregate PDF via the US SMTP relay; legacy-side exports are produced in the US | Exports: user-held, short; PDF at recipients | [PLACEHOLDER] |
| A5 | User and account management | **Both, separately** — the legacy daily system keeps its own accounts (**US**) and the Laravel app its own (**OCI Riyadh**); the two account sets are not synchronised | Authenticate and authorise staff; MFA; password lifecycle; roles/capabilities | Employment/contract; legal obligation to secure systems (national cybersecurity controls) [VERIFY] | Staff (incl. applicants in pending registration) | Identity, contact, credentials, IP, device trust | SiteGround (legacy accounts + relay); OCI + Cloudflare | OTP / reset / username e-mails via the US SMTP relay; legacy credentials are held in the US | Account life + post-departure period [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER — System owner] |
| A6 | Audit logging (incl. break-glass, shipping, verification) | **Laravel only** → OCI Riyadh + in-Kingdom WORM bucket; **the legacy daily system produces no comparable trail** | Accountability for every state change and PHI read/export; tamper evidence | Legal obligation / security controls; necessary for the health-service purpose [VERIFY — note legitimate interest is not available for sensitive data] | Staff; patients (identifiers in details) | Staff identity + IP; some patient identifiers | OCI (compute + object storage) | None (archive bucket in-Kingdom) | Six years local (`settings.audit_retention_years`); seven-year WORM copy | [PLACEHOLDER — Security lead] |
| A7 | Backups | **Laravel only** → in-Kingdom OCI bucket `dmc-db-backups`; **legacy-side backups are whatever SiteGround takes** [VERIFY], in the US | Recover from loss or corruption | Same bases as the source data | All of the above | Everything | OCI (Laravel); SiteGround (legacy) | Laravel backups stay in-Kingdom; **any SiteGround-held backup of the daily database is in the US** | Ninety days (placeholder pending legal) | [PLACEHOLDER — System owner] |
| A8 | Transactional e-mail | **Both** → the same `dmc-im.com` mailbox on SiteGround, **US** | Deliver OTPs, password resets, username reminders, monthly PDF, integrity alerts | Employment/contract; security | Staff; report recipients | E-mail address, username, one-time codes, links; aggregate report | SiteGround (relay) | **Yes — US-hosted SMTP relay** | Provider retention [VERIFY]; local none | [PLACEHOLDER] |
| A9 | Application and security telemetry | **Laravel only** → OCI Riyadh (container logs, CSP reports) | Detect faults and attacks (application log, CSP violation reports, failed-login bursts) | Security obligation [VERIFY] | Staff (IP, username on `login.failed`); anonymous visitors (CSP reports) | IP addresses, usernames, URLs, error context | OCI | None | Log rotation (default fourteen days) | [PLACEHOLDER] |
| A10 | **Parallel operation and migration copy** | **Laravel parallel** → OCI Riyadh, built and refreshed from legacy dumps | Hold a second, complete copy of the dataset so the Laravel app can be run, verified and cut over | Same bases as the source data; the copy exists to migrate the record, not for a new purpose [VERIFY — further-processing/compatibility] | Patients; staff | **Everything in §3** (~17k patients, ~37k episodes, ~330 accounts) | OCI + Cloudflare; developer workstations hold dump copies (D1) | Copy itself is in-Kingdom; the **source** it is taken from is the US-hosted legacy database, and dumps have been held on operator machines | Ends at cutover, when the legacy copy must be decommissioned [PLACEHOLDER — cutover date; destruction certificate] | [PLACEHOLDER — System owner + DPO] |

### 4.1 A1 — Admission management (**SENSITIVE**)

| Field | Content |
|---|---|
| **Description** | Admit a patient (create `patients` row if new + `admissions` + `admission_diagnoses`), queue for assignment, assign (manual, self, shuffle, bulk reassign), transfer ward↔ICU / to another specialty / external, two-phase discharge, ICU discharge, reverse discharge, long-term flag, modify demographics/diagnoses, admin delete/restore, bulk import from spreadsheet, registry search. Endpoints and exact writes (Laravel): [`../DATABASE-AND-BEHAVIOR.md`](../DATABASE-AND-BEHAVIOR.md) §5. |
| **System(s) today / where** | **Legacy daily system** — staff perform this activity in the legacy PHP app at `dmc-im.com`, whose database sits on **SiteGround shared hosting in the United States**, in the **original un-hardened build** (CONFIRMED-FACTS B1/B2). The **Laravel app on OCI Riyadh holds a parallel copy** of the same records (A10) and will take the activity over at cutover. Everything in the "security measures" row below therefore protects the **parallel copy**, not the copy staff actually write to today. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company** (no Art. 17 contract yet). Sub-processors: **SiteGround** (US) for the daily system and the relay; **Oracle/OCI** (Riyadh) and **Cloudflare** (Free plan) for the Laravel copy. |
| **Purpose** | Operational patient-flow management for the Internal Medicine unit: knowing who is in which bed under which consultant, and their progress to discharge. |
| **Legal basis (words)** | Processing of health data that is necessary for a licensed health-care provider to deliver and manage health services, limited to the minimum needed [VERIFY ARTICLE — health-data processing conditions; whether consent is additionally required from the patient, and whether the hospital's general admission consent / privacy notice covers this secondary system]. Staff attribution fields: performance of the employment relationship [VERIFY]. |
| **Data subjects** | In-patients of the unit (≈17,400 distinct patients; ≈37,700 admission episodes); clinical staff (≈331 accounts, ≈150 active). |
| **Data categories** | §3.1 `patients`, `admissions`, `admission_diagnoses` (+ derived TB flag). Staff: `users` identity. |
| **Source** | Entered by staff at admission (from the hospital's primary record system [VERIFY name]); bulk import spreadsheets; repeated imports from the legacy PHP application into the parallel copy (`legacy:import`, A10). |
| **Recipients** | Clinical roles (Admin, Registrar, Consultant, Resident); Observer role reads the board only. No external recipients. |
| **Processors / transfers** | **Daily copy:** SiteGround, **United States** — a transfer outside the Kingdom of the full clinical dataset, with **no DPA and no safeguard on file** [VERIFY — transfer safeguard for the legacy hosting]. **Parallel copy:** OCI (in-Kingdom); Cloudflare edge decrypts every page and POST in transit, on the **Free plan**, where Regional Services is unavailable [PROPOSED] [VERIFY — transfer classification and safeguard]. |
| **Retention** | No policy today in **either** system; Laravel rows are never hard-deleted by routine flow (soft deletes) and the legacy database has no destruction mechanism at all. Period to be set by the applicable medical-records regulation, whose governing instrument delegates the figure to a Ministry annex that has **not** been obtained [NEEDS LEGAL CONFIRMATION] — see DATA-RETENTION.md. |
| **Security measures (implemented — Laravel app only; the legacy daily system has none of them)** | Mandatory TOTP MFA (`EnsureMfaEnrolled`, `MfaController`); role + capability checks on every endpoint (`EnsureAdmin`, controller authorisation); step-up re-authentication for reverse-discharge and delete (`RequireStepUp`); session-sourced attribution (`admitted_by`, `discharged_by`); CSRF on all writes; audit row on every state change and on registry search/export (`App\Support\Audit`); optional per-record open logging (`settings.log_record_opens` → `registry.open`); PHI never in URLs (search terms in POST body); soft deletes; DB transactions on multi-row writes; CSP + HSTS (`SecurityHeaders`); TLS 1.2+ at the edge. |
| **Gaps** | **The system this activity actually runs on is the un-hardened legacy build on US hosting** — the single largest gap in this register **GAP** (DPIA R13/R14; owner decision, HANDOFF item 4). No controller–processor contract with the operator company **GAP**. In the Laravel copy, encryption at rest is **partial** — the four narrative columns under `APP_KEY`, everything else relying on the cloud provider's default volume encryption **GAP**. Privacy notice to patients about this system [VERIFY — other workstream]. |
| **Owner** | [PLACEHOLDER — Head of Internal Medicine (clinical); System owner (technical, processor side)] |

### 4.2 A2 — Consultation ledger (**SENSITIVE**)

| Field | Content |
|---|---|
| **Description** | Book a consultation to a specialty (optionally linked to a patient/admission), track status new → active → ongoing → signed-off, daily follow-up ticks with optional note, sign-off with a structured response (`response_disposition`, `response_followup_needed`, `response_note`), reverse sign-off, coordinator reassignment, service handover sheet (screen + print), physician dashboard. |
| **System(s) today / where** | **This is the one activity the Laravel app already owns.** Since the ledger's cutover the Laravel app on **OCI Riyadh (in-Kingdom)** is the **source of truth** for `consultations` and `consultation_followups`; the `settings.consultations_source_of_truth` flag enforces it technically, so a re-import from the legacy database preserves rather than overwrites the ledger. The **historical rows that were migrated remain in the legacy database on SiteGround (US)** and are no longer updated. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company**. Sub-processors: **Oracle/OCI** (Riyadh) and **Cloudflare** (Free plan) for the live ledger; **SiteGround** (US) for the frozen historical copy and the relay. |
| **Purpose** | The consulted service's own bookkeeping of referrals and their disposition (not a replacement for the hospital information system). |
| **Legal basis (words)** | As A1 [VERIFY]. |
| **Data subjects** | Patients referred; requesting and responding clinicians. |
| **Data categories** | §3.1 `consultations`, `consultation_followups`; `notifications` rows of type `consultation.assigned`. |
| **Source** | Entered by clinical staff **in the Laravel app**; legacy import of the historical rows (≈1,283 per the ledger migration). |
| **Recipients** | Clinical roles; coordinator capability (`can_coordinate_consultations`) may see and modify all consults but never sign off or delete. **Printed handover sheets** are a physical output — see DATA-CLASSIFICATION.md. |
| **Processors / transfers** | Live ledger: as A1's parallel copy (OCI in-Kingdom; Cloudflare edge [VERIFY]). Frozen historical rows: still on SiteGround, **United States**, with no DPA on file. |
| **Retention** | As A1 [NEEDS LEGAL CONFIRMATION]. Follow-ups are append-only. The **frozen legacy copy of the same rows** needs its own destruction decision at cutover [PLACEHOLDER — DPO]. |
| **Security measures** | As A1 (Laravel controls, which here do protect the operative copy); `response_note` and follow-up `note` are **encrypted at rest** under `APP_KEY`; `consultation.*` audit actions incl. `status_change` with from/to; consultation delete is Admin-only. |
| **Gaps** | Free-text `response_note` / follow-up `note` can carry more than the minimum — clinical guidance on content [PLACEHOLDER — governance instruction]. Two copies of the historical ledger exist in two jurisdictions until the legacy database is decommissioned **GAP**. |
| **Owner** | [PLACEHOLDER] |

### 4.3 A3 — Handover (**SENSITIVE**)

| Field | Content |
|---|---|
| **Description** | Free-text cross-cover note + six checkpoints per admission; every save appends an immutable revision; consultant-to-consultant moves create a signature request re-bound at signing to the revision actually read; soft same-day gate raises persistent reminders; break-glass read logging when enabled. Full lifecycle: [`../HANDOVER-COMPLIANCE.md`](../HANDOVER-COMPLIANCE.md). |
| **System(s) today / where** | **Laravel app only**, on **OCI Riyadh (in-Kingdom)**. The legacy daily system has **no handover module at all**, so there is no second copy of this data and no legacy equivalent to migrate. Because the Laravel app is not yet the daily system, how much handover activity is actually recorded before cutover is [VERIFY — extent of live handover use in the parallel period; clinical governance and processor to state]. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company**. Sub-processors: **Oracle/OCI** (Riyadh), **Cloudflare** (Free plan). |
| **Purpose** | Patient safety at change of responsible consultant; evidencing who wrote what and who acknowledged it. |
| **Legal basis (words)** | As A1; additionally the provider's duty of continuity of care and clinical record-keeping [VERIFY]. |
| **Data subjects** | Patients; outgoing/incoming consultants; users who receive reminders. |
| **Data categories** | §3.1 `handovers`, `handover_revisions`, `handover_signatures`, `notifications` (`handover.*` incl. `patient_name` + `mrn` in payload). |
| **Source** | Clinical staff. |
| **Recipients** | All clinical roles can read handovers by design (cross-cover). |
| **Processors / transfers** | OCI (in-Kingdom); Cloudflare edge decryption [VERIFY]. No US-hosted copy of handover content exists, because the legacy system has no handover module. |
| **Retention** | Revisions and signatures are retained indefinitely by design (append-only). Period [NEEDS LEGAL CONFIRMATION]. |
| **Security measures** | As A1; `handovers.body` and `handover_revisions.body` are **encrypted at rest** under `APP_KEY`; `handover.update` / `handover.sign` / `handover.reassign_incomplete` audit rows; `handover.read` break-glass rows when `settings.log_record_opens` is ON (records the MRN); only the primary consultant or a manager may edit while a signature is pending. |
| **Gaps** | `log_record_opens` defaults **OFF** — reads of clinical narrative are unlogged unless an admin enables it [PLACEHOLDER — governance decision on default]. |
| **Owner** | [PLACEHOLDER] |

### 4.4 A4 — Statistics and reporting

| Field | Content |
|---|---|
| **Description** | Dashboard, statistics pages, physician consultation dashboard (aggregate; per-consultant named counts), registry (row-level PHI search, Admin-only), registry CSV/XLSX export, statistics XLSX/PDF export, audit-log CSV/XLSX export, scheduled monthly PDF e-mailed on the first of each month at 06:00 (`routes/console.php` → `GenerateMonthlyReport`). |
| **System(s) today / where** | **Laravel parallel copy** → OCI Riyadh: every figure described here is computed from the parallel copy, not from the system staff work in, so the two systems can disagree until cutover [VERIFY — which system's numbers management actually receives]. The **legacy daily system has its own statistics and patient-export pages** on SiteGround (**US**), with none of the audit or admin-only restrictions below. The monthly PDF leaves the Kingdom through the SiteGround relay. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company**. Sub-processors: **Oracle/OCI** (Riyadh), **Cloudflare** (Free plan), **SiteGround** (US relay, and the legacy exports). |
| **Purpose** | Service management, quality and capacity monitoring, monthly reporting to management. |
| **Legal basis (words)** | Health-service management as A1; statistical/quality purposes as a purpose compatible with collection [VERIFY ARTICLE — further-processing compatibility and any statistics/research provision]. |
| **Data subjects** | Patients (aggregated except in registry exports); consultants (named in activity counts). |
| **Data categories** | Aggregates of §3.1; **row-level PHI in registry exports**; staff names in per-consultant statistics; the monthly PDF is **aggregate-only** — the template was inspected and carries no MRN or name (CONFIRMED-FACTS C6, `App\Jobs\GenerateMonthlyReport`). |
| **Recipients** | Admin role only for registry/statistics/reports/exports/audit (`admin` route group, `routes/web.php`); `report_recipients` for the monthly PDF (hospital staff/management [PLACEHOLDER — list]). |
| **Processors / transfers** | Monthly PDF leaves the Kingdom via the **SiteGround** relay in the **United States** (aggregate content; the template carries no MRN or name). Exports are downloaded to staff devices [VERIFY endpoint/device controls]. Legacy-side exports are produced and downloaded from the US host. |
| **Retention** | Exports: no server copy; user-held. PDF: at recipients' mailboxes and at the relay [VERIFY relay retention]. |
| **Security measures (Laravel app only)** | Admin-only routes; `registry.search` audited with redacted filters; `registry.export` / `registry.export_xlsx` audited with mode, redacted filters and `row_count` (`RegistryController::logExport`); pre-export row-count advisory; PHI-free URLs. **Since 2026-09-03 every other export and report PDF is audited too** — `statistics.export.xlsx`, `statistics.export.pdf`, `audit.export.csv`, `audit.export.xlsx`, `report.pdf.annual`, `report.pdf.consultant`, `report.pdf.governance`, `report.pdf.monthly`, `report.pdf.download` (CONFIRMED-FACTS C12) — and download filenames carry a `SECRET-` or `CONFIDENTIAL-` prefix with a bilingual classification footer on every PDF (C13). `report.pdf.governance` and the audit-log exports sit in the audit viewer's PHI-read category. |
| **Gaps** | No DLP on downloaded files **GAP**; **legacy-side exports of the same patient data are entirely unaudited, unrestricted and unlabelled** **GAP** — the export auditing and labelling closed on 2026-09-03 cover the Laravel app only, i.e. the parallel copy, not the system staff export from today. |
| **Owner** | [PLACEHOLDER] |

### 4.5 A5 — User and account management

| Field | Content |
|---|---|
| **Description** | Phased self-registration (e-mail OTP + authenticator confirmed before an account exists — `pending_registrations`), admin activation, login (username or e-mail), mandatory TOTP MFA with recovery codes, trusted-device MFA skip for a bounded window, password expiry after three months, admin-driven password reset e-mail and MFA reset, role/capability edits, deactivation, soft-delete/restore, profile edits, session idle/absolute timeouts, step-up re-authentication. |
| **System(s) today / where** | **Both systems keep their own, unsynchronised staff accounts.** The legacy daily system's accounts live on **SiteGround (US)** in the un-hardened build — the original review found **admin self-registration** and unauthenticated endpoints there, and that build is what is live (CONFIRMED-FACTS B2). The description below is the **Laravel** account lifecycle on **OCI Riyadh**; none of it constrains the legacy accounts. A leaver therefore has to be deactivated **twice** [PLACEHOLDER — joiner/leaver procedure covering both systems]. |
| **Controller / processor** | Controller: **DMC** (staff data). Primary processor: **the operator company**, whose own personnel administer both systems. Sub-processors: **SiteGround** (legacy accounts + relay), **Oracle/OCI**, **Cloudflare**. |
| **Purpose** | Identity, authentication and authorisation of staff; enforcement of least privilege. |
| **Legal basis (words)** | Employment / contractual relationship; the controller's obligation to implement security controls (national cybersecurity controls for health-sector entities) [VERIFY]. |
| **Data subjects** | Staff; sign-up applicants. |
| **Data categories** | §3.2. |
| **Source** | The individual (registration, profile); administrators (roles, capabilities). |
| **Recipients** | Administrators (Control → Users); the operator company's own staff, who hold platform-level access to both systems. |
| **Processors / transfers** | OTP, reset and username-reminder e-mails traverse the **SiteGround** relay in the **United States** (e-mail address, username, code/link). Legacy account credentials are stored in the US on the same shared host. |
| **Retention** | Accounts: deactivated on departure, retained for attribution (FKs are `nullOnDelete`; soft-delete keeps history) — period [NEEDS LEGAL CONFIRMATION]. `pending_registrations`: thirty-minute TTL (sweep not yet confirmed [VERIFY]). `trusted_devices`: window then expired rows remain. |
| **Security measures** | bcrypt passwords; `mfa_secret` encrypted with `APP_KEY`; recovery codes hashed; TOTP replay guard (`mfa_last_counter`); IP-keyed login/MFA throttle (`bootstrap/app.php`); failed-login burst notification (`security.failed_logins`, threshold `settings.failed_login_notify_threshold`); remember-me never applied to MFA users; `SessionTimeout` middleware audited as `session.timeout`; `login.success` / `login.failed` / `logout` / `password.change` / `mfa.device_trusted` / `mfa.device_revoked` / `user.*` audit rows; self-disable of MFA removed (admin-only reset). |
| **Gaps** | Recovery codes in `pending_registrations.totp_recovery_codes` are plaintext for up to thirty minutes (by design; short-lived) — accepted risk to be recorded [PLACEHOLDER]; no scheduled sweep of expired pending rows confirmed [VERIFY]; **the legacy daily system's account controls are those of the original build — admin self-registration, and none of the authentication lifecycle described above — and no joiner/leaver process spans both systems** **GAP**. |
| **Owner** | [PLACEHOLDER — System owner] |

### 4.6 A6 — Audit logging, break-glass, shipping and verification

| Field | Content |
|---|---|
| **Description** | Every state change, login event, PHI search/export and (optionally) record open writes one `audit_log` row through the single writer [`../../app/Support/Audit.php`](../../app/Support/Audit.php) (actor and IP from the session/request, never from the client). Rows are chained: `prev_hash` = previous row's `row_hash`, `row_hash` = SHA-256 of the canonical row (`App\Models\AuditLog`). `audit:verify` walks the chain; `audit:verify-daily` runs at 02:30 and on failure writes `Log::critical`, notifies every active admin in-app (de-duplicated) and e-mails report recipients ([`../../app/Console/Commands/AuditVerifyDaily.php`](../../app/Console/Commands/AuditVerifyDaily.php)). `audit:ship` runs hourly, sends up to five thousand unshipped rows as NDJSON to the S3-compatible archive and advances `settings.audit_shipped_through_id` only after a successful upload ([`AuditShip.php`](../../app/Console/Commands/AuditShip.php)). `audit:prune` deletes only rows already shipped **and** older than `settings.audit_retention_years` (default six; zero refuses), is dry-run by default, requires `--confirm`, and is deliberately never scheduled ([`AuditPrune.php`](../../app/Console/Commands/AuditPrune.php), [`routes/console.php`](../../routes/console.php)). Admin viewer at `/audit` with CSV/XLSX export. |
| **System(s) today / where** | **Laravel app only** — the `audit_log` table on **OCI Riyadh** and the hourly NDJSON copy in the in-Kingdom bucket `dmc-audit-log`. **The legacy daily system produces no comparable trail**, so the activity staff actually perform every day (A1) is largely unaccountable: there is no tamper-evident record of who read, changed or deleted a patient row in the system of record [PLACEHOLDER — interim compensating control for the legacy period, security lead]. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company**, whose personnel are also the actors most of these rows attribute. Sub-processor: **Oracle/OCI** (compute + object storage, in-Kingdom). |
| **Purpose** | Accountability, tamper evidence, breach investigation, clinical-governance reconstruction. |
| **Legal basis (words)** | The controller's obligation to keep records enabling accountability and to secure personal data; necessary for the health-service purpose. Legitimate interest cannot be relied on for the sensitive-data portion [VERIFY ARTICLE]. |
| **Data subjects** | Staff (every row); patients (identifiers inside `details` on some actions). |
| **Data categories** | §3.3 `audit_log`; `setting_changes`. |
| **Recipients** | Administrators; active admins and `report_recipients` receive integrity alerts. |
| **Processors / transfers** | In-Kingdom object-storage bucket (WORM, seven-year lock) — no transfer outside the Kingdom. |
| **Retention** | Local six years (config); off-box seven years immutable. See DATA-RETENTION.md for the rationale and the recommendation to schedule a prune **dry-run**. |
| **Security measures** | ORM-level append-only (update/delete hooks throw); transactional chain append with row lock; nightly verification with three alert channels; hourly off-box copy to immutable storage; separate archive credentials. |
| **Gaps** | Pre-chain rows written before the hash-chain migration have NULL hashes and are not verifiable until backfilled (`AuditVerify` warns) [VERIFY backfill was run in production]; **no audit trail exists for the legacy daily system** **GAP**; `log_record_opens` is **OFF** in production, so record opens are not logged even in the Laravel app [PLACEHOLDER — governance decision]. (The audit-log export is itself audited since 2026-09-03 — `audit.export.csv` / `audit.export.xlsx`, both in the PHI-read category.) |
| **Owner** | [PLACEHOLDER — Security lead] |

### 4.7 A7 — Backups

| Field | Content |
|---|---|
| **Description** | **Laravel copy:** a nightly encrypted off-box dump runs to the in-Kingdom OCI bucket `dmc-db-backups`, with encrypted local copies in `/var/backups/dmc/`, a daily `backup:verify` staleness alert and a logged restore drill ([`../BACKUP-AND-RESTORE.md`](../BACKUP-AND-RESTORE.md)). Manual `mysqldump ... \| gzip > ~/pre-deploy-*.sql.gz` is still taken on the host before each deploy and before `legacy:import`. **Legacy daily system:** whatever backup SiteGround takes of the shared-hosting account — not specified, not verified and not restore-tested by the processor [VERIFY — SiteGround backup scope, retention and location]. |
| **System(s) today / where** | Laravel backups: **in-Kingdom** (OCI bucket + the host itself). Legacy backups: **United States**, inside SiteGround's platform. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company** (holds the encryption keys and `APP_KEY`). Sub-processors: **Oracle/OCI**; **SiteGround** for anything it retains of the daily database. |
| **Purpose** | Restore after loss, corruption, ransomware or a failed migration. |
| **Legal basis (words)** | Inherits the bases of the source data; the controller's duty to protect data against loss [VERIFY]. |
| **Data subjects / categories** | Everything in §3. |
| **Recipients** | The operator company's operators only; the hospital has no independent copy [PLACEHOLDER — whether the controller should hold an escrowed copy, DPO decision]. |
| **Processors / transfers** | Laravel backups stay in-Kingdom. **`APP_KEY` is the root of trust** — a dump restored without the key in force when it was taken is incomplete, because the encrypted narrative columns will not decrypt; the key is escrowed by the owner, i.e. on the processor side. Any SiteGround-held backup of the daily database is in the **United States** with no DPA on file. |
| **Retention** | Local encrypted copy two days; bucket **ninety days — a placeholder pending legal** [NEEDS LEGAL CONFIRMATION]. Manual pre-deploy dumps: no rule today — see DATA-RETENTION.md. SiteGround-side retention unknown [VERIFY]. |
| **Security measures** | Encryption in transit and at rest for the off-box dump; separate bucket credentials; staleness alerting; a proven restore drill. **GAP:** pre-deploy dumps and the legacy-side PHI copies listed in CONFIRMED-FACTS D1 (operator workstations, `/home/ubuntu/migrate/dmc/`) are still uninventoried and partly unencrypted. |
| **Owner** | [PLACEHOLDER — System owner]; target date [PLACEHOLDER]. |

### 4.8 A8 — Transactional e-mail

| Field | Content |
|---|---|
| **Description** | Outbound only: registration verification code (`RegistrationCodeMail`), username reminder (`UsernameReminderMail`), password-reset link (framework), monthly report (`MonthlyReportMail`, PDF attached), audit-integrity failure alert (plain text, `AuditVerifyDaily`). SMTP settings are runtime-configurable (Control → System) with the password encrypted (`settings.mail_password`). |
| **System(s) today / where** | **Both systems send through the same mailbox.** Production mail leaves the Laravel app on OCI Riyadh via `mail.dmc-im.com:587` (STARTTLS) from `info@dmc-im.com` — the **`dmc-im.com` mailbox on SiteGround, United States**, company-held; the legacy daily system's own mail (password reset and the like) uses the same domain [VERIFY — that legacy mail uses the same mailbox]. This is one of the two live cross-border exposures. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company**, which holds the mailbox account. Sub-processor: **SiteGround** (US). |
| **Purpose** | Account security flows; management reporting; security alerting. |
| **Legal basis (words)** | Employment/contract; security obligation [VERIFY]. |
| **Data subjects** | Staff; applicants; report recipients. |
| **Data categories** | E-mail address, username, one-time code, reset token link, aggregate PDF, integrity-check output (which can name audit row ids — no PHI). **No patient data by design**; the monthly template was checked and carries no MRN or name (CONFIRMED-FACTS C6). |
| **Recipients / processors** | **SiteGround** — the `dmc-im.com` mailbox, **hosted in the United States** → transfer of staff personal data outside the Kingdom, with **no DPA on file**. Safeguard required: contractual clauses / adequacy decision / minimum-necessary assessment per the cross-border transfer regulations [VERIFY ARTICLE — transfer conditions and whether a US-hosted relay for staff e-mail qualifies; alternative: an in-Kingdom relay]. |
| **Retention** | None locally (messages are not stored; `MAIL_MAILER=log` in non-production writes them to the application log). Provider retention [VERIFY]. |
| **Security measures** | TLS to the relay [VERIFY `mail_encryption` value in production]; encrypted stored credential; codes are single-use and expire; reset links are framework-hashed tokens. |
| **Owner** | [PLACEHOLDER] |

### 4.9 A9 — Application and security telemetry

| Field | Content |
|---|---|
| **Description** | Application log (`storage/logs/laravel.log`, `daily` channel recommended, default rotation fourteen days via `LOG_DAILY_DAYS`); CSP violation reports POSTed by browsers to `/csp-report` and **logged only, never stored** (`CspReportController`: document URI, violated directive, blocked URI, source file, line — truncated); failed-login burst notifications; data-quality digest at 07:00 (`dq:notify`). |
| **System(s) today / where** | **Laravel app only**, on **OCI Riyadh** (container logs and the in-app notifications). Whatever the legacy daily system logs on SiteGround is outside this description and unreviewed [VERIFY — legacy log inventory and retention]. |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company**, whose operators are the only readers. Sub-processor: **Oracle/OCI**. |
| **Purpose** | Fault detection, attack detection, data-quality monitoring. |
| **Legal basis (words)** | Security obligation [VERIFY]. |
| **Data subjects** | Staff (IP, username on failed logins); any visitor (CSP report URLs). |
| **Data categories** | IP addresses, usernames, URLs (PHI-free by design), exception context (could incidentally include request data — see DATA-CLASSIFICATION.md). |
| **Recipients** | The operator company's operators. |
| **Retention** | Log rotation; see DATA-RETENTION.md. |
| **Owner** | [PLACEHOLDER] |

### 4.10 A10 — Parallel operation and migration copy (**SENSITIVE**)

| Field | Content |
|---|---|
| **Description** | The Laravel app holds a **second, complete copy of the unit's dataset** — roughly 17k patients, 37k admission episodes and 330 staff accounts — built and periodically refreshed from dumps of the legacy database through `php artisan legacy:import`. The import **truncates its target tables before its first legacy read**, rebuilds patient and user rows, and re-points the consultation ledger by natural key rather than overwriting it (the cutover flag in A2). The copy exists so the replacement system can be run, reconciled and cut over; it is not a backup and not an archive. |
| **System(s) today / where** | Source: the **legacy daily database on SiteGround, United States**. Copy: the **Laravel database on OCI Riyadh, in-Kingdom**. Intermediate dumps have also been held on operator workstations and on the OCI host (CONFIRMED-FACTS D1). |
| **Controller / processor** | Controller: **DMC**. Primary processor: **the operator company**, which performs the extraction, transport and load with no controller–processor contract in place. Sub-processors: **SiteGround** (source), **Oracle/OCI** (copy). |
| **Purpose** | Migration and parallel verification ahead of replacing the dmc-im.com code with the Laravel app. |
| **Legal basis (words)** | The same bases as the source data: the copy serves the original health-care purpose rather than a new one [VERIFY — whether migration/parallel running needs its own basis or is compatible further processing]. |
| **Data subjects / categories** | Everything in §3.1 and §3.2. |
| **Recipients** | The operator company's operators; the Laravel app's users. |
| **Processors / transfers** | The copy itself stays in-Kingdom. The **source** is outside the Kingdom, so each refresh is an export from a US host into the Kingdom, performed over the operator's own channel [VERIFY — how dumps are transported and whether they are encrypted in transit]. |
| **Retention** | **The duplicate ends at cutover**: once the legacy code is replaced, the legacy database and every intermediate dump must be inventoried and destroyed, with a destruction record [PLACEHOLDER — cutover date; destruction certificate; DPO]. Until then, two full copies of the same sensitive dataset exist under two different control regimes. |
| **Security measures** | Laravel-side controls as A1; the ledger-preserving cutover flag; a test suite that proves a reload cannot destroy the ledger; a database dump taken before every import. |
| **Gaps** | The duplicate itself is the gap: it doubles the exposed surface, and the weaker of the two copies is the one in daily use **GAP**. Intermediate dumps on workstations and on the host are uninventoried (D1) **GAP**. No agreed end date for the parallel period [PLACEHOLDER — owner]. |
| **Owner** | [PLACEHOLDER — System owner + DPO] |

---

## 5. Data-subject rights handling [VERIFY]

The law grants data subjects rights of access, correction, destruction and to be informed
[VERIFY ARTICLE — enumeration and exceptions for health data / medical records]. In these systems:

**A request must be answered across both copies.** Anything found in the Laravel app is also in the
legacy daily database (and vice versa for anything recorded since the ledger cutover), so access,
correction and destruction all have to be executed twice until cutover retires the legacy copy.
Requests reach the hospital through DMC Medical Records / Health Information Management for
patients and hospital administration for staff, with escalation to the DPO once appointed; contact
details are [PLACEHOLDER].

| Right | How it would be fulfilled today | Gap |
|---|---|---|
| Access | Admin registry search by MRN + export in the Laravel app (audited) | No patient-facing channel; must be routed through the hospital's medical-records office [PLACEHOLDER — procedure]. **The legacy copy has no equivalent audited search** — a complete answer needs the processor to query the SiteGround database directly [PLACEHOLDER — procedure covering both systems]. |
| Correction | Modify patient / admission in the Laravel app (audited, `patient.modify`) | Clinical-record corrections need governance sign-off [PLACEHOLDER]. A correction applied in one system does not propagate to the other **GAP**. |
| Destruction | Soft delete only; hard delete Admin + step-up (Laravel) | Clinical retention obligations may override [NEEDS LEGAL CONFIRMATION]. Destruction is not real while the legacy copy and its backups survive [PLACEHOLDER — procedure]. |
| Information (privacy notice) | A privacy notice exists in the Laravel app at `/privacy`; the legacy daily site carries none | The notice is not served from the system staff and patients actually interact with [PLACEHOLDER — where the notice is published to patients]. |

---

## 6. Change log

| Date | Version | Change | Author |
|---|---|---|---|
| 2026-09-03 | 0.1 | Initial draft from schema and code review | [PLACEHOLDER] |
| 2026-09-03 | 0.1 | Reconciled A4 and A6 with CONFIRMED-FACTS C12/C13 as re-verified against the code: every Laravel export and report PDF now writes an audit row (`statistics.export.*`, `audit.export.*`, `report.pdf.*`) and carries a `SECRET-`/`CONFIDENTIAL-` filename prefix with a classification footer; the legacy daily system's exports stay unaudited, unrestricted and unlabelled | [PLACEHOLDER] |
| 2026-09-03 | 0.1 | Per-activity rework to the confirmed framing: DMC as controller and the operator company as primary processor with named sub-processors (§1, §2); the legacy `dmc-im.com` app on SiteGround (US) recorded as the daily system and the Laravel app as a parallel copy that is source of truth only for the consultation ledger (§2, §4); a **System(s) today / where** and **Controller / processor** row added to every activity; new activity **A10 — parallel operation and migration copy**; data-subject rights restated across both copies (§5) | [PLACEHOLDER] |
