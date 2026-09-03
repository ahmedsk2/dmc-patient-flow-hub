# HANDOFF — current state, and what remains

> Single ground-truth orientation for the next review session. Read this first (with `CLAUDE.md`).
> Last updated 2026-09-03 (after the compliance fact-fill and the prod-ready re-score); CI green.

## The product

- **The shipped product is the Laravel app under [`laravel/`](laravel/).** Stack: Laravel 13 +
  Inertia 2 + Vue 3 (`<script setup>`) + Tailwind v4 + MySQL 8.4 + **Chart.js 4** (MIT).
- **Live** at `https://dmc-new.towardpcc.com` (Cloudflare-proxied), deployed via **Coolify** on an
  OCI Riyadh host. This is a **live clinical system with real PHI** (~17k patients, ~37k admissions,
  ~331 users). **Saudi PDPL / SDAIA applies** (in-Kingdom data residency for the Laravel app).
- **GROUND TRUTH (2026-09-03):** the Laravel app runs **in parallel** with a copy of the data but is
  **not yet the daily system**. Staff still use the **legacy PHP app at `dmc-im.com` on SiteGround
  (US)**, and that live site is the **original un-hardened build** (verified) — the systemic
  security defects are live over real PHI. Cutover replaces the dmc-im.com code with the Laravel app.
  See [`laravel/docs/compliance/CONFIRMED-FACTS.md`](laravel/docs/compliance/CONFIRMED-FACTS.md).
- **Branch model (changed 2026-09-03):** `main` is **protected — pull requests only**, with the four
  Laravel CI jobs as required, admin-enforced status checks (branch, `gh pr create`, merge on green;
  direct pushes are rejected). `renovation` points to the deployable legacy-PHP lineage. The legacy procedural-PHP app
  still sits at the repo root; it is the current **daily** system in its original build, and the
  hardened `renovation` build was never deployed to dmc-im.com.
- Infra facts, the exact deploy procedure, and environment gotchas are held in **Claude memory**
  (loaded automatically each session) — rely on it rather than re-deriving.

## Guardrails (non-negotiable)

- Real patient PHI. **Never fabricate** clinical / handover / audit records. **Never delete**
  notification or audit rows (retained trail). **No PHI in URLs or logs.** The **owner handles all
  secrets** — never ask them to paste one, never print or commit one. **Confirm** destructive or
  outward-facing actions before doing them.

## What is already shipped and live

- **Security:** git history purged of the three leaked secrets; GitHub secret-scanning + push
  protection + Dependabot + branch protection on; Cloudflare min-TLS 1.2, security headers, edge
  rate-limit, and geo-challenge on the auth pages; host patched and rebooted; `LOG_LEVEL=warning`,
  `SESSION_ENCRYPT=true`, `APP_TIMEZONE=Asia/Riyadh`.
- **Backups:** nightly **encrypted off-box** DB backup to an **in-Kingdom** OCI bucket, a daily
  `backup:verify`, and a **proven restore drill**. See `laravel/docs/BACKUP-AND-RESTORE.md`.
- **Encryption at rest:** the free-text clinical narrative columns are encrypted
  (`App\Casts\EncryptedNarrative`); **`APP_KEY` is the root of trust** (escrowed by the owner).
  See `laravel/docs/ENCRYPTION-AT-REST.md`.
- **Auth:** mandatory MFA + email verification; phased self-registration.
- **Consultation ledger:** 4-state per-specialty bookkeeping, cutover live.
- **CI:** hardened and green — Vitest+axe, PHPUnit+composer-audit, gitleaks, Semgrep; actions
  SHA-pinned. *GitHub Actions billing must stay enabled or CI silently executes nothing.*
- **Charts:** migrated ApexCharts → **Chart.js (MIT)** (resolves the ApexCharts licence question).
- **`npm audit`: 0 vulnerabilities.**
- **Login:** a trust-badge row of six **truthful** claims (encrypted in transit / at rest, MFA,
  in-Kingdom hosting, backups, privacy-notice link). No framework badges until certificates exist.
- **Docs:** pruned of dev scaffolding; PDPL paper-trail drafts in `laravel/docs/compliance/`.
- **Gate baselines:** PHPUnit ~926 (+56 in the `pdf` group), Vitest 717.

## What remains (for the next session, with the owner)

1. ~~**Rewrite `CLAUDE.md`** to describe the **Laravel product as it is**~~ — **DONE 2026-09-03.**
   `CLAUDE.md` now maps the Laravel product; the legacy mental model lives in git history at
   `31f0bfb` and on the `renovation` branch.
