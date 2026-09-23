# HANDOFF — current state, and what remains

> Single ground-truth orientation for the next review session. Read this first (with `CLAUDE.md`).
> Last updated 2026-09-23 (a role-by-role walkthrough of every function on a local copy, and the eight
> fixes it prompted); CI green.
>
> **The working checklist of everything still open, by who acts, is [`REMAINING-WORK.md`](REMAINING-WORK.md).**
> Tick items there as they close; this file keeps the narrative.

## The product

- **The shipped product is the Laravel app under [`laravel/`](laravel/).** Stack: Laravel 13 +
  Inertia 3 + Vue 3 (`<script setup>`) + Tailwind v4 + MySQL 8.4 + **Chart.js 4** (MIT).
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
  `backup:verify`, a **proven restore drill** (monthly), and hourly encrypted binlog shipping with a
  **rehearsed point-in-time recovery** (2026-09-22: 50 s, exact row match, chain intact). See
  `laravel/docs/BACKUP-AND-RESTORE.md` §8 and §10.
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
- **Gate baselines (2026-09-23, after the second UAT pass):** PHPUnit 1057 (+92 in the `pdf` group), PHP statement
  coverage 88.1 % at the last CI measurement (floor 83), Vitest 824 on vitest 5 (floors 72/66/62/48 lines/statements/branches/
  functions — re-baselined 2026-09-22 for the new coverage engine, then raised the same day by the
  IcdTypeahead + ActivityPanel specs), ESLint zero warnings, Pint clean.

## What remains (for the next session, with the owner)

1. ~~**Rewrite `CLAUDE.md`** to describe the **Laravel product as it is**~~ — **DONE 2026-09-03.**
   `CLAUDE.md` now maps the Laravel product; the legacy mental model lives in git history at
   `31f0bfb` and on the `renovation` branch.
