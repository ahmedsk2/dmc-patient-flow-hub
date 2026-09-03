# 0002 — Sessions and cache live in the database, not on the container filesystem

- **Status:** Accepted
- **Date:** 2026-09-03

## Context

Production ran `SESSION_DRIVER=file`, so sessions lived inside the app container. Two consequences
were recorded on 2026-09-03.

First, a correctness defect. Three code paths delete rows from the `sessions` table keyed by
`user_id` to kill a user's other live sessions: password reset, admin MFA reset (the documented
lost-or-compromised-device recovery path) and self password change. Under the file driver Laravel
never touches that table, so all three were **silent no-ops in production**, and two of the call
sites carried comments asserting the opposite — the driver had been changed after the logic was
written and never reconciled (`evidence/prod-ready-2026-09-03.md`, finding SEC-04).

Second, an operational cost: a redeploy replaces the container, so every file session was discarded
and everyone was signed out. The per-container dashboard cache had the matching problem — a second
replica would serve its own stale copy (PERF-07), and the scheduler liveness beacon written by host
cron has to be readable by the web process.

## Decision

Set `SESSION_DRIVER=database` and `CACHE_STORE=database` as the defaults in `config/session.php`,
`config/cache.php` and `.env.example`, and as runtime environment variables in production. Sessions
go to the `sessions` table; the cache goes to the `cache` table
(`0001_01_01_000001_create_cache_table.php`).

## Consequences

- The session-revocation paths actually revoke: password reset, admin MFA reset and self password
  change now delete rows a live session reads.
- A redeploy no longer signs everyone out, and the dashboard cache survives it; cron and the web
  process share one cache store, which is what `GET /health`'s scheduler heartbeat depends on.
- Sessions and cache now sit in the same MySQL instance as the clinical data — they are inside the
  backup, and inside the blast radius of a database incident.
- `legacy:import`'s session-clearing step in DEPLOY-LARAVEL.md §8 is written for file sessions; the
  reason it exists (re-seeded user ids orphan live sessions) is unchanged.
- Logs did **not** move: `LOG_CHANNEL` still writes under `storage/`, so a redeploy still discards
  the previous container's log files unless `storage/` is a persistent volume. Choosing a log sink
  remains open (OBS-01/03/04/05, owner).
- Shipped in PR #7, deployed as `3fbbd73`, and verified live the same day.

## References

- `HANDOFF.md` — "What remains" item 5, top fix (2)
- `CLAUDE.md` §10 (Operations)
- `laravel/docs/DEPLOY-LARAVEL.md` §10 ("Logs live in the container; sessions no longer do")
- `laravel/.env.example` (`SESSION_DRIVER`, `CACHE_STORE` and the cron/web shared-store note)
- `laravel/docs/compliance/evidence/prod-ready-2026-09-03.md` (SEC-04) and
  `evidence/prod-ready-2026-09-03-rescore.md` (PERF-07)
- `laravel/docs/compliance/EVIDENCE-PACK.md` gap G16
- Commits `e28f7ad`, `fa66c00` (2026-09-03)