2. **Compliance placeholders — PARTLY DONE 2026-09-03.** The cross-cutting facts were confirmed
   with the owner and applied to all nine drafts (record:
   [`CONFIRMED-FACTS.md`](laravel/docs/compliance/CONFIRMED-FACTS.md)); every legal marker has a
   sourced, proposed citation in [`PROPOSED-CITATIONS.md`](laravel/docs/compliance/PROPOSED-CITATIONS.md)
   for counsel to verify. **Parked by the owner (resume when ready):** (a) the `[NAME]` / `[DATE]` /
   contact placeholders still owed — operator company legal name + CR, DPO, Head of IM, HIM office
   contacts, SDAIA complaint channel; (b) the counsel/DPO **DECISION** rows — registering entity,
   medical-record retention period (MoH Annex 5), classification tier, Cloudflare edge decryption;
   (c) the deeper per-activity rework of ROPA / DATA-RETENTION / DPIA to the processor-and-legacy-daily
   framing (each carries a confirmed-inputs callout; internal tables still read as if the hospital
   operates the Laravel app). Master checklist: [`OPEN-ITEMS.md`](laravel/docs/compliance/OPEN-ITEMS.md).
3. **Evidence pack — DRAFTED 2026-09-03.** [`EVIDENCE-PACK.md`](laravel/docs/compliance/EVIDENCE-PACK.md)
   maps 20 PDPL obligations + NCA domains to evidence, with gap register G1–G16 and dated evidence
   under `laravel/docs/compliance/evidence/`. Extend to ISO 27001 / SOC 2 / CBAHI only if pursued.
4. **Owner-side ops:** APP_KEY escrow (done), SSH IP allowlist (deferred by owner), keep GitHub
   Actions billing enabled, **make the repo private before go-live** (public by owner decision for
   free CI during development), sign the **controller–processor contract** (none exists), appoint
   the **DPO**, decide on the **legacy daily site** (original un-hardened build live on SiteGround
   US — graded F; hardened `renovation` build never deployed there; owner plans cutover instead).
5. ~~Optionally re-run `/prod-ready`~~ — **DONE 2026-09-03: BLOCKED 58/100** (emphasis 70; was
   27/37). Report + ranked fixes:
   [`evidence/prod-ready-2026-09-03.md`](laravel/docs/compliance/evidence/prod-ready-2026-09-03.md).
   **Top fixes — status after the same-day follow-through (PR #7 merged, deployed as `3fbbd73`,
   smoke 14/14, audit chain intact):** ~~(1)~~ **DONE** — four required, admin-enforced status
   checks on `main`, PR-only; ~~(2)~~ **DONE** — `SESSION_DRIVER`/`CACHE_STORE=database` live and
   verified; ~~(3)~~ **DONE** — SMTP `timeout` 10 s; ~~(4)~~ **DONE** — "today" bound from the app
   clock in place of raw `CURDATE()` (Dashboard / DataQuality / Registry), regression-tested by
   `AppClockDayBoundaryTest` — **never** by pinning the MySQL session time zone (it would shift every
   `TIMESTAMP` column by three hours); (5) **MOSTLY DONE** — CI on PHP 8.3 / MySQL 8.4, blocking Pint
   gate (codebase normalised), Vitest coverage thresholds enforced; **open:** a PHP coverage floor
   (Collision printed no table with pcov on PHPUnit 12 — diagnose before re-adding); (6) **open,
   owner** — name incident roles / DPO / sign the processor contract; ~~(7)~~ **DONE** — labelled
   Login and Admission forms, axe-clean. Runbook/README wording about CI updated in the same
   follow-through. Still open from the report: CICD-08 (manual smoke/rollback), OBS-01/03/04/05,
   DATA-02/04, OPS-02/06, CMP-03/06, the `__Host-` cookie prefix (G14), root `README.md`
   ("22 tests").

## Doc map

| Area | Files |
|---|---|
| Deploy / ops | `laravel/docs/{DEPLOY-LARAVEL, BACKUP-AND-RESTORE, ENCRYPTION-AT-REST, CI, RELEASE-CHECKLIST}.md` |
| Behaviour / metrics | `laravel/docs/{DATABASE-AND-BEHAVIOR, DASHBOARD-AND-STATISTICS-METRICS, HANDOVER-COMPLIANCE, RECONCILIATION, UAT-TEST-PLAN}.md` |
| Compliance (PDPL paper trail) | `laravel/docs/compliance/` + `OPEN-ITEMS.md` (the placeholder checklist) + `PROPOSED-CITATIONS.md` (every legal marker → proposed citation, source, confidence; for counsel) + `EVIDENCE-PACK.md` (control → evidence map for auditors) |
| Legacy app (history only) | repo root: `REVIEW-FINDINGS.md`, `RENOVATION-PLAN.md`, `PERMISSION-MATRIX.md`, `PROJECT-*.md`; the old `CLAUDE.md` at commit `31f0bfb` |

## Working style — token economy (the owner's standing priority)

- Pin a **cheaper model** (`haiku`/`sonnet`) on **every** subagent; never let one inherit the
  session model.
- **No Workflow / adversarial fan-out by default** — only on explicit request or a genuinely
  high-risk change (schema, auth, clinical logic).
- Read narrowly (`offset`/`limit`, `grep`); tail logs and test output.
- Prefer a **fresh session per task**; long sessions re-send a large context every turn.
- Deploy via the Coolify enqueue in memory; if a build fails inside Nixpacks *before* npm/composer
  run, it is a transient GitHub-fetch error — just re-enqueue.