2. **Compliance placeholders — PARTLY DONE 2026-09-03.** The cross-cutting facts were confirmed
   with the owner and applied to all nine drafts (record:
   [`CONFIRMED-FACTS.md`](laravel/docs/compliance/CONFIRMED-FACTS.md)); every legal marker has a
   sourced, proposed citation in [`PROPOSED-CITATIONS.md`](laravel/docs/compliance/PROPOSED-CITATIONS.md)
   for counsel to verify. ~~(c) the deeper per-activity rework of ROPA / DATA-RETENTION / DPIA to the
   processor-and-legacy-daily framing~~ — **DONE 2026-09-03 (PR #18)**: all three describe the real
   picture (DMC as controller, the operator company as processor with no contract yet, the legacy
   daily system on US hosting, the Laravel parallel copy as its own RoPA activity A10, DPIA risks
   R13–R15), `CONFIRMED-FACTS.md` C12/C13 reconciled with the shipped export auditing and labelling,
   `DATA-CLASSIFICATION.md` aligned, and `OPEN-ITEMS.md` regenerated against the live files (575
   markers, machine-checked; 30 further keyword-final placeholders such as `[DPO NAME]` are listed
   in its banner). **Still parked by the owner (resume when ready):** (a) the `[NAME]` / `[DATE]` /
   contact placeholders still owed — operator company legal name + CR, DPO, Head of IM, HIM office
   contacts, SDAIA complaint channel; (b) the counsel/DPO **DECISION** rows — registering entity,
   medical-record retention period (MoH Annex 5), classification tier, Cloudflare edge decryption.
   Master checklist: [`OPEN-ITEMS.md`](laravel/docs/compliance/OPEN-ITEMS.md).
3. **Evidence pack — DRAFTED 2026-09-03.** [`EVIDENCE-PACK.md`](laravel/docs/compliance/EVIDENCE-PACK.md)
   maps 20 PDPL obligations + NCA domains to evidence, with gap register G1–G17 (G1, G2 and G14
   closed; G17 is the dated SEC-11/CICD-11 risk acceptance) and dated evidence
   under `laravel/docs/compliance/evidence/`. Extend to ISO 27001 / SOC 2 / CBAHI only if pursued.
4. **Owner-side ops:** APP_KEY escrow (done), SSH IP allowlist (deferred by owner), keep GitHub
   Actions billing enabled, **make the repo private before go-live** (public by owner decision for
   free CI during development), sign the **controller–processor contract** (none exists), appoint
   the **DPO**, decide on the **legacy daily site** (original un-hardened build live on SiteGround
   US — graded F; hardened `renovation` build never deployed there; owner plans cutover instead).
5. ~~Optionally re-run `/prod-ready`~~ — **DONE 2026-09-03, twice.** Morning: **BLOCKED 58/100**
   (emphasis 70; was 27/37) —
   [`evidence/prod-ready-2026-09-03.md`](laravel/docs/compliance/evidence/prod-ready-2026-09-03.md).
   Evening re-score after the fixes below shipped: **NEEDS FIXES 62/100, 0 Critical, 14 High**
   (emphasis 72) —
   [`evidence/prod-ready-2026-09-03-rescore.md`](laravel/docs/compliance/evidence/prod-ready-2026-09-03-rescore.md)
   (delta table + ranked open items; read its orchestrator notes on auditor variance before
   comparing category numbers).
   **Top fixes — status after the same-day follow-through (PR #7 merged, deployed as `3fbbd73`,
   smoke 14/14, audit chain intact):** ~~(1)~~ **DONE** — four required, admin-enforced status
   checks on `main`, PR-only; ~~(2)~~ **DONE** — `SESSION_DRIVER`/`CACHE_STORE=database` live and
   verified; ~~(3)~~ **DONE** — SMTP `timeout` 10 s; ~~(4)~~ **DONE** — "today" bound from the app
   clock in place of raw `CURDATE()` (Dashboard / DataQuality / Registry), regression-tested by
   `AppClockDayBoundaryTest` — **never** by pinning the MySQL session time zone (it would shift every
   `TIMESTAMP` column by three hours); ~~(5)~~ **DONE** — CI on PHP 8.3 / MySQL 8.4, blocking Pint
   gate (codebase normalised), Vitest coverage thresholds enforced, and a PHP statement-coverage
   floor of 83% over `app/` enforced by `scripts/coverage-gate.php` from PHPUnit's Clover report
   (measured 86.15% on 2026-09-03; Collision's own `--coverage` table never rendered on the runner,
   so the gate reads the file PHPUnit writes); (6) **open,
   owner** — name incident roles / DPO / sign the processor contract; ~~(7)~~ **DONE** — labelled
   Login and Admission forms, axe-clean. Runbook/README wording about CI updated in the same
   follow-through.
   **Engineering close-out (same day, PR #10 + hardening PR #11) — DEPLOYED as `a4dd4bd` at
   17:13 UTC on 2026-09-03** after an adversarial pre-deploy review (five lenses, three refuters
   each; all ten raised findings refuted, six cheap hardenings folded into #11): smoke 15/15 with
   the new `__Host-` cookie check, `/health` ok, audit chain intact, image tag = main HEAD. Note:
   production's `LOG_CHANNEL` is already `stderr`, so the JSON log format went live with this
   deploy (Coolify's log viewer now shows JSON lines); `LOG_LEVEL=warning` existed in Coolify only
   as a build-time variable (runtime level was `debug`) — **fixed 2026-09-03 18:13 UTC** with a
   runtime variable, verified in the container. **Same evening, owner-approved:** rollback
   rehearsal done on production (50 s swap to the previous image, 121 s roll-forward; recorded in
   DEPLOY-LARAVEL §4.4 with the API method), second restore drill logged (7 s), the plaintext
   dumps + private deploy key + credential files under `/home/ubuntu/migrate/dmc/` **shredded**
   (only three harmless scripts and empty storage folders remain), Legacy CI switched to
   manual-only, and MySQL binlog confirmed already ON (8.4 default, ROW, 30-day expiry) — **hourly
   off-box binlog shipping + PITR runbook shipped (PR #19, two adversarial reviews) and installed on
   the host at 19:21 UTC**: first run archived both closed binlogs in 47 s, `--restore-check` OK,
   cron `/etc/cron.d/dmc-binlog-ship` (minute 40) + `/etc/logrotate.d/dmc-backup` in place; RPO is
   now ≤ 1 h for binlog-covered changes. **Then the follow-through docs landed:** PR #14 CODEOWNERS
   + security-policy contact pointer, #15 one h1 per page, #16 DATABASE-AND-BEHAVIOR resynced from
   code (14 corrections), #17 ten ADRs under `laravel/docs/adr/`, #18 the parked item 2(c) — RoPA /
   retention / DPIA reworked to the processor-and-legacy-daily framing, C12/C13 reconciled, OPEN-ITEMS
   regenerated (575 live markers, 30 keyword-final placeholders documented in its banner).
   **Deployed as `a855973` at 19:53 UTC (owner-approved, dumped first):** the h1 fix, the binlog
   heartbeat check (`backup:verify` now prints "Point-in-time recovery: fresh"), and the docs;
   smoke green, audit chain intact. Same approval round: the Coolify **stop grace period set
   explicitly to 30 s** (RES-12 — Coolify's default was already 30 s, verified in its source; the
   PHP-FPM `process_control_timeout` idea is inapplicable because no stop signal reaches FPM under
   the Nixpacks PID-1 shell; DEPLOY-LARAVEL §10), and the **single-maintainer review gap recorded as
   a dated waiver** (`laravel/.prod-ready/waivers.yml`, SEC-11 + CICD-11, expires 2026-12-03,
   EVIDENCE-PACK G17).
   **Run-3 score (full fresh audit of all 16 categories + five re-audits after the merges):
   NEEDS FIXES 71/100 with the SEC-11/CICD-11 waivers applied (70 without), emphasis 78,
   0 Critical / 10 High / 17 Medium / 5 Low** —
   [`evidence/prod-ready-2026-09-03-closeout.md`](laravel/docs/compliance/evidence/prod-ready-2026-09-03-closeout.md).
   Remaining Highs, all owner/infra: SEC-11 + CICD-11 (a second reviewer — waiver candidate with one
   maintainer), OPS-02/03 (on-call + paging), CICD-08 (auto rollback; deploys stay manual by
   decision) and the separate RES-12 drain step, DATA-02 (off-region backup copy — **closed
   2026-09-24 by owner decision: Riyadh only**, plus an owner-kept local copy) + CFG-10 (OCI
   instance principal) + OBS-03/04 (error/metrics sink) — all need an OCI IAM step, CICD-05
   (SBOM/attestations), I18N-02 (accepted: never pin the MySQL session time zone). Deploy-on-green stays OFF by owner decision — the owner's stated reason (2026-09-03): "I don't
   want to autodeploy, to make sure nothing goes wrong on production" (recorded here so ADR 0005 has
   its rationale).
   Contents: audit rows + `SECRET-`/`CONFIDENTIAL-` filename
   prefixes + PDF/print footers on every export (G1, G2); labels paired on the six remaining forms
   (UX-04 fully closed) with axe specs; `__Host-` session cookie in config (G14); ESLint + vue
   plugin as a blocking CI gate with a zero-warning baseline (TST-04); unhandled-rejection net +
   readable fetch errors (TST-10); `mb_strtolower` on login lookups (I18N-05); ChartCanvas
   code-split (PERF-01); monthly-report per-recipient failure isolation (RES-05); dashboard
   single-flight cache with jittered TTL (RES-08); JSON stderr log channel wired, production opt-in
   via `LOG_STACK=daily,stderr` (OBS-01 code part); in-app privacy text synced with the drafts;
   `scripts/deploy-on-green.sh` prepared (not enabled); drain-step settings documented (RES-12);
   legacy CI no longer fires on docs-only changes; root README current.
   **Engineering decisions closed 2026-09-22** (the items earlier reports listed as "left as
   decisions"; adversarially reviewed, 10 findings folded in before merge): ~~PERF-03~~ — the
   long-term registry, the one board query with no natural bound, is capped at 1000 with every
   **open** episode protected (a long-stay patient still in a bed has the oldest admit_date, so a
   naive newest-first cap would have dropped exactly the patients the view exists for) and the page
   told when it trimmed; ~~I18N-02~~ — the unpinned MySQL session timezone stays accepted but is now
   *enforced*: `scripts/clock-guard.php` blocks new raw-clock SQL unless `.clock-allowlist.json`
   records why the column opposite is DB-written, a tripwire test fails if anyone pins the config,
   and DEPLOY-LARAVEL §10 carries the data migration pinning would really require; ~~CICD-05
   (SBOM half)~~ — `scripts/sbom.php` emits a deterministic CycloneDX 1.5 document of both lock
   files, archived per run. Signed provenance stays open by nature: CI builds no release artifact
   to attest.
   **2026-09-22 later — deploy, drill, PITR rehearsal (owner: "deploy main, dump first and complete
   the engineering things"):** `main` @ `fc44a0b` deployed after a pre-deploy dump (smoke 15/15,
   audit chain intact); the two components no spec loaded got real ones (IcdTypeahead 15 tests,
   ActivityPanel 13) and the Vitest floors went up to 72/66/62/48. Writing the IcdTypeahead spec
   exposed a **clinical race**: a slow ICD-10 lookup answering after a pick reopened the list under
   the clinician's next Enter, adding a diagnosis nobody chose — fixed with a generation guard, and
   a failed or non-OK lookup now shows nothing instead of throwing. Monthly restore drill OK (7 s).
   **First PITR rehearsal**: the documented recovery procedure did **not** work as written — the
   `mysql:8` image has no `mysqlbinlog`, and the chain-check command would have verified the
   **live** database (the app container's `DB_DATABASE` is a process env var, which a `.env` file
   never overrides). Both fixed (`scripts/backup/pitr-tools.Dockerfile`, a one-off verify
   container), then rehearsed twice on a throwaway server with `scripts/backup/pitr-rehearsal.sh`:
   base 02:15 dump + 10 binlogs replayed to 10:30 in 50 s, recovered `audit_log` = exactly the 861
   live rows before the stop time, `audit:verify` intact, nothing left behind. The runbook's
   chain-check form was also proven read-only against the production server. **Production runs the
   merge of PR #26** (deployed 2026-09-22, dumped first). The old `/home/ubuntu/migrate/dmc/` folder
   was re-checked the same day — scripts and `.gitignore` files only, no dumps, env files or keys —
   so it is off the open list.
   **2026-09-22, engineering batch (owner: "do the engineering batch as one PR"):** every engineering
   item from the remaining-work review, built in six file-disjoint groups (each with its own tests and a
   two-lens review), then a cross-cutting adversarial review of the whole diff that found six more real
   problems — all fixed, including a report job left on the new 60 s SELECT cap and a pre-existing
   rollback-runbook gap (maintenance mode does not survive a container swap). Highlights: per-user rate
   limits on patient data, database and fetch timeouts, Arabic text in PDF reports, self-healing report
   downloads, a race-safe duplicate-admission guard (the brand-new-MRN case used to be a 500), COOP/CORP,
   a clock-skew check in `/health`, a daily expired-row prune, a read-only retention report, the nightly
   dump recording its binlog position, and a whole-server-loss runbook (unrehearsed). The full list and
   what it left open are in [`REMAINING-WORK.md`](REMAINING-WORK.md) §F. Deployed 2026-09-22 with the next
   item (production `379ef29`); the host copy of `db-backup.py` was reinstalled the same day. Also found that day: the host was down
   for ~13 h on 2026-09-16 (02:55–16:00 UTC, abrupt stop, nobody paged) — cause to be checked in the OCI
   console (§B there).
   **2026-09-22, the four approved items + a whole-server-loss rehearsal:** the style policy dropped
   `'unsafe-inline'` (browser-verified across every page), CI signs a tagged release's SBOM and built
   bytes, `infra/` describes the live infrastructure as unapplied Terraform, and the **data half of
   RES-09 is now rehearsed** on a throwaway instance (≈3 min of machine time; recovered `audit_log`
   digest identical to production's; instance destroyed). That rehearsal found three defects in the
   recovery procedure — the PITR tools image could not build from its stale version pin, the replay
   had no inventory once the shipper's state file died with the host (`db-backup.py --list-objects`
   now provides one), and that listing was truncated by a 64 KB body cap — all fixed. The application
   half (deploy platform, image rebuild, DNS) is still unrehearsed and is what a real RTO hinges on.
   **2026-09-23, role walkthrough (owner: "use the website using all the user roles and walk through
   all the functions"; then "fix all and deploy after"):** a technical dry run of `UAT-TEST-PLAN.md` on
   a **local copy with fake demo data** — eight walkthrough accounts (every role and capability
   variant), signed in through a local-only link so no password was ever typed, each state change
   checked in the database, on screen and in the statistics, plus a route-by-route authorization sweep
   and an independent recomputation of every dashboard and statistics figure (28/28 matched). 190 rows:
   180 pass. It found **eight real defects** — the worst: the patient-merge pickers rendered **nothing**
   in the built app (a runtime template string that only the test build could compile), and
   deactivating or deleting a user left their open sessions working. All eight are fixed with
   regression tests and re-verified live; details and the doc corrections (Observer scope, resident
   Can-Manage, Active List scope) in
   [`evidence/uat-dry-run-2026-09-23.md`](laravel/docs/compliance/evidence/uat-dry-run-2026-09-23.md).
   This is **not** the clinical UAT sign-off — the cutover item in `REMAINING-WORK.md` still needs the
   owner and clinicians on the real system. **Production runs `afa7f60`** (merge of PR #29, release
   `v2026.09.23`, build and SBOM attestations verified), deployed 2026-09-23 after a pre-deploy dump;
   no migrations; smoke 15/15, `/health` ok, audit chain intact, both backup heartbeats fresh, and
   the served merge-page bundle confirmed to be the compiled picker. The host's `binlog-ship.py` was
   reinstalled the same day (it predated the 2026-09-22 wrong-key message fix; the old copy is kept
   as `.prev`).
   **2026-09-23, second pass (owner: "try to complete these too" — test accounts and data are
   temporary):** the rows the first pass could not run, on three more isolated local servers set up
   like production — every credential journey as automated test code (nobody typed a password or code
   into the site), the leftover functional rows, a browser / accessibility pass — plus **read-only
   checks on production** that print numbers only: all 52 dashboard / statistics / consultation / Active
   List figures match an independent recomputation on the real data, and 148 page loads across the four
   roles present had no errors, no access breaches and no writes. That found **six more defects, all
   fixed with tests**: impossible discharge dates crashed instead of showing a message; **Admin →
   Patient Merge took 57 s on the real volume** (over the 60 s web limit — it could not open on the
   live site; now 116 ms, identical results); forgot-username showed no confirmation; light-mode grey
   and teal text below WCAG AA; the header title squeezed out on phones and tablets; the closed mobile
   menu still reachable with Tab. Details and the short list that still needs a person on the live site
   are in the same evidence file. **Production runs `a13a845`** (merge of PR #31, release
   `v2026.09.23.2`, build and SBOM attestations verified), deployed 2026-09-23 after a pre-deploy dump;
   no migrations; smoke 15/15, `/health` ok, audit chain intact, backup heartbeats fresh, and the live
   Patient Merge duplicate finder measured in the new container at 124 ms (14 pairs).
   **2026-09-23, owner decision — the session ends when the browser closes** (`expire_on_close`
   defaults to true; the cookie no longer carries Max-Age=7200). A test and a new `smoke.sh` check
   (16 checks now) guard it. **Production runs `4bd5bba`** (merge of PR #33, release `v2026.09.23.3`,
   attestations verified), deployed after a pre-deploy dump; the live session cookie has no
   Expires / Max-Age, smoke 16/16, `/health` ok, audit chain intact.
   **2026-09-23 night, operations (owner: "do these for now"):** SSH restricted to the owner's
   workstation; the host patched (38 packages incl. Docker) and rebooted — all 31 containers of every
   app came back, DMC smoke 16/16; the monthly restore drill and the quarterly point-in-time rehearsal
   run (both pass); the **16 September outage explained** from OCI's own records (Oracle stopped and
   later restored the server — an infrastructure failure, not ours); the backup key can no longer
   delete; the **Jeddah backup copy was blocked** by the tenancy's own residency quota
   (`ksa-data-residency` zeroes storage outside `me-riyadh-1`, Jeddah included) — and on 2026-09-24
   the owner **chose Riyadh only** (plus a local copy they keep); the empty Jeddah bucket was deleted;
   the **cutover reload rehearsed** on a copy of
   production (29 s, MFA and settings kept) and the **application half of a server loss rehearsed**
   (≈ 7 min to a serving app) — the two rehearsals found three procedure defects (database sessions
   not cleared by the reload runbook; an empty database can never pass the first deploy's health
   check; an undocumented Nixpacks setting production needs), all fixed in the runbooks. Still open
   from the list: instance-principal auth (an engineering change, see REMAINING-WORK).
   **2026-09-24 (owner: "stay in Riyadh … forget about Jeddah"; "you may delete the files"):** backups
   stay Riyadh-only (DATA-02 closed by decision; the empty Jeddah bucket deleted). A read-only sweep of
   the owner's workstation (Google Drive excluded, as asked) moved **38 DMC files** to the Recycle Bin
   with a hashed inventory (`laravel/docs/compliance/evidence/workstation-phi-cleanup-2026-09-24.md`);
   emptying the bin is the owner's. It also found, and left for the owner: real-data databases in the
   laptop's WAMP, and the owner's daily local mirror of the `coolify-backups` bucket — which revealed
   that **Coolify's own backup writes unencrypted daily dumps of `dmc_demo` with no expiry**. Checking
   OCI for cross-region copies found none, but did surface the host's weekly boot-volume backups and
   a manual full one from 2026-07-19 with no expiry. All three are owner decisions in REMAINING-WORK.
   **Still open and all owner / infrastructure decisions, not code:** enable deploy-on-green
   (declined so far — "I don't want to autodeploy"), pick a log sink and set `LOG_STACK`
   (OBS-01/03/04/05), instance principal (CFG-10), SLOs and an
   on-call/paging channel (OPS-02/03, REL-01..05), repo private before go-live, the legacy daily site, contracts / DPO /
   names / counsel decisions (CMP-03/06 and item 2 above).

## Doc map

| Area | Files |
|---|---|
| Deploy / ops | `laravel/docs/{DEPLOY-LARAVEL, BACKUP-AND-RESTORE, ENCRYPTION-AT-REST, CI, RELEASE-CHECKLIST}.md` |
| Behaviour / metrics | `laravel/docs/{DATABASE-AND-BEHAVIOR, DASHBOARD-AND-STATISTICS-METRICS, HANDOVER-COMPLIANCE, RECONCILIATION, UAT-TEST-PLAN}.md` |
| Compliance (PDPL paper trail) | `laravel/docs/compliance/` + `OPEN-ITEMS.md` (the placeholder checklist) + `PROPOSED-CITATIONS.md` (every legal marker → proposed citation, source, confidence; for counsel) + `EVIDENCE-PACK.md` (control → evidence map for auditors) |
| Decisions (ADRs) | [`laravel/docs/adr/`](laravel/docs/adr/) — MADR records backfilled from the recorded decisions (cutover flag, DB sessions, audit chain, narrative encryption, deploy trigger, branch rule, hosting, runtime shape, MFA, backups); start at its `README.md` |
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
