# DMC — SQL schema changes vs the ORIGINAL database

> **Purpose.** This is the authoritative record of every **structural** change the renovation makes
> to the original DMC schema, so the changes can be applied to a **newer live database that already
> exists** (i.e. one that has kept accumulating real data and may already differ from the original
> dump). Each change names the migration that implements it, says whether it is additive / in-place /
> destructive, and gives a **detection query** so you can tell whether the target DB already has it
> and **skip** it.
>
> The migration files in this folder remain the source of truth — run them, in order, as described in
> [`README.md`](README.md) and the deploy runbook [`../DEPLOY.md`](../DEPLOY.md) §3. This file is the
> human-readable map of what they change.

⚠️ **Take a verified backup first.** Several steps run in a maintenance window (engine conversion,
charset conversion, foreign keys, the MRN type change). Nothing here deletes clinical/consultation
rows.

---

## How to use this on an existing, newer live DB

1. **Back up** the live DB.
2. For each change below, run its **detection query** against the live DB. If the change is already
   present (column exists, index exists, engine already InnoDB, …), **skip that migration step** —
   MySQL has no portable `ADD COLUMN/INDEX/CONSTRAINT IF NOT EXISTS`, so re-running an already-applied
   `ALTER` errors (harmlessly) with "Duplicate …". `CREATE TABLE IF NOT EXISTS` and `DROP TABLE IF
   EXISTS` are safe to re-run.
3. Apply the remaining migrations **in numeric order** (04 requires 03; 07 requires the InnoDB/8.0
   state from 03).
4. **Re-derive the two rebuildable tables** (`patient_diagnosis`, `patients`) from the live data
   *after* import — see the note in §“Derived tables”. Their backfill is computed from `picupatients`,
   so on a DB with more data you must `TRUNCATE` + re-run their `INSERT` to cover the new rows.
5. Do the **non-DDL deploy steps** (not in these files): rotate DB/SMTP creds, set
   `settings.min_subs = settings.max_subs = 7`, set `settings.mfa_enforcement` to your policy, delete
   spam member rows, enable TLS. See `../DEPLOY.md`.

---

## Summary — every structural change

| # (migration) | Change | Kind | Detection query (run on the live DB) |
|---|---|---|---|
| 01 | **+ table `audit_log`** | additive | `SHOW TABLES LIKE 'audit_log';` |
| 02 | **+ table `password_resets`** | additive | `SHOW TABLES LIKE 'password_resets';` |
| 02 | `members.member_password` → `VARCHAR(255)` | in-place widen | `SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME='member_password';` |
| 03 | **engine MyISAM→InnoDB** on `picupatients`, `picupatients_temp`, `icd10`, `speciality`, `other_specialities`, `tb_list`, `settings`, `position` | in-place | `SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE<>'InnoDB';` |
| 03 | **+ indexes** on `picupatients(MRN(20))`, `(consultant_id)`, `(current_location(10))` | additive | `SHOW INDEX FROM picupatients;` |
| 03 | **+ indexes** on `consultations(consultant_id)`, `(MRN)`, `(signoff_date)`, `(consultation_date)` | additive | `SHOW INDEX FROM consultations;` |
| 04 | **+ 5 foreign keys** `picupatients.{consultant_id,admitted_by,trans_discharge_by}` and `consultations.{consultant_id,entered_by_id}` → `members(member_id)` | additive (DML pre-step) | `SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='members';` |
| 05 | **charset → utf8mb4_unicode_ci** on `members`, `settings`, `tbl_token_auth`, `countries` | in-place | `SELECT TABLE_NAME,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_COLLATION NOT LIKE 'utf8mb4%';` |
| 06 | **+ columns** `members.mfa_secret`, `members.mfa_recovery_codes`, `members.mfa_enrolled_at` | additive | `SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND COLUMN_NAME LIKE 'mfa%';` |
| 06 | **+ column** `settings.mfa_enforcement TINYINT NOT NULL DEFAULT 0` | additive | `SHOW COLUMNS FROM settings LIKE 'mfa_enforcement';` |
| 07 | **+ table `patient_diagnosis`** (derived, rebuildable) | additive | `SHOW TABLES LIKE 'patient_diagnosis';` |
| 08 | **− tables `consultation_details`, `Notes`** (dead/unused) | destructive (drop) | `SHOW TABLES LIKE 'consultation_details'; SHOW TABLES LIKE 'Notes';` |
| 09 | **+ table `patients`** (derived canonical one-row-per-MRN, rebuildable) | additive | `SHOW TABLES LIKE 'patients';` |
| 10 | **+ covering index** `icd10(id, name)` (`idx_icd10_id`) | additive | `SHOW INDEX FROM icd10 WHERE Key_name='idx_icd10_id';` |
| 11 | `consultations.MRN` `INT` → `VARCHAR(64)` | in-place type change | `SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='consultations' AND COLUMN_NAME='MRN';` |

Nothing renames or drops any **clinical column**, and no migration changes `picupatients`’ own columns
(its `admissiondiagnosis` JSON stays the source of truth; 07/09 only *derive* from it).

---

## Detail by category

### New tables (additive — `CREATE TABLE IF NOT EXISTS`, safe to re-run)
- **`audit_log`** (01) — clinical audit trail (actor, action, entity, JSON details, ip, time). The app’s
  `audit_log()` is fail-safe: if the table is absent it logs to the PHP error log and never breaks the action.
- **`password_resets`** (02) — single-use, time-limited, **sha256-hashed** reset tokens (replaces the old
  md5-of-password-hash scheme).
