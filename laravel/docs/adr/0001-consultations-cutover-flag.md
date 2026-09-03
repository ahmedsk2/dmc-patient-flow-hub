# 0001 — The consultations cutover flag makes this app the source of truth for the ledger

- **Status:** Accepted
- **Date:** 2026-08-21 (migration `2026_08_21_000000_add_consultations_source_of_truth_to_settings.php`)

## Context

`php artisan legacy:import` is a full rebuild of the Laravel database from the legacy `dmc_prod`
schema: it truncates its target tables *before* its first legacy read, re-seeds users with new ids,
and truncates handovers and notifications (DEPLOY-LARAVEL.md §8). That was acceptable while every
row originated in the legacy system.

The per-specialty consultation ledger changed that. Consultations, their status transitions, their
sign-offs and their daily follow-ups are entered **in this application** and have no legacy
counterpart. A reload run with the old semantics would have destroyed them silently, with no error
and no recovery path short of a database restore.

## Decision

Record the cutover as data, in the app's own `settings` row: `consultations_source_of_truth`.

While the flag is **ON** — the production state — `legacy:import` **preserves** `consultations` and
`consultation_followups`, re-points them at the rebuilt patient and user rows by natural key
(`patients.mrn`, `users.legacy_id`), and **refuses** `--wipe-consultations`. While it is **OFF** —
the pre-cutover default — the ledger is truncated and rebuilt from the legacy dump.

Because the flag is an app-owned settings column, the importer carries it across its own rebuild, so
the first import cannot silently reset it. It is set in Control → Settings; the un-tick is confirmed
and written to the settings history.

## Consequences

- Turning the flag OFF is not a harmless toggle: it re-arms the next import to destroy the ledger.
  DEPLOY-LARAVEL.md §7 requires reading before flipping it, and names the only legitimate reason
  (a deliberate decision, with the consulting services, to abandon the ledger).
- A **failed** import while the flag is ON requires a database restore before retrying: `TRUNCATE`
  forces a commit so the run cannot be one transaction, and the re-link mapping lives only in memory.
- After any restore, the flag must be checked — a dump taken before cutover restores it to OFF.
- Preserved consultations whose patient or user cannot be resolved are reported by id, their link
  nulled; they keep `mrn` / `patient_name` and can be re-linked.
- `tests/Feature/LegacyImportTest.php` (group `slow-import`) is what proves a reload cannot destroy
  or misattribute the ledger, so it must never be excluded from a release run.

## References

- `CLAUDE.md` §11, §12
- `laravel/docs/DEPLOY-LARAVEL.md` §7, §8, §10
- `laravel/database/migrations/2026_08_21_000000_add_consultations_source_of_truth_to_settings.php`
- `laravel/tests/Feature/LegacyImportTest.php`
- Commits `7e46506` (2026-08-21, pin the wipe-refusal, carry app-owned settings), `ca330d8` and
  `5d4e4a4` (2026-08-22, re-link preserved rows), `a29cfa7` (2026-08-22, group `slow-import`)
