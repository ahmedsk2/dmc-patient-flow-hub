# DMC Internal Medicine — Database structure & action behavior

> How the data is stored, and exactly what every button/field on every page does to the database.
> Companion doc: **[DASHBOARD-AND-STATISTICS-METRICS.md](DASHBOARD-AND-STATISTICS-METRICS.md)** (how the numbers are calculated).
>
> Stack: Laravel 13 + Inertia/Vue 3 + MySQL. Schema is defined by `database/migrations/*` and is the
> authoritative source; the tables below are the live shape.

---

## 1. Database connections

| Connection | Database | Use |
|---|---|---|
| `mysql` (default) | `dmc_laravel` | The **live application database** — everything below is here. |
| `legacy` (read-only) | `dmc_prod` | The old PHP app's data, read only during the one-time `php artisan legacy:import`. The running app never writes here. |
| `mysql` (testing) | `dmc_test` | Disposable DB for the automated test suite (`RefreshDatabase`). |

Every table uses InnoDB with real foreign keys and indexes (unlike the legacy MyISAM/no-FK schema).

---

## 2. Tables (data dictionary)

### `users` — staff accounts & permissions
| Column | Type | Meaning |
|---|---|---|
| `id` | PK | |
| `username` | varchar | Login name (unique). |
| `name`, `full_name` | varchar | Display names. |
| `role` | tinyint | **0=Admin, 2=Registrar, 3=Consultant, 4=Resident, 5=Observer**. |
| `specialty_id` | FK→specialties | Consultant's specialty (1 = hospitalist convention). |
| `active` | bool | Disabled accounts can't log in. |
| `on_service` | bool | Currently on the service (drives shuffle + dashboard grouping). |
| `can_assign`, `can_add`, `can_manage`, `can_modify` | bool | Capability flags (see §4). |
| `email` | varchar | For password-reset email. |
| `password` | varchar | bcrypt hash. |
| `mfa_secret`, `mfa_recovery_codes`, `mfa_enrolled_at` | text/datetime | TOTP two-factor. NULL = not enrolled. |
| `pass_exp_date` | date | Password set date; expiry gate = +3 months. |
| `remember_token` | varchar | "Remember me" cookie token. |
| `legacy_id` | int | Maps back to the old `members.member_id`. |

### `patients` — the person (one row per MRN)
| Column | Type | Meaning |
|---|---|---|
| `id` | PK | |
| `mrn` | varchar | Medical record number (patient identity). |
| `name`, `gender`, `age`, `nationality` | — | Demographics. |

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
| `discharge_to`, `outcome` | — | Destination; outcome ∈ Alive/Dead/LAMA/DAMA/Transferred. |
| `delay_reason` | varchar | Why the bed isn't freed after medical discharge. |
| `transfer_type` | varchar | `discharge from ward` / `discharge from ICU` / `other transfer` / `Transfer from ICU`. |
| `is_longterm`, `is_new_assignment` | bool | Long-term flag; "new" badge (set on assignment). |
| `assigned_on` | date | When the consultant was assigned. |
| `legacy_id` | int | Maps to old `picupatients.ID`. |

### `admission_diagnoses` — ICD-10 codes per admission (replaces the legacy JSON array)
`id`, `admission_id` (FK), `seq`, `icd10_code`. One row per diagnosis.

### `consultations` — referrals to/from other services
`id`, `mrn`, `patient_id`, `patient_name`, `age`, `bed`, `current_location`, `consultation_date`,
`consultation_from`, `to_service`, `indication` (JSON of `consultation_reasons` ids), `other_indication`,
`consultant_id` (receiving), `entered_by`, `signoff_date` (NULL = open), `legacy_id`.

### Reference / config tables
| Table | Columns | Use |
|---|---|---|
| `settings` | single row: `min/max_hospitalist`, `min/max_subs`, `short_los`, `long_los`, **`ward_beds`**, **`icu_beds`**, `mfa_enforcement` | Operational thresholds (see metrics doc). |
| `specialties` | `id`, `name`, `is_subspecialty` | Specialty list (id 1 = hospitalist). |
| `consultation_reasons` | `id`, `name` | Consultation indication options. |
| `tb_diagnoses` | `icd10_code` | ICD-10 codes that classify an admission as TB. |
| `icd10` | `code`, `name` | ICD-10 reference (~72k rows). |
| `countries` | `code`, `name` | Nationality reference. |
| `audit_logs` | `actor_id`, `actor_name`, `action`, `entity_type`, `entity_id`, `details` (JSON), `ip`, timestamps | Append-only audit trail (see §5). |
| `password_reset_tokens`, `sessions`, `cache`, `jobs` | Laravel framework tables. |

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

---

## 4. Authorization model

