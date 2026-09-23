# REMAINING WORK — the living checklist

> **What this is.** Everything still open for the DMC Laravel app, in one place, split by **who has to
> act**. Tick a box when an item is done and add the date; move nothing silently. `HANDOFF.md` stays the
> narrative ground truth; this file is the list to work from.
>
> **Last verified: 2026-09-22.** Compiled from every tracker in the repo (HANDOFF, the compliance drafts
> and `OPEN-ITEMS.md`, `EVIDENCE-PACK.md`, the 2026-09-03 prod-ready close-out, the runbooks, the ADRs,
> `waivers.yml`, code TODOs), each item checked against the code and the commits since 2026-09-03, then
> adversarially re-checked. Live checks the same day: 0 open PRs / issues / Dependabot / secret-scanning
> alerts. Production was `379ef29` (release `v2026.09.22`) when this header was last touched on 2026-09-23;
> `HANDOFF.md` records each later deploy.
>
> **This repository is public.** Items below are worded so they do not hand an attacker a map; the
> specifics live with the owner.

**Where things stand, in one paragraph.** The Laravel app is built, hardened and live in parallel, with
backups, point-in-time recovery and restore drills proven. What stops it becoming the unit's daily system
is **not code**: the cutover decision and its UAT sign-off, a signed controller–processor contract, a
named DPO, and the hospital's legal answers. Meanwhile the **legacy site staff use every day remains the
biggest live risk** (the original un-hardened build, on US hosting).

---

## A. Owner — urgent

- [ ] **Cut over from the legacy site to the Laravel app.** Set a date; run the UAT checklist
  (`laravel/docs/UAT-TEST-PLAN.md` — every row and the Go/No-Go table are still blank; a technical dry
  run passed on 2026-09-23 in two passes — a local copy for everything that changes data, read-only
  checks on production for the figures and pages — after fourteen fixes; its "What still needs a person
  on the live site" list is the short human pass: real phone authenticator, real mailbox, real
  devices, one pass per role, and the Go/No-Go signature —
  `laravel/docs/compliance/evidence/uat-dry-run-2026-09-23.md`); plan staff
  communication and training; get the legacy host's backup-retention/deletion terms; inventory what the
  legacy site leaks through its own logs, URLs and exports. *(B1–B3, G11, G15, R13–R15;
  `laravel/docs/compliance/CONFIRMED-FACTS.md`)*
- [ ] **Plan the first sign-in day.** Almost no one has signed in to the new app yet: at their first
  sign-in each person verifies their email, sets up an authenticator app and chooses a new password.
  Two active accounts have no email address on file — add one (they cannot receive codes or reset
  links). *(2026-09-23 read-only check)*
- [ ] **Sign the controller–processor contract** between the hospital and the operating company — the top
  compliance action in every audit pass. *(A0/A5, G12, CMP-03)*
- [ ] **Appoint a DPO.** The DPO charter and the privacy notices still carry `[DPO NAME]` placeholders.
  *(A6, G9, CMP-06; `laravel/docs/compliance/DPO.md`)*
- [ ] **Destroy or encrypt the old patient-data exports kept on a personal workstation**, and keep an
  inventory of what was destroyed. (The copies on the production host were shredded and re-checked
  2026-09-22.) *(D1, G8, DATA-14)*
- [ ] **Make the GitHub repository private** before go-live (decided earlier; still public). *(B8, G10)*

## B. Owner — decisions and console work

- [ ] **Explain the 2026-09-16 outage.** The host stopped abruptly at 02:55 UTC and came back at 16:00
  UTC (~13 h); the system journal ends with no shutdown sequence, same kernel before and after, memory
  ample. Nothing paged anyone. Check the OCI console (instance events / maintenance / audit) for the
  cause. *(new, found 2026-09-22)*
- [ ] **On-call and paging, with agreed uptime targets.** Today nothing pages anyone out of hours.
  *(OPS-02/03, REL-01..05)*
- [ ] **Pick a log / error-tracking / metrics service.** The code side is ready (`LOG_STACK`); container
  logs are lost on every redeploy until then. *(OBS-01/03/04/05)*
- [ ] **Second-region backup copy** and **OCI instance-principal auth** for the backup/audit buckets
  (removes the standing static key). Needs OCI IAM. *(DATA-02/04, CFG-10)*
- [ ] **Single-reviewer waiver expires 2026-12-03** — renew with fresh reasoning or add a second reviewer.
  *(SEC-11, CICD-11, G17; `laravel/.prod-ready/waivers.yml`)*
- [ ] **Name a backup person** — today only the owner can deploy, decrypt backups or lead an incident.
  *(DATA-12)*
- [ ] **Commission an external penetration test.** *(SEC-09)*
- [ ] **Decide on column encryption for names / MRNs / diagnosis codes** (trade-off: they stop being
  searchable/sortable in SQL). *(G3)*
