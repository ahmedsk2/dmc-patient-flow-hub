# 0008 — No queue worker, no SSR, and a host cron drives the scheduler

- **Status:** Accepted
- **Date:** 2026-09-03 (the state as recorded; the original decision dates are not recorded)

## Context

The deployment is one OCI host running one app container under Coolify, with no staging and no
second replica (ADR 0007). Every moving part added to it is another thing that can die silently —
the system had already lost its scheduler once, and the monthly report, audit shipping and the audit
integrity check all quietly stopped with it.

## Decision

Keep the runtime deliberately small.

- **No queue worker.** `QUEUE_CONNECTION=sync`. Anything marked `ShouldQueue` — the monthly PDF job,
  the report page's `?async=1` — runs inline in the request that triggered it. No worker process to
  supervise, no `jobs` backlog to drain.
- **No SSR.** The browser renders everything, and `public/build/` is committed so the host never runs
  Node. The rationale for choosing no SSR is **not recorded** beyond the statement itself.
- **Scheduler by host root cron.** Nothing in the container runs the Laravel scheduler. A host root
  cron runs `/usr/local/bin/dmc-schedule.sh` every minute, doing `docker exec … php artisan
  schedule:run` against the container found **by label** — so it survives every redeploy.
- **A liveness beacon.** `scheduler:heartbeat` stamps the cache every minute and `GET /health`
  reports it stale after five minutes, precisely because a dead scheduler had gone unnoticed before.

## Consequences

- No worker to crash, restart or monitor; no queued PDF bytes persist in a `jobs` table, which is
  why the compliance record can state there is no queued-PDF retention question (C3).
- `?async=1` report generation is **not** actually asynchronous: a long monthly PDF holds the request
  open. The monthly-report mailer runs inline too, so per-recipient failure isolation had to be added
  explicitly (RES-05, 2026-09-03 close-out).
- The cron script survives redeploys but **not** the application being deleted and recreated in
  Coolify (new uuid). If the scheduler ever moves to a Coolify *Scheduled Task*, this cron must be
  removed or tasks run twice.
- The cron user and the web user must share one cache store — part of why the cache moved to the
  database (ADR 0002).
- The monthly booklet is written to the container's local disk; on a future scaled-out deployment a
  download could land on the wrong replica (PERF-07, bounded: admin-only, not a clinical flow).

## References

- `CLAUDE.md` §3, §5, §10
- `laravel/docs/DEPLOY-LARAVEL.md` §6 (schedule table, `QUEUE_CONNECTION=sync`), §10
- `laravel/app/Http/Controllers/HealthController.php`, `laravel/app/Console/Commands/SchedulerHeartbeat.php`
  (commit `dea9dff`, 2026-09-03, OBS-07)
- `laravel/routes/console.php`, `laravel/routes/public.php`
- `laravel/.env.example` (`QUEUE_CONNECTION`, and the cron/web shared-cache note)
- `laravel/docs/compliance/CONFIRMED-FACTS.md` C3
- `laravel/docs/compliance/evidence/prod-ready-2026-09-03-rescore.md` (PERF-07, RES-05)
