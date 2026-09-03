# DMC Internal Medicine — Database structure & action behavior

> How the data is stored, and exactly what every button/field on every page does to the database.
> Companion doc: **[DASHBOARD-AND-STATISTICS-METRICS.md](DASHBOARD-AND-STATISTICS-METRICS.md)** (how the numbers are calculated).
>
> Stack: Laravel 13 + Inertia/Vue 3 + MySQL. Schema is defined by `database/migrations/*` and is the
> authoritative source; the tables below are the live shape.

**Freshness — last verified 2026-09-03.** Re-read against: every migration in
`database/migrations/` (through `2026_09_03_000000_encrypt_clinical_narratives`), all 20 models in
`app/Models/`, `app/Casts/EncryptedNarrative.php`, `app/Support/{Audit,DashboardCache}.php`,
`routes/web.php`, `app/Http/Requests/*`, and the controllers `Admissions`, `Audit`, `Consultation‑
Dashboard`, `Consultations`, `Control`, `Dashboard`, `Handover`, `PatientAction`, `PatientMerge`,
`Patients`, `Recent`, `Registry`, `Reports`, `Statistics`, `Trashed`. Statements that were corrected
in this pass carry an inline `(verified against <file> on 2026-09-03)` note.

---

## 1. Database connections

| Connection | Database | Use |
|---|---|---|
| `mysql` (production) | `dmc_demo` (app user `dmc_demo`, not root) | The **live application database** — everything below is here. |
| `mysql` (local default) | `dmc_laravel` | Local development. |
| `mysql` (testing) | `dmc_test` | Disposable DB for the automated test suite (`RefreshDatabase`). |
| `legacy` (read-only) | `dmc_prod` | The old PHP app's data, read only during `php artisan legacy:import`. The running app never writes here. |

There is ONE `mysql` connection definition (`config/database.php`); which database it points at is
`DB_DATABASE` per environment. Every table uses InnoDB with real foreign keys and indexes (unlike
the legacy MyISAM/no-FK schema), plus named CHECK constraints on the admission dates and patient age
(`2026_06_14_010006_add_check_constraints`).

---

## 2. Tables (data dictionary)

