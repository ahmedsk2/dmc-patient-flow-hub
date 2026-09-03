# Data classification and handling — DMC Internal Medicine patient-flow hub

> **DRAFT — for review by the hospital's legal / data-protection officer and clinical governance; not legal advice.**
>
> Version 0.1 · 2026-09-03 · Scheme owner: [PLACEHOLDER — Information-security lead], with the DPO.
> Applies to every table, column, file, export, print-out and copy that originates from the hub.

---

## 1. The scheme

The hospital's national obligations point at two closely related four-level schemes: the National
Cybersecurity Authority's **Data Cybersecurity Controls**, which prescribe controls per
classification level, and the National Data Management Office's **data classification policy**,
which defines the levels themselves. Both use the same four names, which this document adopts in
words [VERIFY — current edition of the NCA Data Cybersecurity Controls and the NDMO classification
policy; confirm the hospital is an in-scope entity and which edition applies]:

| Level | Meaning (in words) | Harm from unauthorised disclosure | Colour |
|---|---|---|---|
| **Top Secret** | Data whose disclosure would cause exceptionally grave damage to national security, the economy or public safety | Exceptional / national | Red |
| **Secret** | Data whose disclosure would cause serious damage to national interests, an organisation, or **serious harm to individuals** | Serious | Orange |
| **Confidential** | Data whose disclosure would cause limited or moderate damage to an organisation or individuals | Limited / moderate | Amber |
| **Public** | Data intended for, or that may be freely made available to, the public | None | Green |

Control references in this document ("DCC ref.") are placeholders to be mapped to the exact
control identifiers by the security lead [VERIFY control refs].

### 1.1 How this system maps to the levels (proposed — for DPO confirmation)

| Level | What lands here in this system | Rationale |
|---|---|---|
| **Top Secret** | **Nothing.** | No data in the hub concerns national security. Listed for completeness so nobody "rounds up". |
| **Secret** | All **patient-identifiable health data** (identity + any clinical fact), and **secrets that unlock it** (`APP_KEY`, database credentials, archive keys, backup encryption keys) | Disclosure of diagnoses, TB status, resuscitation status or death outcome causes serious, lasting harm to an individual (stigma, discrimination, distress to families). The law treats health data as sensitive [VERIFY]. Keys are classified with the data they protect. **[VERIFY — whether the NDMO/NCA mapping places sensitive personal data at Secret or Confidential; this draft proposes Secret.]** |
| **Confidential** | Staff personal data and account state; the audit log (except rows carrying patient identifiers, which inherit Secret); operational configuration; **aggregate** statistics that name consultants; application logs; CSP reports; source code and deployment configuration without secrets | Disclosure harms individuals or the hospital in a limited way (e.g. staff e-mail list, workload per consultant, internal URLs). |
| **Public** | Reference tables (`icd10`, `countries`, `specialties`, `consultation_reasons`, `tb_diagnoses`); the login page; the public build assets | Already public or contain no personal/organisational information. |

**Inheritance rule:** a container takes the highest level of anything inside it. A backup of the
whole database is Secret; a spreadsheet with one MRN is Secret; a log file with one exception
that echoed a patient name is Secret until scrubbed.

**Aggregation rule:** aggregates are Confidential only while no cell can be traced to an
individual. A "mortality by consultant" table with a count of one, or any statistic filtered to a
single MRN, is Secret.

---

## 2. Classification of database tables and columns

Derived from [`../../database/migrations/`](../../database/migrations/); see
[`ROPA.md`](ROPA.md) §3 for the full column lists.

### 2.1 Secret