- **Page access** by `role`: clinical pages = Admin/Registrar/Consultant/Resident; Observer (5) = read-only board; admin pages (Registry, Statistics, Reports, Recent, Import, Control) = Admin only on the routes guarded by `admin` middleware.
- **Per-action** by capability: `can_assign` (assign to a chosen consultant, shuffle, bulk reassign), `can_add` (admit / admission-from-ICU), `can_manage` (transfer/discharge any patient), `can_modify` (Modify patient details). The **primary consultant** of an admission can manage/discharge their own patient even without `can_manage`.
- **Middleware:** `auth` → `mfa.enroll` (forces TOTP setup if the policy requires it) → `pwd` (forces password change if `pass_exp_date` > 3 months old **or NULL** — unknown password age counts as expired; app-created users are stamped on create; H2/C2). Every state-changing endpoint re-checks authorization server-side (not just hidden buttons).

---

## 5. What every action writes to the database

> All state-changing endpoints are POST/PUT/DELETE, CSRF-protected, and (except pure reads) write an
> `audit_logs` row `{actor_id, actor_name, action, entity_type, entity_id, details, ip}`. "Attribution"
> columns (`admitted_by`, `discharged_by`, `entered_by`) are always taken from the **logged-in session**.

### New Admissions  (`/admissions`)
| Button / field | Endpoint | Database effect |
|---|---|---|
| **Admit patient** (form on `/admissions/create`) | POST `/admissions` | INSERT `patients` (if the MRN is new) + INSERT `admissions` (location, bed, admit_date, admitted_from, `admitted_by`=you, `consultant_id`=NULL) + INSERT one `admission_diagnoses` row per ICD-10 picked. |
| **Admission from ICU** → "To ward" | POST `/admissions/{id}/transfer` (`target=Ward`) | Transaction: UPDATE the ICU admission (`discharge_date`=today, `transfer_type='Transfer from ICU'`, `discharged_by`=you) + INSERT a new Ward admission (same patient & consultant, carried diagnoses). |
| **Assign to primary** (pick consultant) | POST `/admissions/{id}/assign` | UPDATE admission `consultant_id`, `is_new_assignment=1`, `assigned_on`=today. |
| **Assign to me** | POST `/admissions/{id}/assign-to-me` | UPDATE admission `consultant_id`=you, `is_new_assignment=1`, `assigned_on`=today. |
| **Shuffle / auto-assign** | POST `/admissions/shuffle` | UPDATE `consultant_id` on each unassigned active admission, balancing across on-service consultants (ShuffleService). |
| **Edit (Modify)** a queued patient | POST `/admissions/{id}/modify` | See "Modify" below (same endpoint). |

### Active Patients board  (`/patients`)  — shows assigned active patients only
| Button / field | Endpoint | Database effect |
|---|---|---|
| **Reassign consultant** | POST `/admissions/{id}/assign` | UPDATE `consultant_id` (+ `is_new_assignment`, `assigned_on`). |
| **Modify** (bed/MRN/name/age/gender/nationality/diagnoses) | POST `/admissions/{id}/modify` | Transaction: UPDATE `patients` (mrn,name,age,gender,nationality) + UPDATE `admissions.bed` + DELETE all `admission_diagnoses` for the admission and re-INSERT the chosen codes. **J2/13:** an optional `consultant_id` (consultant role) QUIETLY moves the assignment — no `is_new_assignment`/`assigned_*` stamps (legacy Modify semantics), recorded in the `patient.modify` audit row. |
| **Long-term** toggle | POST `/admissions/{id}/longterm` | UPDATE `admissions.is_longterm` (flip). |
| **Transfer** (Ward↔ICU) | POST `/admissions/{id}/transfer` | Transaction: close current episode (`discharge_date`=today, `transfer_type`, `discharged_by`) + INSERT a new admission in the target location (same patient/consultant, carried diagnoses). **J2/1 (legacy parity):** the new episode **carries the bed** and stamps `admitted_from` with the source side (`Ward` going into ICU, `ICU` coming back); a **specialty** transfer keeps the ORIGINAL `admitted_from`, forces `current_location='Ward'` and also carries the bed. |
| **Discharge** (ward, not yet medically discharged) | POST `/admissions/{id}/medical-discharge` | UPDATE `medical_discharge_date`, `outcome`, `discharge_to`, `delay_reason`, `discharged_by`. (Patient stays on the board as "discharged still in".) |
| **Complete discharge** | POST `/admissions/{id}/complete-discharge` | UPDATE `discharge_date`, `transfer_type` (`discharge from ward`/`ICU`), `discharged_by`. Leaves the board. |
| **ICU discharge** (ICU patients) | POST `/admissions/{id}/icu-discharge` | UPDATE `discharge_date`, `outcome`, `transfer_type='discharge from ICU'`, `discharged_by`. |
| **Reverse discharge** (Admin) | POST `/admissions/{id}/reverse-discharge` | UPDATE clears `discharge_date`, `medical_discharge_date`, `delay_reason`, `outcome` (back to active). **Same-day discharges only** (legacy undo; H2/B4). |
| **Shuffle / Bulk reassign** (toolbar) | POST `/admissions/shuffle` · `/admissions/reassign` | Shuffle (above); Bulk reassign UPDATE `consultant_id` for the **selected subset** (`admission_ids[]`, all pre-checked; H2/B3) of one consultant's active admissions. |

