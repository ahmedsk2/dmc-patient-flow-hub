# 0007 — One OCI Riyadh host behind Cloudflare, with in-Kingdom buckets, for PDPL residency

- **Status:** Accepted
- **Date:** 2026-09-03 (the date the facts below were confirmed and verified)

## Context

The hub holds real PHI and **Saudi PDPL / SDAIA applies**, so data residency is a compliance
requirement, not a preference. The system is also mid-migration: the unit's daily system is still
the legacy PHP app, and the Laravel app runs in parallel until cutover.

## Decision

Run the Laravel application entirely in-Kingdom and put the edge in front of it:

- **Compute and database:** one OCI Ubuntu instance in `me-riyadh-1` (Oracle Systems Limited,
  company-held pay-as-you-go tenancy, Oracle-managed AES-256 volume encryption), running Coolify v4
  and a MySQL 8 container on the same host (B4).
- **Buckets, both in-Kingdom:** `dmc-db-backups` for the encrypted nightly dump and `dmc-audit-log`
  for the hourly audit archive — kept separate so their lifecycle and retention can differ
  (C4, ADR 0003, ADR 0010).
- **Edge:** Cloudflare Free, proxied record, minimum TLS 1.2 and HSTS. The origin's ports 80/443
  accept **only** Cloudflare's IP ranges, so an unproxied record or a direct `curl` gets nothing
  (B5, C8).
- **Accepted, time-boxed cross-border exposure until cutover:** the legacy daily system on SiteGround
  shared hosting in the US (B1), and the outbound SMTP relay — the dmc-im.com mailbox on SiteGround
  (US) — carrying staff e-mail, OTP codes, reset links and the aggregate monthly PDF, no
  patient-level data (B6).

## Consequences

- Application data, backups and the audit archive stay in-Kingdom; the login trust badges can state
  in-Kingdom hosting truthfully.
- **Cloudflare Free terminates TLS outside the Kingdom.** Regional Services (in-Kingdom termination)
  is Enterprise-only. Whether that is acceptable is an open **DECISION** for counsel; SDAIA text does
  not address it, and with no SDAIA adequacy list the US relay needs SCCs/BCR plus an Art. 7 risk
  assessment (§E).
- The **legacy daily site is the live exposure**: verified 2026-09-03 as the original un-hardened
  build, defects live over real PHI on a US host, graded **F** against the Laravel app's **A**. Its
  fate is an owner item; the plan is cutover.
- The OCI host also runs unrelated databases (`endorsement`, `qch`) — a shared multi-project host,
  relevant to isolation in the classification scheme (B7).
- One host, one environment: no staging, no second region, no automatic failover; a second
  in-Kingdom backup region remains open (DATA-02/04).

## References

- `laravel/docs/compliance/CONFIRMED-FACTS.md` — A3, B1, B2, B3, B4, B5, B6, B7, C4, C5, C8, D2, §E
- `CLAUDE.md` §1, §9, §14
- `HANDOFF.md` — "The product", item 4
- `laravel/docs/DEPLOY-LARAVEL.md` §0
- `laravel/docs/compliance/evidence/sec-web-2026-09-03.md` (Laravel A, legacy F)
- `laravel/docs/compliance/EVIDENCE-PACK.md` gap G15