| Table | Columns | Why Secret | Notes |
|---|---|---|---|
| `patients` | `mrn`, `name`, `gender`, `age`, `nationality` | Identity of a hospital in-patient; the row's existence is itself a health fact | Soft-deleted rows stay Secret |
| `admissions` | all clinical and location columns; `outcome` (Alive/Dead); `discharge_to` (LAMA / Absconded / Mortuary); `delay_reason`; `is_longterm` | Episode of care, outcome incl. death | `consultant_id`, `admitted_by`, `discharged_by` link staff to a patient — Secret by linkage |
| `admission_diagnoses` | `icd10_code` | Diagnosis; with `tb_diagnoses` yields an infectious-disease flag | **Highest sensitivity in the system** together with `code_status` |
| `consultations` | `mrn`, `patient_name`, `age`, `bed`, `current_location`, `indication`, `other_indication`, `response_disposition`, `response_followup_needed`, `response_note`, dates, staff FKs | Referral reason and specialist response (free text) | |
| `consultation_followups` | `note`, `followup_date`, `author_id` | Daily clinical review | Append-only |
| `handovers`, `handover_revisions` | `body`, `checkpoints` (incl. `code_status`, `high_risk`) | Clinical narrative and resuscitation status | Revisions are the permanent history |
| `handover_signatures` | all | Care-responsibility trail per patient | |
| `notifications` | rows whose `payload` carries `patient_name` / `mrn` (`handover.incomplete`, `handover.transfer`, `consultation.assigned`) and `admission_id` | Patient identifiers in an inbox feed | Other rows (e.g. `audit.integrity_failure`, `security.failed_logins`) are Confidential |
| `audit_log` | rows whose `details` carry an MRN or patient name (`handover.read`, `patient.modify`, `patient.merge`, `patient.repoint`, `admission.*` before/after values) | Patient identifiers | Whole-table exports are therefore Secret |
| `settings` | `mail_password` (encrypted) | Credential | Everything else in `settings` is Confidential |
| `users` | `mfa_secret` (encrypted), `mfa_recovery_codes` (hashed), `password` (bcrypt), `remember_token` | Authentication secrets — with `APP_KEY` they unlock accounts that reach Secret data | Stored transformed; treat the columns as Secret regardless |
| `pending_registrations` | `totp_secret` (encrypted), `totp_recovery_codes` (**plaintext for up to thirty minutes**), `email_code_hash`, `token` | Authentication secrets in flight | Short-lived; purge on expiry |
| `trusted_devices` | `validator_hash`, `selector` | MFA-skip token material | Hashed; revoke on incident |
| `sessions` table / `storage/framework/sessions/*` | `payload` | Authenticated session state; flashed form input may contain PHI | Delete to revoke |

### 2.2 Confidential

| Table | Columns | Notes |
|---|---|---|
| `users` | `username`, `name`, `full_name`, `email`, `email_verified_at`, `role`, `specialty_id`, `active`, `on_service`, capability flags, `mfa_enrolled_at`, `mfa_last_counter`, `pass_exp_date`, `tour_completed_at`, `legacy_id`, `deleted_at` | Staff directory and privileges |
| `audit_log` | `actor_id`, `actor_name`, `action`, `entity_type`, `entity_id`, `ip`, `prev_hash`, `row_hash`, `created_at`, and `details` without patient identifiers | Accountability record; integrity-critical (append-only, hash-chained) |
| `setting_changes` | all | Configuration history with staff attribution |
| `settings` | thresholds, timeouts, `log_record_opens`, `audit_shipped_through_id`, `audit_retention_years`, `mail_*` except password, `app_timezone`, `app_url` | Reveals security posture |
| `report_recipients` | `email`, `active`, `added_by_id` | Staff e-mail |
| `password_reset_tokens` | `email`, `token` (hashed) | |
| `trusted_devices` | `user_id`, `label`, `ip`, timestamps | IP = personal data |
| `notifications` | non-patient rows | |
| `cache`, `jobs` | transient; the queued monthly-report job carries the PDF bytes (Confidential while aggregate) [VERIFY queue driver] | |

### 2.3 Public

`icd10`, `countries`, `specialties`, `consultation_reasons`, `tb_diagnoses`, `migrations`.

---

## 3. Classification of artifacts outside the database

