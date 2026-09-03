# Architecture Decision Records

Backfilled records of the load-bearing decisions already in force in this repository. Each one is
assembled **only** from what the repository records — `CLAUDE.md`, `HANDOFF.md`, the runbooks under
`laravel/docs/`, the compliance drafts under `laravel/docs/compliance/`, code comments, migration
names and commit messages. Where the record is silent, the ADR says **not recorded** rather than
guessing. Nothing here is a new decision: these documents follow the code, they do not lead it.

Format: [MADR](https://adr.github.io/madr/) — Title / Status / Date / Context / Decision /
Consequences / References.

| # | Decision | Status | Date |
|---|---|---|---|
| [0001](0001-consultations-cutover-flag.md) | The consultations cutover flag makes this app the source of truth for the ledger | Accepted | 2026-08-21 |
| [0002](0002-database-sessions-and-cache.md) | Sessions and cache live in the database, not on the container filesystem | Accepted | 2026-09-03 |
| [0003](0003-hash-chained-append-only-audit-log.md) | Hash-chained, append-only audit log, verified nightly and shipped off-box hourly | Accepted | 2026-06-14 |
| [0004](0004-application-layer-narrative-encryption.md) | Clinical narratives encrypted in the application under `APP_KEY` via a hand-rolled cast | Accepted | 2026-09-03 |
| [0005](0005-operator-triggered-deploys.md) | Deploys stay operator-triggered; deploy-on-green is prepared but not enabled | Accepted | 2026-09-03 |
| [0006](0006-main-protected-pull-requests-only.md) | `main` is protected: pull requests only, four required admin-enforced CI checks | Accepted | 2026-09-03 |
| [0007](0007-in-kingdom-hosting-behind-cloudflare.md) | One OCI Riyadh host behind Cloudflare, with in-Kingdom buckets, for PDPL residency | Accepted | 2026-09-03 |
| [0008](0008-no-queue-worker-no-ssr-host-cron-scheduler.md) | No queue worker, no SSR, and a host cron drives the scheduler | Accepted | 2026-09-03 |
| [0009](0009-mandatory-totp-mfa.md) | Mandatory TOTP MFA for every user, remember-me disabled, no self-disable | Accepted | 2026-07-11 |
| [0010](0010-nightly-encrypted-off-box-backups.md) | Nightly encrypted off-box backups with a monthly restore drill; RPO ≤ 24 h | Accepted | 2026-09-03 |

## Conventions

- **Status** is `Accepted` only where the repository shows the decision is in force today.
  `Proposed` would mark a decision recorded but not yet applied; no ADR here is in that state.
- **Date** is the date the repository records for the decision taking effect (a commit date, a
  migration name, or a dated statement in a runbook), not the date the ADR was written.
- ADRs are immutable once merged. A decision that changes gets a **new** ADR that supersedes the
  old one; the old file stays, with its status changed to `Superseded by NNNN`.
- Ground truth for what is live and what remains is still [`HANDOFF.md`](../../../HANDOFF.md);
  the standing product map is still [`CLAUDE.md`](../../../CLAUDE.md). These ADRs record *why*,
  and defer to those two on *what*.
