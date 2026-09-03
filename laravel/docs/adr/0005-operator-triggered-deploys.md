# 0005 — Deploys stay operator-triggered; deploy-on-green is prepared but not enabled

- **Status:** Accepted
- **Date:** 2026-09-03

## Context

There is **one** environment and no staging: every deploy is a production change against a live
clinical system holding real PHI. Coolify builds `laravel/` from `main` HEAD, runs
`migrate --force` and swaps the container — but it does **not** back up the database, run the test
suite, or run the smoke test. Those are operator steps (DEPLOY-LARAVEL.md §2, §3.3).

The morning production-readiness run of 2026-09-03 raised a Critical on the assumption that Coolify
deployed on raw push (CICD-04/08). The container swap also still has **no drain step** (RES-12): a
request in flight on the old container when Traefik is repointed is cut.

## Decision

Keep deploys **operator-triggered**. Coolify *Auto Deploy* is off. A deploy is started deliberately,
from the Coolify UI or the host-local API, after the operator has taken a pre-deploy database dump
and read the migration list.

Write the automated alternative but leave it inert: `laravel/scripts/deploy-on-green.sh` deploys
`main` only when the Laravel CI run for that exact commit is green and the commit is not already the
live image, then runs the smoke test and reports a red smoke as the rollback trigger. It is not
installed and not wired to cron; enabling it is a documented, opt-in procedure.

The record states the reason as an **owner decision** — "Auto Deploy is off on purpose"; a fuller
written rationale is **not recorded**.

## Consequences

- No commit reaches production without a human starting it, so the pre-deploy backup and the
  post-deploy verification (`scripts/smoke.sh`, `/health`, audit chain, scheduler heartbeat) stay
  attached to someone present when they fail.
- Deploy latency is manual, and low-activity windows are chosen by hand while the drain step is
  unapplied.
- The single-operator control plane remains a known open High (SEC-11): one person authors, merges
  and deploys. The re-score records the required, admin-enforced status checks and Auto Deploy being
  off as the **compensating controls**, which do not add a second-person approval gate.
- Because `main` accepts only green pull requests (ADR 0006), the script's "green" check would be
  redundant protection rather than the only gate.
- Enabling deploy-on-green and applying the drain step remain open owner/infrastructure items
  (CICD-08 / RES-12).

## References

- `HANDOFF.md` — "What remains" item 5 ("Deploy-on-green stays OFF by owner decision")
- `laravel/docs/DEPLOY-LARAVEL.md` §1, §2, §3, §6 ("Deploy-on-green (prepared, opt-in)"), §10
- `laravel/scripts/deploy-on-green.sh`
- `CLAUDE.md` §2, §10
- `laravel/docs/compliance/evidence/prod-ready-2026-09-03.md` (CICD-04/08) and
  `evidence/prod-ready-2026-09-03-rescore.md` (SEC-11, compensating controls)
- Commits `fa66c00`, `f51f152` (2026-09-03)
