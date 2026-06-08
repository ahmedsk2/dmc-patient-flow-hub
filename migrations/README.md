# Database migrations

Run these SQL files **once each, in numeric order**, against the DMC database
(e.g. `mysql dbname < migrations/01-audit-log.sql`) as part of deploying the
`renovation` branch. They are additive and safe to run on the live schema, but
**take a database backup first**.

**For a structural-delta map + per-change detection queries (deploying onto a newer existing live DB),
see [`SCHEMA-CHANGES.md`](SCHEMA-CHANGES.md).**

| # | File | Purpose | Kind |
|---|------|---------|------|
| 01 | `01-audit-log.sql` | `audit_log` table — who changed/deleted what, when | + table |
| 02 | `02-password-resets.sql` | `password_resets` table + widen `members.member_password` → varchar(255) | + table / widen |
| 03 | `03-innodb-and-indexes.sql` | clinical tables MyISAM→InnoDB (enables transactions) + MRN/consultant/location/signoff indexes | engine / + indexes |
| 04 | `04-foreign-keys.sql` | 5 FKs `picupatients`/`consultations` member refs → `members` (`ON DELETE SET NULL`). **Run after 03, in a window.** | + FKs (DML pre-step) |
| 05 | `05-charset-utf8mb4.sql` | convert the 4 remaining tables (`members`,`settings`,`tbl_token_auth`,`countries`) → utf8mb4. **Optional, recommended.** | charset |
| 06 | `06-mfa.sql` | `members.mfa_secret/mfa_recovery_codes/mfa_enrolled_at` + `settings.mfa_enforcement`. Needs `MFA_KEY` in config. | + columns |
| 07 | `07-patient-diagnosis.sql` | derived `patient_diagnosis` join table (rebuildable; not yet read by the app) | + table (derived) |
| 08 | `08-drop-dead-tables.sql` | drop unused `consultation_details`, `Notes`. **Confirm empty/unreferenced first.** | − tables |
| 09 | `09-patients-entity.sql` | derived canonical `patients` table (one row per MRN; rebuildable; not yet read) | + table (derived) |
| 10 | `10-icd10-id-index.sql` | covering index `icd10(id,name)` — **PERF, important** (without it `longterm.php` etc. time out) | + index |
| 11 | `11-consultations-mrn-type.sql` | `consultations.MRN` `INT` → `VARCHAR(64)` — stops MRN truncation/zeroing | type change |

> Until a migration is applied, the related feature degrades gracefully where possible:
> `audit_log()` is fail-safe (logs to the PHP error log if the table is missing); MFA stays
> off until 06 + enrollment; the derived tables (07/09) are simply unused.
>
> MySQL has **no portable `IF NOT EXISTS`** for `ADD COLUMN/INDEX/CONSTRAINT`, so each `ALTER`-based
> migration is **run-once** — re-running after a successful apply errors harmlessly ("Duplicate …").
> `CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS` are safe to re-run. See `SCHEMA-CHANGES.md`
> for a detection query per change so you can skip ones the target DB already has.
