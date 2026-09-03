# 0003 — Hash-chained, append-only audit log, verified nightly and shipped off-box hourly

- **Status:** Accepted
- **Date:** 2026-06-14 (commit `23cab57`, "Phase 2 — audit & accountability")

## Context

PDPL accountability requires a tamper-evident record of writes and of PHI reads. A plain append
table is not enough: anyone with database access could edit or delete a row afterwards and leave no
trace, and a retention job could quietly remove the evidence it was meant to preserve.

## Decision

One writer, one chain, one archive.

- **One writer.** `App\Support\Audit::log()` is the only path that creates an `audit_log` row; actor
  identity and IP always come from the session and request, never from the caller.
- **Hash chain.** Each row stores the previous row's `row_hash` as `prev_hash` plus a sha256 over its
  own canonical JSON (fixed fields, alphabetical keys, including `id` and `prev_hash`). `prev_hash`
  is read under `lockForUpdate`, and the whole append runs in one transaction so two concurrent
  writes cannot fork the chain and raise false tamper alarms.
- **Append-only at the ORM layer.** `App\Models\AuditLog` refuses `update()` and `delete()`.
- **Verified nightly.** `audit:verify-daily` (02:30) walks the chain; on a break it logs critical,
  notifies every active admin in-app and emails the report recipients. Silent on success.
- **Shipped hourly, write-once.** `audit:ship` streams unshipped rows as NDJSON to the in-Kingdom
  bucket `dmc-audit-log`, capped at 5000 per run, advancing the high-water mark
  `settings.audit_shipped_through_id` only after a successful upload.
- **Pruning is manual.** `audit:prune` is never scheduled. A row is eligible only if it is below the
  shipping high-water mark **and** past `audit_retention_years`; `0` refuses to run; the default is
  a dry run and `--confirm` is required. It deletes through the query builder — the model forbids it.

## Consequences

- Retrospective edits and deletions are detectable, and off-box copies survive a compromise of the
  application database.
- The audit `details` JSON stays **plaintext** — encrypting it would break external verification
  (ENCRYPTION-AT-REST.md §2). One deliberate, flagged consequence: `consultation.reverse_signoff`
  preserves the cleared response note in the clear.
- Missing `AUDIT_S3_*` config is a valid "not set up yet" state, not a failure, so the hourly
  schedule does not spam an unconfigured environment.
- `audit_retention_years` is 6 by internal choice; no sector rule requiring six was found.

## References

- `CLAUDE.md` §2, §5, §9
- `laravel/app/Support/Audit.php`, `laravel/app/Models/AuditLog.php`
- `laravel/app/Console/Commands/AuditShip.php`, `AuditVerify.php`, `AuditVerifyDaily.php`,
  `AuditPrune.php`
- `laravel/database/migrations/2026_06_14_000004_add_hash_chain_to_audit_log.php`
- `laravel/docs/DEPLOY-LARAVEL.md` §0, §6
- `laravel/docs/compliance/EVIDENCE-PACK.md` rows P10, P17 and §5
- `laravel/docs/compliance/CONFIRMED-FACTS.md` C10 (audit retention)