| Artifact | Where it lives | Level | Contains | Handling summary |
|---|---|---|---|---|
| **MySQL data volume** (Docker) | OCI block/boot volume on the instance | **Secret** | Everything | At-rest encryption of PHI is **planned** (GAP); provider volume encryption [VERIFY]; access = host root only |
| **Automated backups** *(being built)* | [PLACEHOLDER — in-Kingdom bucket] | **Secret** | Everything | Encrypted with a key held separately from the host; ninety-day retention; restore-tested; access logged |
| **Manual pre-deploy dumps** `~/pre-deploy-*.sql.gz`, incident dumps | Operator home directory on the host | **Secret** | Everything | **Today unencrypted on the same host** (GAP). Encrypt (`gpg`/`age`) at creation, move off-box, delete after the deploy is verified — see DATA-RETENTION.md |
| **Legacy database dumps** (`dmc_prod` exports used for `legacy:import`; the historical `Demo.sql` referenced in the root `CLAUDE.md`) | Operator machines; historically the legacy working folder (not in the working tree and not in git history as of this draft) | **Secret** | Real PHI | Locate every copy; destroy after migration sign-off [VERIFY inventory of local copies] |
| **Audit archive NDJSON** | S3-compatible WORM bucket, seven-year lock, region me-riyadh-1 | **Secret** (inherits rows with MRNs) | Audit rows incl. actor, IP, details | Immutable; separate keys (`AUDIT_S3_*`); read access limited to the security lead and DPO |
| **Audit-log exports** (`/audit/export`, `/audit/export-xlsx`) | Staff device | **Secret** | Same as above | Admin only; **not itself audited (GAP)** — record manually; delete after use |
| **Registry exports** (CSV/XLSX) | Staff device | **Secret** | Row-level PHI | Admin only; audited with row count; no e-mail forwarding; delete within the period in DATA-RETENTION.md |
| **Statistics exports** (XLSX/PDF) | Staff device | **Confidential** unless a cell identifies an individual → **Secret** | Aggregates, consultant names | Admin only; **not audited (GAP)** |
| **Monthly report PDF** | E-mailed to `report_recipients` via the US relay; queued in `jobs` | **Confidential** (aggregate-only [VERIFY sample]) | Aggregates | Recipients list reviewed quarterly; no forwarding outside the hospital |
| **Printed handover / service sheets** | Paper on the ward | **Secret** | Patient names, MRNs, narrative, code status | Named holder; never left on a desk; shred at end of shift or when superseded [PLACEHOLDER — clinical rule] |
| **Application logs** `storage/logs/laravel-*.log` | Host | **Confidential**; **Secret if PHI leaks in** | Exceptions, CSP reports, integrity alerts, mail failures, IPs | `LOG_LEVEL=warning` in production; review for PHI after any incident; rotate |
| **CSP violation reports** | Log lines only (never stored separately) | **Confidential** | URLs (PHI-free by design), directives, source file/line | Nothing to do beyond log handling |
| **Session files** `storage/framework/sessions/*` | Host | **Secret** | Session payloads | Web user only; cleared on incident; garbage-collected |
| **`.env` / Coolify environment** | Host / Coolify | **Secret** | `APP_KEY` (decrypts `mfa_secret` + `mail_password`), `DB_*`, `AUDIT_S3_*` | Never in chat, tickets, screenshots or git; rotate per INCIDENT-RESPONSE.md §7.4 |
| **Source code** | GitHub (private) + host checkout | **Confidential** | Application logic, security controls, no data by design | Private repo; MFA for collaborators [VERIFY]; no secrets committed (legacy history exposure remediated by rotation) |
| **Compiled assets** `public/build` | Host + repo | **Public** | JS/CSS | — |
| **Browser** | Staff devices | Page props are **Secret** while rendered | Rendered PHI | Idle timeout thirty minutes; no offline caching by design [VERIFY cache headers]; lock screen policy [PLACEHOLDER] |
| **E-mail bodies** (OTP, reset, username reminder, integrity alert) | Relay + mailboxes | **Confidential** | Staff e-mail, codes, links | Codes single-use and expiring |
| **OCI volume snapshots / clones** taken during incidents | OCI tenancy | **Secret** | Everything | Named `INC-…`; deleted only when the DPO releases the legal hold |
| **This documentation** | Repo `docs/compliance/` | **Confidential** | Security posture, contacts once filled | Do not publish outside the hospital |

---

## 4. Handling rules per level

"DCC ref." cells are for the security lead to fill with the exact control identifiers [VERIFY].

### 4.1 Secret

| Aspect | Rule | How it is met today | DCC ref. |
|---|---|---|---|
| Labelling | Every export file name, e-mail subject, printed sheet and document carries **SECRET — Patient data** (EN) / **سري — بيانات مرضى** (AR) | Not automated; exports are unlabeled (GAP — add a header row / PDF footer) | [VERIFY] |
| Access | Named individuals with a clinical or operational need; MFA always; admin-only for bulk views and exports; break-glass logging for record opens where enabled | Roles/capabilities, `admin` routes, mandatory MFA, `log_record_opens` (default OFF) | [VERIFY] |
| Storage | Encrypted at rest; keys separate from data; in-Kingdom only | **GAP for the MySQL volume (planned)**; audit archive in-Kingdom; backups (being built) to be encrypted | [VERIFY] |
| Transit | TLS 1.2+ everywhere; no plain-text protocols; no personal e-mail, chat or removable media | Cloudflare edge TLS 1.2 min + HSTS; SMTP TLS [VERIFY]; edge decryption is an open legal point (DPIA R5) | [VERIFY] |
| Sharing outside the hospital | Prohibited unless the DPO approves and a contract/legal basis exists; minimum necessary; pseudonymise where possible | No external sharing exists by design | [VERIFY] |
| Copies / exports | Only when needed for a defined task; recorded (automatically or manually); deleted when the task ends; never synced to personal cloud storage | Registry exports audited with row count; audit/statistics exports unaudited (GAP) | [VERIFY] |
| Printing | Only from the designated handover/service sheet; collected immediately; never left unattended; shredded (cross-cut) at end of shift or when superseded | Print pages exist; paper rule to be set [PLACEHOLDER] | [VERIFY] |
| Logging of access | All writes and PHI reads/exports audited with actor + IP; audit rows immutable and shipped off-box | `App\Support\Audit`, hash chain, hourly ship, nightly verify | [VERIFY] |
| Disposal | Database rows: per DATA-RETENTION.md; files: cryptographic erasure (delete + key destruction) or secure wipe; paper: cross-cut shredding; cloud objects: delete + confirm no versions/snapshots remain | See DATA-RETENTION.md §5 | [VERIFY] |
| Incidents | Any suspected exposure is at least SEV2 | INCIDENT-RESPONSE.md §2 | [VERIFY] |