### `users` — staff accounts & permissions
| Column | Type | Meaning |
|---|---|---|
| `id` | PK | |
| `username` | varchar | Login name (unique). |
| `name`, `full_name` | varchar | Display names. |
| `role` | tinyint | **0=Admin, 2=Registrar, 3=Consultant, 4=Resident, 5=Observer**. |
| `specialty_id` | FK→specialties | Consultant's specialty (1 = hospitalist convention). Also the consultation-ledger scoping key. |
| `active` | bool | Disabled accounts can't log in. |
| `on_service` | bool | Currently on the service (drives shuffle + dashboard grouping). |
| `can_assign`, `can_add`, `can_manage`, `can_modify` | bool | Capability flags (see §4). |
| `can_coordinate_consultations` | bool | **Fifth capability flag** (`2026_08_21_000400`): book a consult into ANY specialty, see and modify all consults. Deliberately NOT sign-off / delete / reverse-signoff. Default false; granted at cutover to every non-admin, non-observer user with a NULL `specialty_id` (`2026_08_21_000500`). |
| `email` | varchar | Nullable, **UNIQUE** (restored `2026_07_11_000002`; NULLs stay non-unique). Password-reset + verification identity. |
| `email_verified_at` | timestamp | NULL = must verify before any clinical page renders. |
| `password` | varchar | bcrypt hash. |
| `mfa_secret`, `mfa_recovery_codes`, `mfa_enrolled_at` | text/datetime | TOTP two-factor. NULL = not enrolled. `mfa_secret` is **encrypted at rest**; both secret columns are in `User::$hidden`. |
| `mfa_last_counter` | int | Last accepted TOTP time-step — replay guard (a code can't be reused within its ±90 s window). |
| `pass_exp_date` | date | Password set date; expiry gate = +3 months (NULL counts as expired). |
| `tour_completed_at` | datetime | First-login onboarding tour "seen" stamp. UI preference — not audited. |
| `remember_token` | varchar | Laravel column. Remember-me is **disabled** for MFA logins (see §5 Login). |
| `deleted_at` | timestamp | **Soft delete** (`2026_06_14_010001`). A soft-deleted user never fires the `nullOnDelete` FKs, so historical attribution survives. |
| `legacy_id` | int | Maps back to the old `members.member_id`. |

### `patients` — the person (one row per MRN)
| Column | Type | Meaning |
|---|---|---|
| `id` | PK | |
| `mrn` | varchar(64) | Medical record number — **unique**, indexed. |
| `name`, `gender`, `age`, `nationality` | — | Demographics. `age` carries CHECK `chk_age_range` (0–150). |
| `deleted_at` | timestamp | **Soft delete** (`2026_06_14_010007`) — the admin patient-merge tool retires the SOURCE patient this way, so the merge is recoverable. |

### `admissions` — one row per admission **episode** (the central table)
A re-admission or a ward↔ICU transfer creates a **new** row, so one patient = many admissions over time.
| Column | Type | Meaning |
|---|---|---|
| `id` | PK | The "patient" id used throughout the UI. |
| `patient_id` | FK→patients | |
| `bed`, `admitted_from`, `current_location` | — | `current_location` ∈ ER/Ward/ICU. |
| `admit_date` | date (idx) | |
| `consultant_id` | FK→users | Primary consultant. **NULL = unassigned** (shows on New Admissions queue). |
| `admitted_by`, `discharged_by` | FK→users | Audit attribution (session user, not spoofable). |
| `medical_discharge_date` | date | Phase-1 (medical) discharge → "discharged still in". |
| `discharge_date` | date (idx) | **NULL = active.** Phase-2 (complete) discharge / ICU discharge. |
| `discharge_to`, `outcome` | — | `discharge_to` = destination (Home / Other Facility / **LAMA / Absconded / Mortuary** / transfer targets). `outcome` is **strictly Alive/Dead** — non-death dispositions are *destinations*, never outcomes; a Dead outcome locks the destination to Mortuary (UI-enforced). |
| `delay_reason` | varchar | Why the bed isn't freed after medical discharge. |
| `transfer_type` | varchar | `discharge from ward` / `discharge from ICU` / `other transfer` / `transfer to other speciality` / `Transfer from ICU`. |
| `is_longterm`, `is_new_assignment` | bool | Long-term flag; sticky legacy "new" flag (set on assignment). |
| `assigned_on` | date | When the consultant was assigned. |
| `assigned_at` | timestamp | Precise assignment moment — drives the rolling 24-hour "New" badge (NULL on historical rows = not new). |
| `deleted_at` | timestamp | **Soft delete** (`2026_06_14_010001`) — the admin Delete action; recover from Recently Deleted. |
| `legacy_id` | int | Maps to old `picupatients.ID`. |

CHECK constraints (`2026_06_14_010006`): `discharge_date >= admit_date`,
`medical_discharge_date >= admit_date`, `discharge_date >= medical_discharge_date` — each applied
only when both dates are non-NULL.

### `admission_diagnoses` — ICD-10 codes per admission (replaces the legacy JSON array)
`id`, `admission_id` (FK, cascade), `seq`, `icd10_code`. One row per diagnosis, **unique per
admission** (`2026_06_09_000002`).

### `consultations` — the per-specialty consultation **ledger**
> Rewritten 2026-09-03: this table was a two-state referral log (open vs `signoff_date`) until the
> ledger migrations `2026_08_21_000100`–`000500`
> *(verified against `database/migrations/2026_08_21_000100_add_ledger_columns_to_consultations.php` on 2026-09-03)*.

| Column | Type | Meaning |
|---|---|---|
| `id` | PK | |
| `mrn`, `patient_name`, `age`, `bed`, `current_location` | — | Denormalised display copy taken at entry (legacy shape, retained). |
| `patient_id` | FK→patients (nullOnDelete) | Resolved from the picked patient or the typed MRN; NULL only when the user explicitly acknowledged an unmatched MRN. |
| `admission_id` | FK→admissions (nullOnDelete) | Ties the consult to the actual stay. Only attached when the admission really belongs to the resolved patient. |
| `consultation_date` | date (idx) | Legacy date-only request date — retained as the display/continuity value. |
| `requested_at` | datetime (idx) | The REAL request time. **NULL on every historical row** — never fabricated from a DATE — and stamped `now()` on everything the app creates. Also the "is this a legacy row" discriminator. |
| `consultation_from` | varchar | The requesting service. |
| `indication` | JSON | Array of `consultation_reasons` ids. |
| `other_indication` | varchar | Free text; required when reason id 0 ("Other") is picked. |
| `to_service` | varchar | The routing label the clinician reads (display value). |
| `owning_specialty_id` | FK→specialties (nullOnDelete) | **The scoping key** — resolved server-side from `to_service` by case-insensitive name match, never accepted from the payload. NULL = the "Unassigned" bucket. Re-resolved on edit only when `to_service` actually changes. |
| `status` | varchar(16), idx, default `new` | **`new` / `active` / `ongoing` / `signed_off`** (`Consultation::STATUS_*`). |
| `consultant_id` | FK→users (nullOnDelete) | Receiving consultant. Required only when `to_service` names an INTERNAL specialty. |
| `entered_by` | FK→users (nullOnDelete) | Who typed the record — session-sourced, immutable, independent of ownership. |
| `signoff_date` | date (idx) | Retained: every legacy report reads it. Written **in lockstep** with `status = signed_off`. |
| `signed_off_at` | datetime | Real sign-off time — cutover onward; NULL on historical rows. |
| `signed_off_by` | FK→users (nullOnDelete) | Session-sourced. |
| `response_disposition` | varchar(32) | The recorded outcome: `advice_given` / `taking_over` / `follow_up_arranged` / `no_further_input` (`ConsultationSignoffRequest::DISPOSITIONS`). |
| `response_followup_needed` | bool | Captured at sign-off. |
| `response_note` | text | The sign-off narrative — **encrypted at rest** (see below). |
| `deleted_at` | timestamp | Soft delete; admin Delete + restore from Recently Deleted. |
| `legacy_id` | int | |

**Backfill of the 1,283 historical rows** (`2026_08_21_000300`, one-time, guarded on
`requested_at IS NULL`): `signoff_date` set → `signed_off`; `signoff_date` NULL → **`ongoing`,
never `active`** (mapping them to `active` would have invented a launch-day worklist for every one).
`owning_specialty_id` resolved by name match against INTERNAL specialties only; unmatched rows stay
NULL. `requested_at` / `signed_off_at` left NULL — turnaround metrics must exclude NULLs explicitly.

**Legal status moves** (`ConsultationsController::STATUS_MOVES`): `new → active|ongoing`,
`active → ongoing`, `ongoing → active`, `signed_off → ` (frozen — an admin must reverse the sign-off
first). Sign-off is deliberately not reachable through the status endpoint.

### `consultation_followups` — the daily "seen today" tick
`id`, `consultation_id` (FK, cascade), `followup_date` (date), `note` (text, nullable,
**encrypted at rest**), `author_id` (FK→users, nullOnDelete), `created_at` (`useCurrent`).
**Append-only** — no `updated_at` (`ConsultationFollowup::UPDATED_AT = null`), and
`unique(consultation_id, followup_date)` makes double-ticking a day impossible, which is what keeps
"seen X of Y today" exact.

### Encryption at rest — the four narrative columns
`handovers.body`, `handover_revisions.body`, `consultations.response_note` and
`consultation_followups.note` are ciphertext in the database (`2026_09_03_000000_encrypt_clinical_narratives`
rewrote the existing rows in place; the models carry `App\Casts\EncryptedNarrative`). AES-256-CBC +
HMAC under `APP_KEY`. Operationally:

- **Never filter, sort, group or join on these columns in SQL**, and never put one in an export,
  notification, log, dashboard or email payload.
- **A raw read returns ciphertext.** `DB::table(...)` / `selectRaw` bypass the cast — decrypt
  explicitly. The one place that does so on purpose is the consultation handover sheet's
  latest-follow-up join (`ConsultationsController::handover`, `Crypt::decryptString`, NULL-safe).
- The cast is **tolerant on read**: a value that does not decrypt is served as-is and logged
  (table / id / column) rather than throwing, and becomes ciphertext on its next save through the
  model. A wrong `APP_KEY` therefore shows base64, not a 500 — the warning log is the signal.
- Also encrypted: `users.mfa_secret`, `settings.mail_password`. Both are in `$hidden`, because
  Eloquent DECRYPTS `encrypted` casts on `toArray()`. Full rules: `ENCRYPTION-AT-REST.md`.

### Handover subsystem (4 tables)
| Table | Use |
|---|---|
| `handovers` | The **current** handover per admission (unique `admission_id`, upserted on save): `body` (encrypted), `checkpoints` (JSON), `updated_by`, timestamps. `updated_at` drives the same-day gate and is stamped explicitly, so an unchanged body still satisfies it. |
| `handover_revisions` | Append-only history — every save adds one row (`body` encrypted, `checkpoints` JSON, `author_id`, `created_at`; no `updated_at`). |
| `handover_signatures` | Created on **consultant-to-consultant moves** (reassign, bulk reassign, internal specialty transfer): `from/to_consultant_id`, `revision_id`, `required_at`, `signed_at`/`signed_by`, `voided_at`. |
| `notifications` | In-app inbox feed (the bell): `user_id`, `type`, `admission_id` (indexed, no FK — reminders outlive their admission), `payload` JSON, `read_at`, `resolved_at`, `created_at`. |

`checkpoints` (both handover tables, `2026_07_13_010000`) holds the six clinical flags validated by
`HandoverController::save`: `vte_completed`, `ready_for_discharge`, `high_risk`, `needs_workup`,
`workup_pending`, and `code_status` ∈ `full|dnr|dni`.

Notification `type` values in use: `handover.transfer`, `handover.incomplete` (persistent — cleared
by `resolved_at`, not by read-all), `consultation.assigned`, `security.failed_logins`,
`audit.integrity_failure`, `report.ready`, `dq.daily_report`.

Behavior:
- **Same-day gate:** moving a patient to a *different* consultant expects their handover to have been
  updated **today** (`handovers.updated_at`). It is a **soft** gate (owner-approved): a stale or
  missing handover no longer blocks the move — it raises a persistent `handover.incomplete`
  reminder instead *(verified against `PatientActionController::assign` on 2026-09-03)*. First
  assignments from the unassigned queue are exempt. Bulk reassign checks per selected patient
  (`/handovers/preflight` shows per-admission freshness).
- **Signatures bind the latest revision:** each move pins `revision_id` to the latest handover
  revision at transfer time, and it is **re-bound at signing** to the revision the receiving
  consultant actually read. Only the primary consultant, a manager, or the outgoing consultant of a
  still-pending signature may update the handover.
- **Voiding:** a new move supersedes (voids) any pending signature on the admission; closing the
  patient's **last open episode** (complete/ICU/one-step discharge, delete) voids unsigned
  signatures **patient-wide** — including those parked on already-closed episodes (e.g. after a
  specialty transfer), so nothing dangles forever.
- **Bell / inbox:** the receiving consultant gets a `handover.transfer` notification (bell badge =
  unread count on every page) and an `/handovers` inbox listing signatures awaiting them plus
  their outgoing ones (last 7 days).

### Reference / config tables
| Table | Columns | Use |
|---|---|---|
| `settings` | single row — see below | Operational thresholds + runtime config (metrics doc for the clinical ones). |
| `setting_changes` | `field`, `old_value`, `new_value`, `changed_by`, `created_at` | Append-only history — one row per field that actually changed on a settings save (so "what was `ward_beds` in March?" is answerable). The SMTP password is recorded as `••••`. |
| `specialties` | `id`, `name`, `is_subspecialty`, `is_external` | Specialty list (id 1 = hospitalist). `is_external` = allied/external services (legacy `other_specialities`): transfer-OUT targets only — an external transfer closes the episode without opening a new one, and they never appear in internal-specialty pickers. **`is_external` does NOT restrict consultation ownership**: `resolveOwningSpecialtyId` matches on name across all specialties, because any service can be consulted. |
| `consultation_reasons` | `id`, `name` | Consultation indication options (id 0 = "Other" → free text required). |
| `report_recipients` | `email` (unique), `active`, `added_by_id`, `created_at` | Recipients of the scheduled monthly report email. |
| `tb_diagnoses` | `icd10_code` | ICD-10 codes that classify an admission as TB. |
| `icd10` | `code`, `name` | ICD-10 reference (~72k rows). |
| `countries` | `code`, `name` | Nationality reference. |
| `audit_log` | `actor_id`, `actor_name`, `action`, `entity_type`, `entity_id`, `details` (JSON), `ip`, `prev_hash`, `row_hash`, `created_at` | Append-only audit trail (see §5). **Table name is `audit_log`, singular** *(verified against `2026_06_08_120006_create_settings_and_audit.php` on 2026-09-03 — earlier revisions of this doc said `audit_logs`)*. Composite index on `(entity_type, entity_id)`; `prev_hash`/`row_hash` form the tamper-evident chain walked by `audit:verify`. |
| `pending_registrations` | `token` (unique), `email`, email-OTP lifecycle (`email_code_hash`, `email_code_expires_at`, `email_sent_at`, `email_send_count`, `email_attempts`, `email_verified_at`), TOTP provisioning (`totp_secret`, `totp_recovery_codes`, `totp_confirmed_at`), `expires_at` (30-min TTL) | Phased self-registration state. **No user row exists until both the email and an authenticator are confirmed**; the token lives in the session, never in a URL. |
| `trusted_devices` | `user_id`, `selector` (unique), `validator_hash` (SHA-256), `label`, `ip`, `expires_at`, `revoked_at`, `last_used_at` | Opt-in TOTP-skip for one browser. The cookie carries `selector:validator`; only the hash is stored. `expires_at` is a FIXED window, never extended by use; revocation sets `revoked_at` rather than deleting. |
| `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs` | Laravel framework tables | `sessions` is the live session store (`SESSION_DRIVER=database`); `cache` + `cache_locks` back the dashboard heavy-tier cache and its single-flight lock (§5). |

**`settings` columns** (single row, `Setting::current()` memoises it per request):

| Group | Columns (default) |
|---|---|
| Shuffle pools | `min_hospitalist` (6), `max_hospitalist` (30), `min_subs` (7), `max_subs` (7) |
| LOS bands | `short_los` (5), `long_los` (11) |
| Capacity | `ward_beds` (50), `icu_beds` (10) — **both still placeholders** |
| Clinical | `readmission_window_days` (3, clinically confirmed) |
| Dashboard alerts | `alert_overcensus_pct` (100), `alert_boarding_max` (5), `alert_readmit_rate_pct` (10), `alert_deaths_delta_pct` (50) — conservative placeholders, clinician-tunable |
| Sessions | `idle_timeout_minutes` (30), `abs_timeout_minutes` (0 = off) |
| Security | `mfa_enforcement` (0; **inert — MFA is mandatory regardless**), `mfa_trusted_device_hours` (24; 0 disables), `failed_login_notify_threshold` (5; 0 = off), `log_record_opens` (false) |
| Data quality | `dq_los_multiplier` (2 → flags active non-long-term episodes over `long_los × 2` days) |
| Audit | `audit_shipped_through_id` (0 — off-box shipping high-water mark), `audit_retention_years` (6 — gates the manual `audit:prune`) |
| Cutover | `consultations_source_of_truth` (false by default; **true in production**) — while true, `legacy:import` preserves the ledger instead of truncating it |
| Runtime config | `mail_mailer`, `mail_host`, `mail_port`, `mail_encryption`, `mail_username`, `mail_password` (encrypted + `$hidden` + write-only in the UI), `mail_from_address`, `mail_from_name`, `app_timezone`, `app_name`, `app_url` — all nullable; `.env` is the fallback, and `RuntimeConfigServiceProvider` applies them at boot |

---

## 3. Admission flow states (derived, not a column)

| State | Condition |
|---|---|
| **Unassigned** (New Admissions queue) | `discharge_date IS NULL AND consultant_id IS NULL` |
| **Active — Ward** | `discharge_date IS NULL`, `current_location ≠ ICU` |
| **Active — ICU** | `discharge_date IS NULL`, `current_location = ICU` |
| **Medically discharged ("still in")** | `discharge_date IS NULL`, `medical_discharge_date` set |
| **Long-term** | `is_longterm = 1` (cross-cutting) |
| **TB** | any diagnosis ∈ `tb_diagnoses` (cross-cutting) |
| **Discharged** | `discharge_date` set |

Every analytic additionally excludes `deleted_at IS NOT NULL` explicitly — the raw `DB::table`
queries in Dashboard/Statistics/Reports bypass the SoftDeletes global scope.

---

## 4. Authorization model

- **Page access** by `role`: clinical pages = Admin/Registrar/Consultant/Resident; Observer (5) =
  read-only everywhere (refused *before* any capability flag is consulted); admin pages (Registry,
  Statistics, Reports, Recent undo, Import, Control, Audit viewer, Trash, Security, Data quality,
  Patient merge, Style guide) = Admin only, on the routes guarded by `admin` middleware.
- **Per-action** by capability: `can_assign` (assign to a chosen consultant, shuffle, bulk reassign),
  `can_add` (admit / admission-from-ICU), `can_manage` (transfer/discharge any patient), `can_modify`
  (Modify patient details), `can_coordinate_consultations` (book into any specialty, see and modify
  all consults — **never** sign-off, delete or reverse-signoff). The **primary consultant** of an
  admission can manage/discharge their own patient even without `can_manage`.
- **Consultation visibility** is one rule, expressed twice and kept in lockstep:
  `Consultation::scopeVisibleTo` (list queries) mirrors `User::canSeeConsultation` (per row) —
  observers refused; admin/coordinator see everything; everyone else sees their own
  `owning_specialty_id`, plus anything assigned to them or entered by them.
  `scopeRecordableBy` / `User::canRecordFollowup` is the narrower WRITE-side twin: identical minus
  the `entered_by` clause, so the daily worklist only offers rows the viewer can actually clear.
- **Middleware chain:** `auth → session.timeout → email.verify → mfa.enroll → pwd`. Every user needs
  a verified email, an enrolled TOTP authenticator and a password younger than three months (NULL
  counts as expired) before any clinical page renders. `admin` nests inside that group.
  `stepup` (fresh password re-check, throttled) additionally guards: reverse discharge, delete
  admission, Control → System save, Control → System test email, delete user, patient merge
  *(verified against `routes/web.php` on 2026-09-03)*.
- Every state-changing endpoint re-checks authorization server-side (not just hidden buttons);
  the riskier writes carry a FormRequest whose `authorize()` fires the 403 **before** validation.

---

## 5. What every action writes to the database

> All state-changing endpoints are POST/PUT/DELETE, CSRF-protected, and write an
> `audit_log` row `{actor_id, actor_name, action, entity_type, entity_id, details, ip}` through
> `App\Support\Audit::log()` — one writer, actor + IP always from the session/request, the append
> wrapped in a transaction so the hash-chain lock spans both statements. (The pre-session auth paths
> — `login.failed`, `login`, `mfa.*`, `password.change` — build the row with `AuditLog::create()`
> directly, because there is no authenticated actor for `Audit::log()` to read.) **Reads are audited too**
> where they expose PHI: registry searches, every export and report PDF, handover reads and record
> opens (the last two only when `log_record_opens` is ON). "Attribution" columns (`admitted_by`,
> `discharged_by`, `entered_by`, `author_id`, `signed_off_by`, `updated_by`) are always taken from
> the **logged-in session**, never from the payload.

### New Admissions  (`/admissions`)
| Button / field | Endpoint | Database effect · audit action |
|---|---|---|
| **Admit patient** (form on `/admissions/create`) | POST `/admissions` | INSERT `patients` (if the MRN is new) + INSERT `admissions` (location, bed, admit_date, admitted_from, `admitted_by`=you, `consultant_id`=NULL) + INSERT one `admission_diagnoses` row per ICD-10 picked. → `admission.create` |
| **Admission from ICU** → "To ward" | POST `/admissions/{id}/icu-pull` | Gated by `can_add` (not `can_manage`); the ICU episode must still be active. Transaction: close the ICU episode (`discharge_date`=today, `transfer_type='Transfer from ICU'`, `discharge_to='Ward'`, `medical_discharge_date`=today, `outcome='Alive'`, `delay_reason`=NULL, `discharged_by`=you) + INSERT a new **unassigned** Ward admission (`consultant_id`=NULL, no bed, `admitted_from='ICU'`, diagnoses carried) so the patient re-enters the assignment queue, + void pending signatures on the closed episode. → `admission.icu_pull` *(verified against `PatientActionController::icuPull` on 2026-09-03 — the doc previously described this as `/transfer` carrying the consultant over)* |
| **Assign to primary** (pick consultant) | POST `/admissions/{id}/assign` | UPDATE `consultant_id`; with `mark_new` (default true) also `is_new_assignment=1`, `assigned_on`=today, `assigned_at`=now. `mark_new=false` = quiet administrative assignment, leaving those three untouched. A move between *different* consultants also INSERTs a `handover_signatures` row + a `handover.transfer` `notifications` row, and raises `handover.incomplete` reminders when the handover is stale. → `admission.assign` |
| **Assign to me** | POST `/admissions/{id}/assign-to-me` | UPDATE `consultant_id`=you + `is_new_assignment`, `assigned_on`, `assigned_at`. **Unassigned queue only** — an already-assigned patient must go through Assign/Transfer so the gate + signature apply. Open to ANY clinical role, never Observer. → `admission.assign_to_me` |
| **Shuffle / auto-assign** | POST `/admissions/shuffle` | UPDATE `consultant_id` on each unassigned active admission, balancing across on-service consultants (`ShuffleService`, settings min/max pools). → `admission.shuffle` |
| **Edit (Modify)** a queued patient | POST `/admissions/{id}/modify` | See "Modify" below (same endpoint). |

### Active Patients board  (`/patients`)  — shows assigned active patients only
| Button / field | Endpoint | Database effect · audit action |
|---|---|---|
| **Reassign consultant** | POST `/admissions/{id}/assign` | As above (same handover gate, signature and reminders). → `admission.assign` |
| **Modify** | POST `/admissions/{id}/modify` | Transaction: UPDATE `patients` (mrn, name, age, gender, nationality) + UPDATE `admissions` (`bed`, `admit_date`, `admitted_from`, `current_location`) + DELETE all `admission_diagnoses` for the admission and re-INSERT the chosen codes. An optional `consultant_id` QUIETLY moves the assignment — no `is_new_assignment`/`assigned_*` stamps (legacy Modify semantics), recorded in the audit diff. Changing the MRN to one that already exists RE-POINTS the admission to that patient instead (separate `patient.repoint` row). The GET `/admissions/{id}/edit` JSON prefill is admin/`can_modify` ONLY and writes `registry.open` when `log_record_opens` is on. → `patient.modify` *(field list verified against `PatientActionController::modify` on 2026-09-03 — the doc previously omitted `admit_date`/`admitted_from` and the re-point path)* |
| **Bed** (inline edit) | POST `/admissions/{id}/bed` | UPDATE `admissions.bed`. Any clinical role. → `admission.bed` |
| **Long-term** toggle | POST `/admissions/{id}/longterm` | UPDATE `admissions.is_longterm` (flip). → `admission.longterm` |
| **Transfer** (Ward↔ICU) | POST `/admissions/{id}/transfer` | Transaction: close the current episode as a transfer (`discharge_date`=today, `transfer_type`, `discharge_to` = `Intensive Care (ICU)`/`Ward`, `medical_discharge_date`=today, `outcome='Alive'`, `delay_reason`=NULL, `discharged_by`=you) + INSERT a new admission in the target location (same patient/consultant, carried diagnoses). The new episode **carries the bed** and stamps `admitted_from` with the source side. → `admission.transfer` |
| **Specialty transfer** (internal) | POST `/admissions/{id}/transfer` | Closes the episode and opens a new one keeping the ORIGINAL `admitted_from`, forcing `current_location='Ward'` and carrying the bed. Creates a handover signature when the consultant changes. → `admission.transfer_specialty` |
| **Specialty transfer** (external service) | POST `/admissions/{id}/transfer` | Closes the episode **without** reopening one — except a transfer to `'Intensive Care (ICU)'`, which ALSO opens the receiving ICU episode (same consultant, bed + diagnoses carried, `admitted_from='Ward'`, `assigned_on`=today but NOT new-flagged) so the patient stays on the census. → `admission.transfer_external` |
| **Discharge** (ward, not yet medically discharged) | POST `/admissions/{id}/medical-discharge` | UPDATE `medical_discharge_date`, `outcome`, `discharge_to`, `delay_reason`, `discharged_by`; a one-step variant also closes the episode. (Patient stays on the board as "discharged still in".) → `admission.medical_discharge` / `admission.discharge_both` |
| **Complete discharge** | POST `/admissions/{id}/complete-discharge` | UPDATE `discharge_date`, `transfer_type` (`discharge from ward`/`ICU`), `discharged_by`. Voids unsigned signatures patient-wide if this was the last open episode. Leaves the board. → `admission.complete_discharge` |
| **ICU discharge** (ICU patients) | POST `/admissions/{id}/icu-discharge` | UPDATE `discharge_date`, `outcome`, `transfer_type='discharge from ICU'`, `discharged_by`. → `admission.icu_discharge` |
| **Undo medical discharge** | POST `/admissions/{id}/undo-medical-discharge` | Clears `medical_discharge_date` (and the fields captured with it). → `admission.undo_medical_discharge` |
| **Reverse discharge** (Admin, step-up) | POST `/admissions/{id}/reverse-discharge` | UPDATE clears `discharge_date`, `medical_discharge_date`, `delay_reason`, `outcome` (back to active). **Same-day discharges only.** Audit detail carries `step_up`. → `admission.reverse_discharge` |
| **Delete** (Admin, step-up) | DELETE `/admissions/{id}` | **Soft delete** — sets `deleted_at`; the audit row is written FIRST inside the transaction, and pending signatures are voided. Diagnoses are untouched (hidden with their parent). Recover from `/trashed`. → `admission.delete` |
| **Shuffle / Bulk reassign** (toolbar) | POST `/admissions/shuffle` · `/admissions/reassign` | Shuffle (above); Bulk reassign UPDATEs `consultant_id` for the **selected subset** (`admission_ids[]`) of one consultant's active admissions, creating a signature + notification per moved patient and reminders for stale handovers. → `admission.bulk_reassign`, `handover.reassign_incomplete` |
| **Active List** (printable census) | GET `/active-list` | Read only. Not audited (same posture as the consultation handover sheet). |

Every write above also **busts the dashboard heavy-tier cache** (below).

### Consultations ledger  (`/consultations`)
> Rewritten 2026-09-03 — the previous table described the pre-ledger two-state model
> *(verified against `ConsultationsController` and `routes/web.php` on 2026-09-03)*.

| Button / field | Endpoint | Database effect · audit action |
|---|---|---|
| **New Consultation** | POST `/consultations` | INSERT `consultations`: the validated payload + `patient_id`/`admission_id` re-derived server-side, `owning_specialty_id` resolved from `to_service`, `requested_at`=now, `entered_by`=you, `status` = the `new` default, `indication` JSON. A **coordinator or admin** booking into someone else's book also INSERTs a `consultation.assigned` notification. → `consultation.create` (+ `consultation.assign` when notified) |
| **Move status** (new→active, new→ongoing, active⇄ongoing) | POST `/consultations/{id}/status` | UPDATE `consultations.status`. Refuses any move not in the legal set, and freezes on **either** `status='signed_off'` **or** a non-NULL `signoff_date` (legacy imports write the date without the status). JSON 422 on an illegal move. Gate = `canModifyConsultation`. → `consultation.status_change` (`{from, to}`) |
| **Tick "seen today"** (worklist) | POST `/consultations/{id}/followup` | Transaction: INSERT one `consultation_followups` row (`followup_date`=today, encrypted `note`, `author_id`=you) — and, if the consult was `new`, UPDATE `status='active'` (the single automatic transition in the design). One tick per consult per day, enforced by the unique index; a repeat answers 422, never overwrites. Gate = `canRecordFollowup` (narrower than read). → `consultation.followup` (+ a second `consultation.status_change` with `reason: first_followup` on promotion) |
| **Sign off** | POST `/consultations/{id}/signoff` | UPDATE `status='signed_off'`, `signoff_date`=today, `signed_off_at`=now, `signed_off_by`=you, `response_disposition`, `response_followup_needed`, encrypted `response_note`. Gate = `canManageConsultation` in `ConsultationSignoffRequest::authorize()` (admin / `can_manage` / receiving consultant — **coordinators are refused by design**). → `consultation.signoff` (disposition + followup flag only; the note is never in the detail JSON) |
| **Undo sign-off** (Admin, same day) | POST `/consultations/{id}/reverse-signoff` | UPDATE clears the WHOLE closing entry — `signoff_date`, `signed_off_at`, `signed_off_by`, `response_disposition`, `response_followup_needed`, `response_note` — and returns `status='ongoing'` (back on the books, no daily commitment invented). The cleared response, note included, is preserved in the audit detail. Same-day only. → `consultation.reverse_signoff` |
| **Edit** | PUT `/consultations/{id}` | UPDATE the editable fields + `indication`; `owning_specialty_id` is re-resolved **only** when `to_service` actually changed. `patient_id`/`admission_id`/`unmatched_mrn_ack` are stripped — relinking a filed consult to another patient is a re-identification, not an edit. A changed `consultant_id` raises a `consultation.assigned` notification. → `consultation.modify` (field-level diff) |
| **Delete** (Admin) | DELETE `/consultations/{id}` | **Soft delete** — sets `deleted_at`. Restorable from `/trashed`. → `consultation.delete` |
| **Workspace / tabs / search** | GET `/consultations` · POST `/consultations/search` | Read only. Four status tabs, per-tab counts computed through the SAME visibility scope as the list, plus today's follow-up worklist. The free-text term rides a POST body (SPC-TM-011). |
| **Service handover sheet** | GET `/consultations/handover` | **Read only, writes nothing and is not audited** (same rows the viewer already sees on `/consultations`, mirroring the printable Active List). Lists the viewer's service's `active` + `ongoing` consults (`signoff_date IS NULL` as a belt) with each one's latest follow-up note, oldest request first, grouped by owning service. The note is decrypted explicitly because the latest-follow-up join is a raw query. |
| **Physician dashboard** | GET `/consultations/dashboard` | **Read only, not audited.** Open counts, ageing, today's completeness, turnaround, 6-month volume trend, top indications, per-consultant load — all through `baseQuery()`, which pins `scopeVisibleTo` + an explicit `whereNull(deleted_at)` + the optional specialty narrowing. Only an admin or coordinator may pass `?specialty_id=`. |

### Registry  (`/registry`)  — Admin
| Button / field | Endpoint | Database effect · audit action |
|---|---|---|
| Mode tabs, all filters, search | GET `/registry` · POST `/registry` | No data change, but **every search writes one break-glass audit row**: mode, redacted filters (free-text terms reduced to a length), result count — zero-result searches included. → `registry.search` |
| Pre-export row count | GET/POST `/registry/count` | Read only, not audited (a cheap COUNT for the export advisory). |
| **CSV export** | GET/POST `/registry/export` | Streams `SECRET-Export-DD-MM-YYYY.csv`; cells starting `= + - @` are apostrophe-prefixed against formula injection. Writes an audit row (mode, redacted filters, row count). → `registry.export` |
| **Excel export** | GET/POST `/registry/export-xlsx` | Same, as `SECRET-Export-DD-MM-YYYY.xlsx`. → `registry.export_xlsx` |
| **Edit** (from a result row) | POST `/admissions/{id}/modify` | Same as Modify above. |

### Statistics & Reports  (Admin)
| Button | Endpoint | Database effect · audit action |
|---|---|---|
| Statistics page | GET `/statistics` | Read only, not audited. |
| **Statistics → Excel** | GET `/statistics/export` | `CONFIDENTIAL-dmc-statistics-{from}-{to}.xlsx`. Audit detail = date range, interval and the row counts of every sheet emitted (never cell values). → `statistics.export.xlsx` |
| **Statistics → PDF** | GET `/statistics/export/pdf` | `CONFIDENTIAL-dmc-statistics-{from}-{to}.pdf` (no interval-series sheet). → `statistics.export.pdf` |
| **Annual booklet PDF** | GET `/reports/pdf` | `CONFIDENTIAL-dmc-annual-report-{year}.pdf`. → `report.pdf.annual` |
| **Monthly report PDF** | GET `/reports/monthly/pdf` | `CONFIDENTIAL-dmc-monthly-report-{year}.pdf`. The `?async=1` variant only dispatches a job (no file, so no export row) — the eventual download is logged instead. → `report.pdf.monthly` |
| **Queued booklet download** | GET `/reports/pdf-download/{key}` | Streams the stored PDF from the private `local` disk as `CONFIDENTIAL-{filename}` and deletes it after send. → `report.pdf.download` |
| **Per-consultant scorecard PDF** | GET `/reports/consultant/{user}/pdf` | `CONFIDENTIAL-scorecard-{userId}-{from}-{to}.pdf`. → `report.pdf.consultant` |
| **Governance / M&M PDF** | GET `/reports/governance/pdf` | `SECRET-governance-{year}-{Qn}` or `-{MM}.pdf` — **row-level MRN line lists**, hence the SECRET prefix. → `report.pdf.governance` |

Classification convention (`DATA-CLASSIFICATION.md` §4/§6): a **`SECRET-`** filename prefix means the
file carries row-level patient (or audit-trail) data; **`CONFIDENTIAL-`** means aggregate-only.
*(This whole block is new — the doc previously said the export/report endpoints "write nothing";
verified against `RegistryController`, `AuditController`, `StatisticsController` and
`ReportsController` on 2026-09-03.)*

### Audit viewer  (`/audit`, Admin)
| Button | Endpoint | Database effect · audit action |
|---|---|---|
| Filter / browse | GET `/audit` | Read only. |
| **CSV / Excel export** | GET `/audit/export` · `/audit/export-xlsx` | `SECRET-Audit-Export-DD-MM-YYYY.{csv,xlsx}`. Exporting the trail is itself a read-of-audit-trail event, so it writes its own row (verbatim non-PHI filters + row count). → `audit.export.csv` / `audit.export.xlsx` |

### Handovers
| Button | Endpoint | Database effect · audit action |
|---|---|---|
| **Open handover** | GET `/admissions/{id}/handover` | Read only. Writes `handover.read` **only when `log_record_opens` is ON** *(verified against `HandoverController::show` on 2026-09-03 — it is not unconditional)*. |
| **Save handover** | POST `/admissions/{id}/handover` | Transaction: upsert `handovers` (encrypted `body`, `checkpoints`, `updated_by`, explicit `updated_at`) + INSERT a `handover_revisions` row. Then resolves any open `handover.incomplete` notifications for the admission (`resolved_at`). Gate: `canManageAdmission` OR the outgoing consultant of a pending signature. → `handover.update` |
| **Sign** / **Sign many** | POST `/handovers/{signature}/sign` · `/handovers/sign-many` | UPDATE `handover_signatures` (`signed_at`, `signed_by`, `revision_id` re-bound to the revision actually read). → `handover.sign` |
| **Mark notifications read** | POST `/notifications/read-all` | UPDATE `notifications.read_at` for the user's non-`handover.incomplete` rows. Reminder rows are cleared by `resolved_at` only. Notification rows are **never deleted**. |

### Recent activity  (`/recent`) — lists YESTERDAY + TODAY
The VIEW is read-only and open to every clinical role (including Observer); the UNDO actions are
Admin-only, enforced inside the reverse controllers rather than by route middleware.
| Button | Endpoint | Database effect |
|---|---|---|
| **Undo discharge** | POST `/admissions/{id}/reverse-discharge` | Clears the discharge fields (above). Same-day rows only; step-up gated. |
| **Undo sign-off** | POST `/consultations/{id}/reverse-signoff` | Clears the **entire** closing entry and returns the consult to `ongoing` (above). Same-day rows only. |

### Bulk import  (`/import`, Admin)
| Step | Endpoint | Database effect · audit action |
|---|---|---|
| **Preview** | POST `/import/preview` | **Read only** — parses + validates rows, returns valid/invalid lists. No writes. |
| **Confirm import** | POST `/import` | Transaction: for each valid row, INSERT `patients` (if new) + `admissions` (+ diagnoses). Optional column 18 `TransferType` (`transfer to other speciality` / `other transfer` / `Transfer from ICU`; requires a discharge date) OVERRIDES the location-derived discharge `transfer_type`. → `import.bulk` |

### Admin tooling
| Page / action | Endpoint | Database effect · audit action |
|---|---|---|
| **Recently Deleted → Restore** | POST `/trashed/{admissions\|consultations\|users}/{id}/restore` | Clears `deleted_at`. Restoring an admission is refused when it would create a second ACTIVE admission for the same patient/MRN. → `admission.restore` / `consultation.restore` / `user.restore` |
| **Patient merge** (step-up) | POST `/admin/patient-merge` | Transaction: re-points the source patient's admissions and consultations to the target, then **soft-deletes** the now-empty source. → `patient.merge` |
| **Data quality**, **Security panel**, **Orphan diagnoses** | GET `/data-quality` · `/security` · `/admin/orphan-diagnoses` | Read-only reports. No writes, not audited. |

### Control panel  (`/control`, Admin)
| Button / field | Endpoint | Database effect · audit action |
|---|---|---|
| **Save settings** (pools, LOS, beds, readmission window, alert thresholds, session timeouts, failed-login threshold, trusted-device hours, DQ multiplier, `log_record_opens`, `consultations_source_of_truth`, MFA policy) | PUT `/control/settings` | INSERT one `setting_changes` row per field that actually changed (booleans normalised to `1`/`0`), then UPDATE the single `settings` row. → `settings.update` |
| **Save system config** (SMTP / timezone / app basics; step-up) | PUT `/control/system` | Same `setting_changes` + `settings` update, with `mail_password` **write-only**: a blank submit keeps the stored value, and both the history row and the audit detail record `••••` / `changed`, never the value. Validation failures re-flash input *without* the password. → `settings.system.update` |
| **Send test email** (step-up) | POST `/control/system/test-email` | Sends to the acting admin's own address using the current mail config. No DB change. |
| **Edit user** (role, active, on-service, specialty, capabilities incl. `can_coordinate_consultations`) | PUT `/control/users/{id}` | UPDATE `users` (guards against self-demotion; granting admin requires step-up). → `user.update` (field diff) |
| **Delete user** (step-up) | DELETE `/control/users/{id}` | **Soft delete** — sets `deleted_at`; you cannot delete your own account. → `user.delete` |
| **Reset MFA** | POST `/control/users/{id}/reset-mfa` | UPDATE clears `mfa_secret`, `mfa_recovery_codes`, `mfa_enrolled_at`. → `user.reset_mfa` |
| **Send reset email** | POST `/control/users/{id}/send-reset` | INSERT a `password_reset_tokens` row + send mail. No change to the user row. → `user.send_reset` |
| **Add specialty** | POST `/control/specialties` | INSERT `specialties`. → `specialty.add` |
| **Add indication** | POST `/control/reasons` | INSERT `consultation_reasons`. → `reason.add` |
| **Add / remove report recipient** | POST `/control/report-recipients` · DELETE `/control/report-recipients/{id}` | INSERT / DELETE `report_recipients`. → `report_recipient.add` / `report_recipient.remove` |

### Profile & Auth
| Button / field | Endpoint | Database effect |
|---|---|---|
| **Save profile** (name, email) | PUT `/profile` | UPDATE your own `users` row (name, email). |
| **Change password** | PUT `/profile/password` | UPDATE `password` + `pass_exp_date`=today. |
| **Revoke trusted device(s)** | DELETE `/profile/trusted-devices[/{device}]` | UPDATE `trusted_devices.revoked_at` (scoped to `Auth::id()` inside the controller — the `{device}` segment is a filter, not an authorization claim). |
| **Enable 2FA** | POST `/mfa/confirm` | UPDATE `mfa_secret`, `mfa_recovery_codes`, `mfa_enrolled_at`. Self-disable was REMOVED (owner decision) — once enrolled, only an admin can clear MFA via Control → Reset MFA. |
| **Login** | POST `/login` | Reads `users` by username **or** email; on success regenerates the session. **An MFA login is never remembered** (`Auth::login(.., false)` always), and `remember_token` is rotated on enrolment. With MFA enrolled, the pending challenge expires after **5 min** and allows **8 code attempts**, replay-guarded by `mfa_last_counter`. Failed attempts are throttled by IP **and** username, and write `login.failed` audit rows that drive the `security.failed_logins` admin notification. |
| **Register** (phased) | POST `/register/email/send` → `/register/email/verify` → `/register/mfa/provision` → `/register/mfa/confirm` → `/register` | The first four steps read/write **`pending_registrations` only — no `users` row exists yet**. The final POST creates the user with `active=0` (pending admin activation), `role` ∈ {2,3,4,5} (never Admin), `pass_exp_date`=today, and the confirmed TOTP secret + hashed recovery codes carried over. Every step is throttled. |
| **Forgot / reset password** | POST `/forgot-password` · `/reset-password` | Forgot: INSERT `password_reset_tokens` + email. Reset: UPDATE `password` + `pass_exp_date`. |
| **Forgot username** | POST `/forgot-username` | Read only + email. |
| **Session timeout** | (middleware) | An idle/absolute timeout logs the user out and writes `session.timeout`. |

### Dashboard
Read-only aggregations (see the metrics doc). Two tiers *(new 2026-09-03 — verified against
`DashboardController` and `App\Support\DashboardCache`)*:

- **Live tier — never cached:** today's census, occupancy, boarding, alerts, my-unit, recent.
- **Heavy tier — cached** under the single key `dashboard.heavy` for **300 s + up to 30 s of random
  jitter** (so expiries do not line up), behind a **single-flight lock** (`cache_locks`) so only one
  request recomputes on a miss; a lock timeout re-reads the key before falling through to a plain
  recompute. Contents: the 31-day admissions-vs-discharges trend, the 6-month consultations-vs-
  sign-offs chart, the YTD LOS distribution, top diagnoses and the per-consultant board.
- **Busted on every write** that could move those numbers: `Admission` and `Consultation` model
  `saved`/`deleted` events, plus explicit `DashboardCache::bust()` calls on the
  `PatientActionController` query-builder paths that bypass model events, on patient merge, and on
  restore from Recently Deleted. So a clinician's own action shows immediately, not after the TTL.

---

## 6. Notes

- **Soft deletes are the norm.** `users`, `patients`, `admissions` and `consultations` all carry
  `deleted_at`; the admin Delete actions set it and the Recently Deleted page restores it. Routine
  flow never destroys rows *(verified against `PatientActionController::destroy`,
  `ConsultationsController::destroy` and `TrashedController` on 2026-09-03 — the doc previously
  described the consultation Delete as a hard DELETE)*. Recovery beyond the trash page is via
  backups.
- **`audit_log` and `notifications` rows are never deleted** by the application. `audit:prune` is an
  operator-only, `--confirm`-gated command and is never scheduled.
- **Transactions** wrap the multi-row writes (transfer, modify, import, follow-up + promotion,
  sign-off reversal, delete + signature voiding, merge) so a partial failure rolls back. The audit
  append is itself transactional, so the hash chain cannot fork under concurrency.
- **`status` and `signoff_date` must move together.** Every guard that matters (status moves,
  follow-ups, the handover sheet, the physician dashboard's open query) checks **both**, because
  `legacy:import` writes `signoff_date` without `status` — after a reload with the cutover flag off,
  closed legacy consults would otherwise reappear as open work.
- The legacy spoofable `userid` POST fields are gone — attribution is session-sourced everywhere.
- **Ambiguities left open, not guessed:**
  `settings.mfa_enforcement` still exists and is validated/saved by Control, but MFA is mandatory for
  every user regardless, so the column has no runtime effect;
  `admissions.is_new_assignment` (sticky) and `assigned_at` (rolling 24 h) both survive, and the code
  reads the timestamp for the badge while the boolean is still written — which of the two is intended
  to be authoritative long-term is not settled in code;
  `Consultation::scopeActive` (`signoff_date IS NULL`) is unused in `app/` but retained because
  statistics/dashboard code still reads `signoff_date` directly rather than `status`.