### Consultations  (`/consultations`)
| Button / field | Endpoint | Database effect |
|---|---|---|
| **New Consultation** | POST `/consultations` | INSERT `consultations` (`indication` JSON, `entered_by`=you, `signoff_date`=NULL). |
| **Edit** | PUT `/consultations/{id}` | UPDATE the consultation fields + `indication`. |
| **Sign off** | POST `/consultations/{id}/signoff` | UPDATE `signoff_date`=today. |
| **Delete** (Admin) | DELETE `/consultations/{id}` | DELETE the consultation row. |
| **My consultations** / status / search | GET `/consultations?...` | Read-only filter. |

### Registry  (`/registry`)  — Admin
| Button / field | Endpoint | Database effect |
|---|---|---|
| Mode tabs, all filters, search | GET `/registry?mode=…` | **Read only** (Admissions / Consultations / Diagnosis search). |
| **Edit** (from a result row) | POST `/admissions/{id}/modify` | Same as Modify above. |
| **Excel / CSV** | GET `/registry/export-xlsx` · `/registry/export` | Read only; streams a file (no DB change). |

### Recent activity  (`/recent`, Admin) — lists YESTERDAY + TODAY (legacy 48discharge window; H2/B4)
| Button | Endpoint | Database effect |
|---|---|---|
| **Undo discharge** | POST `/admissions/{id}/reverse-discharge` | Clears discharge fields (above). Same-day rows only. |
| **Undo sign-off** | POST `/consultations/{id}/reverse-signoff` | UPDATE `signoff_date`=NULL. Same-day rows only. |

### Bulk import  (`/import`, Admin)
| Step | Endpoint | Database effect |
|---|---|---|
| **Preview** | POST `/import/preview` | **Read only** — parses + validates rows, returns valid/invalid lists. No writes. |
| **Confirm import** | POST `/import` | Transaction: for each valid row, INSERT `patients` (if new) + `admissions` (+ diagnoses). |

### Control panel  (`/control`, Admin)
| Button / field | Endpoint | Database effect |
|---|---|---|
| **Save settings** (thresholds, LOS, **ward/ICU beds**, MFA policy) | PUT `/control/settings` | UPDATE the single `settings` row. |
| **Edit user** (role, active, on-service, specialty, capabilities) | PUT `/control/users/{id}` | UPDATE `users` (guards against self-demotion). |
| **Reset MFA** | POST `/control/users/{id}/reset-mfa` | UPDATE clears `mfa_secret`, `mfa_recovery_codes`, `mfa_enrolled_at`. |
| **Send reset email** | POST `/control/users/{id}/send-reset` | INSERT a `password_reset_tokens` row + send mail (logged in dev). No change to the user row. |
| **Add specialty** | POST `/control/specialties` | INSERT `specialties`. |
| **Add indication** | POST `/control/reasons` | INSERT `consultation_reasons`. |

### Profile & Auth
| Button / field | Endpoint | Database effect |
|---|---|---|
| **Save profile** (name, email) | PUT `/profile` | UPDATE your own `users` row (name, email). |
| **Change password** | PUT `/profile/password` | UPDATE `password` + `pass_exp_date`=today. |
| **Enable 2FA** | POST `/mfa/confirm` | UPDATE `mfa_secret`, `mfa_recovery_codes`, `mfa_enrolled_at`. **J2/14:** self-disable was REMOVED (owner decision) — once enrolled, only an admin can clear MFA via Control → Reset MFA. |
| **Login** | POST `/login` | Reads `users`; on success regenerates the session and (if "remember me") sets `remember_token` (recaller cookie lives **30 days**; H2/C1). With MFA enrolled, the pending challenge expires after **5 min** and allows **8 code attempts** (H2/C3). |
| **Register** | POST `/register` | INSERT `users` with `active=0` (pending admin activation), `role` ∈ {2,3,4,5} (never admin), `pass_exp_date`=today. |
| **Forgot / reset password** | POST `/forgot-password` · `/reset-password` | Forgot: INSERT `password_reset_tokens` + email. Reset: UPDATE `password` + `pass_exp_date`. |

### Dashboard / Statistics / Reports
All **read-only** aggregations (see the metrics doc). The PDF/Excel endpoints stream files and write nothing.

---

## 6. Notes

- **No hard deletes of patient data** except the explicit admin Reverse actions (which only clear discharge/sign-off fields) and consultation Delete. Patient/admission rows are never destroyed by routine flow.
- **Transactions** wrap the multi-row writes (transfer, modify, import) so a partial failure rolls back.
- The legacy spoofable `userid` POST fields are gone — attribution is session-sourced everywhere.