### 4.2 Confidential

| Aspect | Rule | How it is met today | DCC ref. |
|---|---|---|---|
| Labelling | **CONFIDENTIAL** (EN) / **خاص** or **مقيد** per the hospital's convention (AR) [VERIFY term] | Not automated | [VERIFY] |
| Access | Hospital staff with a role-based need; MFA for system access | Roles; admin-only for audit/config/statistics | [VERIFY] |
| Storage | Access-controlled; encryption at rest recommended; in-Kingdom preferred | Same host as Secret data (inherits its controls) | [VERIFY] |
| Transit | TLS; hospital e-mail acceptable internally; staff personal data may leave the Kingdom only under a documented transfer safeguard | US SMTP relay carries staff e-mail/OTPs — safeguard [VERIFY] | [VERIFY] |
| Sharing | Internal on a need-to-know basis; external only under NDA/contract | — | [VERIFY] |
| Logging | Writes audited; reads not required | Config changes → `setting_changes` + `settings.*` audit rows; user changes → `user.*` | [VERIFY] |
| Disposal | Standard deletion; log rotation; paper recycling after shredding | `daily` log channel, `LOG_DAILY_DAYS` | [VERIFY] |

### 4.3 Public

No restrictions on disclosure; integrity still matters (a tampered ICD-10 table would corrupt
classification). Changes to reference tables go through migrations or the Control panel
(`specialty.add`, `reason.add` audited).

### 4.4 Top Secret

Not used. If such data ever needed to enter this system, the system would be out of scope and a
separate accreditation would be required [VERIFY].

---

## 5. Roles and responsibilities

| Role | Responsibility |
|---|---|
| Data owner — clinical (Head of IM) [PLACEHOLDER] | Confirms the classification of clinical data; sets the printing and free-text rules |
| Data owner — staff data (HR / System owner) [PLACEHOLDER] | Confirms classification of account data; departure process |
| Information-security lead [PLACEHOLDER] | Maintains this scheme; maps DCC control refs; verifies technical handling |
| DPO [PLACEHOLDER] | Approves any external sharing; owns breach decisions |
| Every user | Handles data per its level; reports suspected mishandling (INCIDENT-RESPONSE.md §5) |

---

## 6. Labelling, reclassification and review

- **Labelling:** exports and printed sheets should carry the level in both languages; until automated, users add it manually to file names (`SECRET-registry-2026-09.xlsx`) [PLACEHOLDER — engineering to add headers/footers].
- **Reclassification down** (e.g. Secret → Confidential after true anonymisation) requires the DPO's written confirmation that no individual can be re-identified, including by combination with other hospital data.
- **Reclassification up** is automatic under the inheritance rule and needs no approval.
- **Review:** annually, on any new table/column (each migration should state the level of any new personal-data column in its docblock — proposed convention), and after any incident.

---

## 7. Known gaps against the Secret handling rules

| Gap | Owner | Target | Tracked in |
|---|---|---|---|
| PHI not encrypted at rest inside MySQL | [PLACEHOLDER] | [PLACEHOLDER] | DPIA action 2 |
| No automated encrypted backup; manual dumps unencrypted on the host | [PLACEHOLDER] | [PLACEHOLDER] | DPIA action 1; DATA-RETENTION.md |
| Exports unlabeled; statistics and audit-log exports unaudited | [PLACEHOLDER] | [PLACEHOLDER] | DPIA action 8 |
| `log_record_opens` default OFF | [PLACEHOLDER — governance] | [PLACEHOLDER] | DPIA R6 |
| Cloudflare edge decryption legal status | [PLACEHOLDER — legal] | [PLACEHOLDER] | DPIA R5 |
| Paper handling rule for printed sheets | [PLACEHOLDER — clinical] | [PLACEHOLDER] | — |
| Legacy PHI dump copies not inventoried | [PLACEHOLDER] | [PLACEHOLDER] | DATA-RETENTION.md |

---

## 8. Change log

| Date | Version | Change | Author |
|---|---|---|---|
| 2026-09-03 | 0.1 | Initial draft | [PLACEHOLDER] |