- [x] **End the session when the browser closes** — decided and shipped 2026-09-23. Closing the browser
  now signs the user out; a closed tab still lasts until the idle timeout. On shared ward computers, turn
  off the browser's "continue where you left off" start-up option, which can restore a session.
  *(2026-09-23 UAT, AUTH-07)*
- [ ] **Decide `log_record_opens`** (record every chart open — now a switch in Control → Settings) and **who reviews the export/report audit
  rows**, how often. *(R6, R12)*
- [ ] **Quarterly access review + joiner/leaver process** for both systems' accounts. *(R4, R11)*
- [ ] **Host and account hygiene:** SSH source restriction (deferred by owner, G7); a recurring
  patch/reboot window (R9, R10); confirm every GitHub collaborator has MFA; confirm the historically
  leaked legacy credentials were changed at their providers (CFG-04); a routine rotation schedule.
- [ ] **Keep GitHub Actions billing enabled** — if it lapses, CI silently checks nothing.
- [ ] *Optional:* Cloudflare WAF rules tuned for the app; clean up ~112 legacy records with non-numeric
  MRNs (D1, D3); revisit auto-deploy / auto-rollback (off by your decision, CICD-08).
- [ ] **Rehearse the application half of a server loss** (install the deploy platform, rebuild the app
  from source, repoint DNS) — the remaining unknown in the recovery time. Needs another temporary
  instance and about an hour.
- [x] **Start tagging releases** (`vYYYY.MM.DD`) — done from 2026-09-22 (`v2026.09.22`, `v2026.09.23`); CI signs what it builds on a tag — the
  provenance only exists for tagged commits, so an untagged deploy has none. *(CICD-05, §F)*

## C. Hospital legal / DPO — the owner chases

- [ ] **575 open markers + ~30 names/contacts/dates** across the nine compliance drafts — every marker
  already carries a proposed, sourced answer awaiting sign-off. *(`laravel/docs/compliance/OPEN-ITEMS.md`,
  `PROPOSED-CITATIONS.md`)*
- [ ] **Four legal decisions:** the registering entity; the medical-record retention period (needs the
  MoH Annex 5 — "ten years" is unverified); the classification tier for structured PHI; whether
  Cloudflare's out-of-Kingdom edge decryption is acceptable. *(A2, C1, C2, C4, C10, D4, G6)*
- [ ] **Cross-border transfer safeguards** for the Cloudflare edge and the US mail relay (which carries
  MFA and password-reset mail) — or move the relay in-Kingdom. *(G4, G5, G13, B5, B6)*
- [ ] **Patient data-subject-rights process** (access / correction / deletion via HIM) and **SDAIA
  controller registration**. *(CMP-02, R6)*
- [ ] **~19 facts only regulators or vendors can supply** (classification policy, retention annex, CNI
  status, breach deadline, adequacy list, vendor confirmations). *(OPEN-ITEMS)*
- [ ] Counsel's final sign-off on two hedged wordings (privacy-notice legal basis; incident-response
  breach definition).
- [ ] **90-day backup retention is a placeholder** until the retention decision lands.

## D. Clinical lead

- [ ] **Real ward and ICU bed counts** (Control → Settings). The placeholders (50 / 10) make the dashboard
  show ~260% ward occupancy.
- [ ] **A rule for printed and exported patient sheets** (handling, storage, disposal).

## E. Calendar — recurring duties

| Due | Duty | Where |
|---|---|---|
| ~2026-10-22, then monthly | Restore drill (`db-restore-drill.sh`), log it in §8 | `laravel/docs/BACKUP-AND-RESTORE.md` §4, §8 |
| 2026-12-03 | Single-reviewer waiver: renew or retire | `laravel/.prod-ready/waivers.yml` |
| each entry's `review_by` | Composer-audit ignore list — **empty since 2026-09-22** (every advisory fixed by an update); any entry added later carries its own review date | `laravel/.composer-audit-ignore.json` |
| ~2026-12-22, then quarterly (suggested) — and after any MySQL version change | PITR rehearsal (`pitr-rehearsal.sh`); rebuild the tools image on a version change | `BACKUP-AND-RESTORE.md` §10.2, §10.5 |
| ongoing | GitHub Actions billing on; host patching | — |

## F. Engineering (no owner decision needed)

### Done in the 2026-09-22 batch (one PR, branch `feat/engineering-batch-2026-09-22`)

Each group was built with its own tests, reviewed through two lenses and fixed; then the whole diff
had a cross-cutting adversarial review (6 more real problems found and fixed, listed at the end).

- [x] Per-user rate limits on every patient-data page, poll and admin read (240/min) and on exports /
  report generation (20/min), with a branded 429 page; login limiter key made multibyte-safe.
  *(PERF-08, I18N-05)*
