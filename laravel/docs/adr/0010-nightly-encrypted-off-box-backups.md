# 0010 — Nightly encrypted off-box backups with a monthly restore drill; RPO ≤ 24 h

- **Status:** Accepted
- **Date:** 2026-09-03 (runbook commit `c03f7ca`; first production drill logged the same day)

## Context

Until this shipped there was **no automated database backup** — six ad-hoc, *plaintext* `mysqldump`
gzips in `/home/ubuntu`, on the same host as the database. A disk failure, ransomware, a bad
migration or a crashed `legacy:import` would have permanently destroyed ~37,662 admissions and
~17,435 patient records — and that last case is not hypothetical: `legacy:import` truncates its
targets before its first legacy read, and has emptied the database once.

## Decision

Three pieces, all under `laravel/`:

- **`scripts/backup/db-backup.py`** — host cron, as root, nightly at 02:15: `mysqldump` inside the
  MySQL container → gzip → AES-256-CBC (PBKDF2, 200 000 iterations) → local copy → SigV4 PUT to the
  in-Kingdom bucket `dmc-db-backups` → heartbeat `LATEST.json`. The pipeline **streams**: no plaintext
  SQL is ever written to disk.
- **`php artisan backup:verify`** — app scheduler, daily 06:30: reads `LATEST.json`, HEAD-checks the
  object, and raises one in-app `backup.stale` notification to every active admin if it is older than
  26 h, missing or unverifiable.
- **`scripts/backup/db-restore-drill.sh`** — monthly, by hand, safe on production: restores the latest
  object into a scratch database, prints row counts and timings, drops it.

RPO is **≤ 24 hours** by design. RTO is **whatever the drill prints**, plus the human steps; no
unmeasured number is quoted, and every drill is logged in §8.

## Consequences

- Worst case (host dies at 02:14) loses everything since the previous dump; the full-restore runbook
  requires telling clinicians which window they must re-enter.
- **Binlog shipping is explicitly out of scope** here: §1 states a tighter RPO needs it (DATA-04),
  which the re-score carries as an open High. `HANDOFF.md` records MySQL binlog already ON (8.4
  default, ROW, 30-day expiry) and off-box shipping plus a PITR runbook **in progress** — a separate,
  not-yet-taken decision.
- **Kept outside the backup:** the app's `.env` — above all `APP_KEY`, without which restored MFA
  secrets, the SMTP password and the encrypted narratives are unreadable (ADR 0004) — the backup key
  file, and the Coolify/OCI configuration.
- Retention is **90 days** off-box and 2 days locally, both `[NEEDS LEGAL CONFIRMATION]`.
- Two drills are logged for 2026-09-03 (8 s and 7 s). The second notes `audit:verify` cannot run
  against the scratch database, which the drill drops on exit. A second in-Kingdom backup region
  remains open (DATA-02).

## References

- `laravel/docs/BACKUP-AND-RESTORE.md` §1–§9 (§6 retention, §8 drill log)
- `laravel/scripts/backup/db-backup.py`, `db-restore-drill.sh`, `test_db_backup.py`
- `laravel/app/Console/Commands/BackupVerify.php`, `laravel/app/Support/S3SigV4.php`
- `CLAUDE.md` §9; `HANDOFF.md` — "What is already shipped and live", item 5
- `laravel/.env.example` (`DB_BACKUP_S3_BUCKET`, `AUDIT_S3_*`)
- `laravel/docs/compliance/CONFIRMED-FACTS.md` C4; `EVIDENCE-PACK.md` §5
- `laravel/docs/compliance/evidence/prod-ready-2026-09-03-rescore.md` (DATA-02 / DATA-04)
- Commits `24bd69e`, `c03f7ca`, `def385b`, `02314b9` (2026-09-03)