- **`patient_diagnosis`** (07) — *derived* index table, one row per (admission, ICD-10 code) with 1-based
  `seq`. Rebuildable from `picupatients.admissiondiagnosis`. **No app code depends on it yet.**
- **`patients`** (09) — *derived* canonical one-row-per-trimmed-MRN table (demographics from the latest
  admission + span/count). Rebuildable. **No app code depends on it yet.**

### Dropped tables (08 — destructive, `DROP TABLE IF EXISTS`)
- **`consultation_details`**, **`Notes`** — never read or written by any code (planned-but-unbuilt feature
  remnants). `Notes` is already absent from current production; `consultation_details` may hold a few inert
  sample rows. Confirm both are empty/unreferenced before dropping.

### New / changed columns
- `members.member_password` → **`VARCHAR(255)`** (02) — room for Argon2id etc. (bcrypt is 60 chars).
- `members.mfa_secret VARCHAR(255) NULL`, `members.mfa_recovery_codes TEXT NULL`,
  `members.mfa_enrolled_at DATETIME NULL` (06) — MFA (TOTP). All nullable; until a user enrolls, behaviour
  is unchanged. **Requires `MFA_KEY`** in the app config (the secret is AES-256-GCM-encrypted at rest).
- `settings.mfa_enforcement TINYINT NOT NULL DEFAULT 0` (06) — `0`=opt-in, `1`=admins, `2`=all.
- `consultations.MRN` **`INT` → `VARCHAR(64)`** (11) — stops MRN truncation/zeroing (see migration header).
  The `MODIFY` rebuilds `idx_consultations_mrn` (from 03) for the new type. Existing numeric values become
  their exact decimal string; already-truncated historical values cannot be recovered.

### Engine: MyISAM → InnoDB (03, in-place)
`picupatients`, `picupatients_temp`, `icd10`, `speciality`, `other_specialities`, `tb_list`, `settings`,
`position`. Enables transactions (atomic multi-step clinical writes) + row-level locking + FKs.
`consultations`, `members`, `tbl_token_auth` were already InnoDB.

### Charset: → utf8mb4_unicode_ci (05, in-place)
`members`, `settings`, `tbl_token_auth` (were latin1), `countries` (was utf8mb3). The big clinical/lookup
tables became utf8mb4 during the 03 InnoDB pass. After 05 the whole schema is one consistent collation
(prevents "illegal mix of collations"). Use a plain `CONVERT TO CHARACTER SET` — these four are true-latin1 /
utf8mb3 (not utf8-in-latin1); do **not** use the binary-intermediate trick. ⚠️ Review/delete spam member
rows first (see migration 05 header).

### Indexes (additive)
- `picupatients`: `idx_picupatients_mrn (MRN(20))`, `idx_picupatients_consultant (consultant_id)`,
  `idx_picupatients_location (current_location(10))` (03). (`ADMDATE`/`DISDATE` were already indexed.)
- `consultations`: `idx_consultations_consultant`, `idx_consultations_mrn`, `idx_consultations_signoff`,
  `idx_consultations_condate` (03).
- `icd10`: **`idx_icd10_id (id, name)`** (10) — the ICD-10 *code* column was unindexed; every diagnosis
  lookup was a full scan (~1.3 s on 72,750 rows). Without this, `longterm.php` (and the discharge modals,
  old-patient import, registry export, dashboard/3, statistics/charts) are unusably slow. **High priority.**
- New tables ship their own indexes (see their `CREATE TABLE`).

### Foreign keys (04, additive — requires InnoDB from 03)
5 constraints, all `ON DELETE SET NULL ON UPDATE CASCADE`:
`fk_picupatients_consultant`, `fk_picupatients_admitted_by`, `fk_picupatients_trans_discharge_by`,
`fk_consultations_consultant`, `fk_consultations_entered_by` → `members(member_id)`.
A **DML pre-step** normalizes the `= 0` "no member" sentinel to `NULL`, and there is a **pre-flight orphan
check** (run on the live DB first — each must return 0; clean any orphans before adding the FK).
`members.specialty_id` and `members.position` are intentionally **left FK-free** (load-bearing `0`
sentinels / Admin = position 0 not in `position`).

---

## Derived tables — REBUILD them on the live DB after import

`patient_diagnosis` (07) and `patients` (09) are computed from `picupatients`. On a newer live DB with more
data than the original dump, their `CREATE TABLE IF NOT EXISTS` is a no-op but the **backfill** must be
re-run so they cover the current rows:

```sql
-- patient_diagnosis
TRUNCATE patient_diagnosis;
-- then re-run the two INSERT/UPDATE statements from 07-patient-diagnosis.sql

-- patients
TRUNCATE patients;
-- then re-run the INSERT from 09-patients-entity.sql
```

Known data caveats they expose (not bugs in the migration): ~112 distinct non-numeric/over-long MRNs become
spurious `patients` rows until the MRN clean-up; blank/NULL-MRN admissions are excluded.

---

## NOT in these files (app-level deploy steps — see ../DEPLOY.md)
- Rotate the DB + SMTP credentials (they were exposed in plaintext).
- `UPDATE settings SET min_subs = 7, max_subs = 7;` (operational thresholds, confirmed at deploy).
- `UPDATE settings SET mfa_enforcement = <0|1|2>;` to choose the MFA policy.
- Delete spam member accounts (open-registration era — detection query in 05’s header).
- TLS/HSTS, Secure/HttpOnly/SameSite cookies, `display_errors=Off` (php.ini / .htaccess).