- [x] MySQL connect timeout (5 s) and a web-only per-SELECT cap (60 s; never under artisan/scheduler/
  import; report renders raise it to 120 s); 10 s timeouts on the ICD-10 and bell fetches;
  `MAIL_TIMEOUT` documented. *(RES-01, CFG-06)*
- [x] Arabic text in PDF reports shaped and ordered correctly (in-house, no new dependency).
  *(I18N-06 — see the follow-up below for chart labels)*
- [x] Report PDFs regenerate on demand when a deploy has wiped the stored copy. *(PERF-07)*
- [x] Dependency advisories: patch updates taken; `composer audit` clean; the ignore list is now empty.
- [x] Race-safe duplicate-admission guard, including the brand-new-MRN case (was a 500). *(RES-06)*
- [x] COOP / CORP / fuller Permissions-Policy; the CSP comment now states the real inline-style reasons.
  *(SPC-WEB-002..004)*
- [x] `/health` reports database-vs-app clock skew (degraded above 120 s). *(R2)*
- [x] Daily `auth:prune-expired` (expired sign-ups, trusted devices, reset tokens; audited).
  *(R11)*
- [x] Read-only `records:retention-report --years=N` (counts only; **no delete mode** — deletion stays
  blocked on the legal retention decision, section C). *(CMP-02 scaffold)*
- [x] Nightly dump records its exact binlog position (`--source-data=2`); PITR uses it when present.
- [x] Pre-deploy dump documented as the encrypted `db-backup.py` run (runbook + release checklist);
  host swept 2026-09-22 — no plaintext DMC dump present.
- [x] Bucket lifecycle sized from measured volume (≈4.8 MB/day of binlogs, ≈2.3 MB per nightly dump →
  ≈640 MB at the 90-day placeholder). Setting the rule itself is console work (section B).
- [x] Whole-server-loss runbook written (**unrehearsed**). *(RES-09)*
- [x] Audit-trail reconciliation after any restore, with a read-only `audit:ship --status`.
  *(R8, R11)*
- [x] Stale doc lines fixed (DEPLOY, CI, DATA-CLASSIFICATION, smoke.sh now FAILs on a missing
  `/health` / `security.txt`).
- [x] Cross-cutting review fixes: the background booklet job now gets the render budget; a throttled
  bell refresh no longer blanks the notifications; the admission detail read is throttled; the app+DB
  rollback and the server-loss runbook re-freeze after the container swap (maintenance mode does not
  survive one); the release checklist matches the encrypted dump.

### Still open — engineering

- [x] ~~Reinstall the host copy of `db-backup.py`~~ — **done 2026-09-22** with the deploy; the nightly
  dump now records its exact binlog position.
- [x] ~~Exercise the PITR `--start-position` path~~ — **done 2026-09-22**: a dump taken with the new
  script was replayed from its own recorded position (rehearsal PASS, 107 events, recovered rows equal
  to live). The rehearsal script now reads the coordinate itself and falls back to a timestamp only for
  older dumps.
- [x] ~~Chart labels in PDF reports~~ — **not reproducible 2026-09-22**, closed with a regression test:
  an Arabic chart label renders as real glyphs in the PDF (`ReportSvgArabicLabelTest`), including on a
  page carrying no other Arabic.

### Decided 2026-09-22 (owner said yes to all four)

- [x] **Style security policy tightened** — `'unsafe-inline'` removed from every directive; Inertia's
  runtime styles now carry the nonce. Verified in a real browser against a production build: login,
  two-factor, dashboard, board, statistics, consultations, handovers, registry, reports, control,
  active list, style guide, the tour and the command palette — zero violations, every style intact.
- [x] **Signed release provenance** — a release job attests the SBOM and the built bytes on a pushed
  `vYYYY.MM.DD` tag (`gh attestation verify` checks a download). It attests what CI built, not the
  container Coolify builds on the host — stated plainly in the docs.
- [x] **Infrastructure as code** — [`infra/`](infra/): Terraform for the server, network, firewall,
  buckets and DNS, plus a host bootstrap script. **Never applied, never validated** (no Terraform
  here); adopting it needs a plan and an import of the live resources first. Read `infra/README.md`.
- [x] **Rehearsed losing the whole server** — 2026-09-22, on a temporary instance since destroyed.
  **The data comes back in about 3 minutes of machine time** from the off-box archive alone, and the
  recovered audit trail was byte-identical to production's. It found three defects in the recovery
  procedure itself (the recovery tools image could not be built; the replay had no way to find the
  archived logs once the server that listed them was gone; that listing was then truncated) — all
  fixed. **The application half is still unrehearsed**: installing the deploy platform, rebuilding the
  app and repointing DNS are what would dominate a real outage.

---

*Maintenance: when something closes, tick it here with the date and the PR/commit, and update the
matching line in `HANDOFF.md`. When a new item appears, add it to the right section with its source.*
