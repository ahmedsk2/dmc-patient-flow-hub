# Data retention and destruction schedule — DMC Internal Medicine patient-flow hub

> **Confirmed inputs (2026-09-03) — see [`CONFIRMED-FACTS.md`](CONFIRMED-FACTS.md).** Controller: **Dammam Medical Complex**, under the **Eastern Health Cluster** (Saudi Health Holding Company); public-sector ownership, exact registering entity for counsel to confirm. Primary processor: **the developer/operator company** (holds the code, the OCI tenancy and the domain) — **no controller–processor contract exists yet (top action)**. DPO **not yet appointed** (treated as mandatory). The daily production system is still the **legacy PHP app on `dmc-im.com` (SiteGround, United States), original un-hardened build**; the Laravel app runs in parallel until cutover. Review: annual (next 2027-09-03), owner interim until a DPO is named. Legal citations are **[PROPOSED]** — see [`PROPOSED-CITATIONS.md`](PROPOSED-CITATIONS.md).

> **DRAFT — for review by the hospital's legal / data-protection officer and clinical governance; not legal advice.**
>
> Version 0.1 · 2026-09-03 · Schedule owner: [PLACEHOLDER — DPO], with the System owner for implementation.
> Companion documents: [`ROPA.md`](ROPA.md) (what is held), [`DATA-CLASSIFICATION.md`](DATA-CLASSIFICATION.md) (how it is handled), [`INCIDENT-RESPONSE.md`](INCIDENT-RESPONSE.md) (legal hold).

---

## 1. Principles

1. **Two duties pull in opposite directions and both apply.** The Personal Data Protection Law
   requires a controller to destroy personal data once the purpose of its collection has ended,
   unless a legal requirement obliges the controller to keep it, in which case it is kept for that
   period only [VERIFY ARTICLE — destruction duty and its exceptions]. Separately, Saudi
   health-care regulation obliges providers to keep medical records for a defined period
   [NEEDS LEGAL CONFIRMATION — the applicable medical-records regulation and the period it sets;
   this document deliberately asserts **no number**]. Section 6 explains how the two interact.
2. **Nothing in this schedule is implemented as automatic destruction of clinical data today.**
   Clinical rows are never purged; the only destruction mechanism that exists is the operator-run
   `audit:prune` for audit rows, and log rotation. Every "destroy after" cell below is a
   **proposal** until the DPO confirms it and engineering builds the mechanism.
3. **Legal hold overrides everything.** When an incident, complaint, claim or regulator request is
   open, the DPO places a hold and no destruction step in this schedule runs for the affected data
   (INCIDENT-RESPONSE.md §6).
4. **Destruction must be real, recorded and verifiable.** "Deleted from the table" is not
   destruction if a backup, an export, a snapshot or a printed sheet survives. Section 5 defines
   the method per artifact.
5. **Every period in this schedule has to be applied twice, in two jurisdictions.** The unit runs
   **two systems** (CONFIRMED-FACTS B1–B3): the **legacy PHP app on `dmc-im.com`, SiteGround,
   United States** — the daily system, in its original un-hardened build — and the **Laravel app on
   OCI Riyadh**, live in parallel with a full copy of the same data and the source of truth only
   for the consultation ledger. Every mechanism named below (`audit:prune`, the encrypted off-box
   backup, log rotation, soft deletes) exists **only on the Laravel side**. On the legacy side there
   is no destruction mechanism, no retention configuration and no verification. §2.0 lists the
   copies; nothing is "destroyed" until it is gone from both.
6. **The hospital sets the periods; the processor executes them.** DMC is the controller; the
   operator company holds the code, the hosting and the data and is the only party that can run any
   destruction step. Until the controller–processor contract exists (CONFIRMED-FACTS A5), none of
   these obligations is contractually binding on it [PLACEHOLDER — contract reference once signed].

---

## 2. Retention and destruction schedule

Legend — **Today:** what actually happens now. **Proposed:** the period this draft recommends,
pending the tags. **Mechanism:** the existing command/config or the GAP to build. **System:**
*legacy daily* (SiteGround, US) · *Laravel* (OCI Riyadh, in-Kingdom) · *both*.

### 2.0 Where the copies are (what any period has to reach)

| # | Copy | System / location | Controlled by | Destruction mechanism today |
|---|---|---|---|---|
| C1 | The **daily** clinical database | Legacy PHP app, **SiteGround shared hosting, United States** | Operator company (account holder); SiteGround as sub-processor | **None.** No prune, no rotation, no documented deletion path [PLACEHOLDER — processor to state what deletion is possible on the account] |
| C2 | The **parallel** clinical database | Laravel app, **OCI `me-riyadh-1`, in-Kingdom** | Operator company; Oracle as sub-processor | Soft deletes and an admin hard delete only; no scheduled prune (§2.1) |
| C3 | Audit log + WORM archive | Laravel app + in-Kingdom OCI bucket | Operator company; Oracle | `audit:prune`, operator-run, never scheduled (§2.2) |
| C4 | Encrypted database backups | In-Kingdom OCI bucket `dmc-db-backups` + `/var/backups/dmc/` on the host | Operator company (holds the keys and `APP_KEY`) | Bucket lifecycle (§2.3) |
| C5 | Whatever SiteGround retains of the daily database | **United States**, inside SiteGround's platform | SiteGround | Unknown [VERIFY — SiteGround backup scope, retention and deletion on request] |
| C6 | Migration dumps and exports | Operator workstations, the OCI host under `/home/ubuntu/migrate/dmc/` (CONFIRMED-FACTS D1) | Operator company | Manual, uninventoried **GAP** (§2.3) |
| C7 | The `dmc-im.com` mailbox | SiteGround, **United States** | Operator company | Mailbox policy [VERIFY] (§2.6) |

A destruction step that reaches only C2 has destroyed nothing while C1 and C5 hold the same rows.

### 2.1 Patient clinical records — **SENSITIVE**

The hub is a **supplementary part of the medical record** (CONFIRMED-FACTS C1), so the clinical rows
follow the medical-record period once counsel obtains it. The governing instrument has been
identified — the Private Health Institutions Law Executive Regulations, whose Art. 3/3 delegates the
figure to a Ministry annex [PROPOSED] — but **the annex has not been obtained, so no number is
asserted anywhere in this document** [NEEDS LEGAL CONFIRMATION].

| Data | System / copy | Trigger (start of clock) | Retention period | Today | Mechanism / GAP | Destruction method |
|---|---|---|---|---|---|---|
| `patients`, `admissions`, `admission_diagnoses`, `consultations`, `consultation_followups`, `handovers`, `handover_revisions`, `handover_signatures`, patient-bearing `notifications` rows | **Laravel (C2)** — the parallel copy; the consultation ledger is the only part of it that is the source of truth | End of the last episode of care for that patient (last `discharge_date`), or the patient's death [VERIFY which trigger the regulation uses; special rules for minors or deceased patients] | **Set by the applicable Saudi medical-records regulation — [NEEDS LEGAL CONFIRMATION].** Do not assume a number from another jurisdiction. The period may differ between the primary medical record and a supplementary operational system like this hub [VERIFY whether the hub is part of the medical record]. | Indefinite. Rows are soft-deleted at most (`deleted_at`); no purge exists. Data from the legacy system going back years was imported in full and is re-imported on each refresh (ROPA A10). | GAP — a scheduled `records:prune` (dry-run by default, `--confirm` gated, audited, refusing to run when the period is unset — mirroring `audit:prune`), **or** an anonymisation routine that strips identifiers but keeps statistics. Decision needed on delete-vs-anonymise [PLACEHOLDER — DPO + clinical owner]. | SQL delete of the patient graph (FKs cascade from `patients` → `admissions` → diagnoses/handovers; consultations `nullOnDelete`), then §5.1 caveats on InnoDB and backups. |
| The **same clinical records** in the legacy daily database | **Legacy daily (C1)** — SiteGround, **United States**; this is the copy staff write to | As above | As above — the period applies to this copy first, because it is the operative record until cutover | Indefinite. No prune, no soft-delete convention, no destruction path documented; the build is the original un-hardened one | GAP — **the highest-priority retention gap.** Either the cutover retires this copy (CONFIRMED-FACTS D2) or a destruction path must be built on the SiteGround account [PLACEHOLDER — processor; cutover date] | Whatever SiteGround permits, plus destruction of C5; certificate required (§5.6) |
| Soft-deleted patient / admission / consultation rows (`deleted_at` set) | Laravel (C2) | `deleted_at` | Same as above (a soft delete is a correction, not a retention event) | Kept indefinitely | Included in the same prune | As above |
| Derived TB classification | Both | Follows `admission_diagnoses` | — | — | — | — |

### 2.2 Audit log

**Laravel (C3) only.** The legacy daily system produces no comparable trail, so for the activity
staff actually perform today there is nothing to retain, prune or verify — the retention question
there is moot until cutover, and the accountability gap is recorded in ROPA A6 and DPIA R13.

| Data | Trigger | Retention | Today | Mechanism | Destruction method |
|---|---|---|---|---|---|
| `audit_log` rows (local database) | `created_at` | **Six years** (`settings.audit_retention_years`, default six; zero refuses to prune) [VERIFY the six-year figure against any sector requirement for access-log retention] | Nothing is ever deleted: `audit:prune` exists but is **never scheduled** and deletes only with `--confirm` ([`../../app/Console/Commands/AuditPrune.php`](../../app/Console/Commands/AuditPrune.php), [`../../routes/console.php`](../../routes/console.php)). | **Recommendation:** (1) schedule a **monthly dry-run** (`audit:prune` without `--confirm`) whose output is e-mailed/notified so the eligible count is visible; (2) an operator runs `audit:prune --confirm` **quarterly** after checking no legal hold applies; keep it unscheduled for the actual delete. Both runs are self-audited (`audit.pruned`). | Query-builder delete (bypasses the ORM append-only guard by design). A row is eligible **only** if its id is at or below `settings.audit_shipped_through_id` — i.e. it already exists in the WORM archive — so local pruning never destroys the only copy. |
| Audit archive NDJSON objects (WORM bucket) | Object creation | **Seven years** (bucket retention lock) | Immutable; nothing can delete them during the lock | Bucket lifecycle rule expires objects after the lock [VERIFY configured]. | Object expiry by the storage service; confirm no replica/versioning keeps copies. |
| **Why seven off-box vs six local** | | The archive must outlive the local rows by a margin so that (a) a local prune, a restore from an old backup, or a host loss can never leave a window with no copy; (b) an investigation opened near the end of the local period still has the source; (c) the extra year absorbs clock drift between "created" and "shipped" and any legal-hold extension. If counsel confirms a longer statutory period for access logs, **raise both** [VERIFY]. | | | |
| Pre-chain audit rows (NULL `row_hash`) | — | As above | Present until backfilled [VERIFY] | Backfill, then normal schedule | As above |
| `setting_changes` | `created_at` | Align with the audit log (six years) | Indefinite | GAP — include in the audit prune or a sibling command | SQL delete |
| Non-patient `notifications` (`audit.integrity_failure`, `security.failed_logins`, consultation/handover bell rows without identifiers) | `resolved_at` / `read_at` | **Proposed one year** after resolution [PLACEHOLDER] | Indefinite; rows are never deleted by the UI (by design for the reminder trail) | GAP — scheduled purge; keep `handover.incomplete` rows for the clinical-audit trail per HANDOVER-COMPLIANCE §4 (align those with the audit-log period) | SQL delete |

### 2.3 Backups and copies of the database

| Data | System / copy | Trigger | Retention | Today | Mechanism | Destruction method |
|---|---|---|---|---|---|---|
| Automated encrypted off-box backups | **Laravel (C4)** — in-Kingdom OCI bucket `dmc-db-backups` | Backup creation | **Ninety days** — a **placeholder pending legal**, not a sourced figure: the research found **no** backup-retention minimum in the instruments reviewed [PROPOSED — NOT FOUND, see PROPOSED-CITATIONS.md] [VERIFY sufficient against the clinical-record and audit periods — a backup only needs to cover recovery, not retention, because the live database and the WORM archive are the retention copies] | **Live**: nightly encrypted dump off-box, encrypted local copies in `/var/backups/dmc/` kept two days, a daily `backup:verify` staleness alert and a logged restore drill | Lifecycle rule on the backup bucket; backup-stale alert | Object expiry **plus** key management: destroy the backup encryption key and verify the object and all its versions are gone. Note separately that a dump restored without the `APP_KEY` in force when it was taken is **incomplete** — the encrypted narrative columns will not decrypt — so key custody is part of both recovery and destruction |
| Whatever SiteGround retains of the **daily** database | **Legacy (C5)** — SiteGround, **United States** | Provider policy | Unknown | Unknown and unverified — no DPA, no stated retention, no deletion-on-request commitment on file | GAP — obtain the provider's backup scope, retention and deletion terms [VERIFY]; cover it in the transfer safeguard | Provider-side deletion request + written confirmation (§5.4) |
| Manual pre-deploy dumps `~/pre-deploy-*.sql.gz` | Laravel host (C6) | Creation (DEPLOY-LARAVEL.md §7) | **Proposed: delete seven days after the deploy is verified**, or immediately after the next automated backup succeeds [PLACEHOLDER] | Accumulate on the host, unencrypted, no rule | GAP — encrypt at creation, ship off-box, cron delete | `shred -u` on the host (or secure delete appropriate to the filesystem [VERIFY]); confirm not in any home-directory backup |
| Incident dumps / OCI volume snapshots named `INC-…` | Laravel (C6) | Incident closure | For the duration of the incident **plus the legal hold**, then thirty days [PLACEHOLDER] | — | DPO releases the hold in writing | Delete the OCI backup/clone; verify no dependent clones remain |
| Legacy database dumps and exports | **C6** — operator workstations and the OCI host under `/home/ubuntu/migrate/dmc/` | Migration sign-off | **Destroy after `legacy:import` is signed off and the consultation cutover gate is ON** (DEPLOY-LARAVEL.md §3) [PLACEHOLDER — date] | CONFIRMED-FACTS D1 records the known copies: seven legacy `.sql` dumps plus export/reconciliation files on the owner's workstation, and a **plaintext** `dmc_demo.sql.gz`, a pre-refresh dump and leftover key files on the OCI host. A full inventory and destruction schedule is still owed **GAP**. The historical `Demo.sql` is not in the working tree and not in git history as of this draft [VERIFY local copies destroyed] | Manual inventory + destruction certificate | Secure delete every copy; if a dump is ever found in git history, purge it (history rewrite + force-push + collaborator re-clone) |
| The legacy application's own database | **Legacy daily (C1)** — SiteGround, **United States**; still the live system of record | Cutover | Keep until cutover, then destroy or archive per the clinical-record period for the migrated rows [NEEDS LEGAL CONFIRMATION] | **Live and in daily use** — not a dormant copy; the plan is to replace the dmc-im.com code with the Laravel app at cutover (CONFIRMED-FACTS D2), at which point this database must be dealt with explicitly [PLACEHOLDER — cutover date] | — | Drop database; destroy C5 and every provider-side backup; obtain written confirmation from SiteGround (§5.4) |

### 2.4 Sessions, tokens and authentication state

**Laravel (C2) only.** The legacy daily system keeps its own sessions and password-reset state on
SiteGround (**US**) under the original build's settings — its session cookie has neither `Secure`
nor `HttpOnly` (CONFIRMED-FACTS B2) — and none of the rows below describes it [PLACEHOLDER —
legacy session/token retention, processor to state].

| Data | Trigger | Retention | Today | Mechanism | Destruction method |
|---|---|---|---|---|---|
| Sessions (`storage/framework/sessions/*` files; `sessions` table if the driver is `database`) | Last activity | **Two hours** lifetime (`SESSION_LIFETIME=120`, `config/session.php`); forced logout after **thirty minutes idle** (`settings.idle_timeout_minutes`, audited as `session.timeout`); absolute timeout `settings.abs_timeout_minutes` (default zero = off) | Expired files are removed by the framework's probabilistic garbage collection (`session.lottery`) — so stale files can linger | Recommendation: nightly cron `find storage/framework/sessions -type f -mmin +120 -delete` [VERIFY path on the live host]; or switch to the `database` driver and let the table be pruned | File delete / row delete |
| `trusted_devices` | `expires_at` (fixed window `settings.mfa_trusted_device_hours`, default twenty-four hours; never extended) or `revoked_at` | **Proposed: delete ninety days after expiry/revocation** [PLACEHOLDER] (the row is kept briefly as an audit trail of device trust; `mfa.device_trusted`/`mfa.device_revoked` audit rows remain the permanent record) | Rows accumulate | GAP — scheduled purge | SQL delete |
| `pending_registrations` | `expires_at` (**thirty minutes** after creation) | Delete at expiry | The controller treats an expired row as gone; the migration says a scheduled sweep may prune it — **no sweep is present in `routes/console.php`** [VERIFY] | GAP — schedule a daily `DELETE … WHERE expires_at < NOW()`; these rows hold a **plaintext recovery-code set** until account creation, so the sweep is a security control, not housekeeping | SQL delete |
| `password_reset_tokens` | `created_at` | Token validity per `config/auth.php` (`passwords.users.expire` = sixty minutes) | Rows persist after expiry until overwritten | Recommendation: schedule the framework's `auth:clear-resets` daily | SQL delete |
| `users.remember_token` | — | Never issued to MFA users (all users are MFA) | Column mostly NULL | — | — |
| `users.mfa_recovery_codes`, `mfa_secret` | Account lifetime | With the account | — | Admin **Reset MFA** clears them | SQL update to NULL |

### 2.5 Staff accounts

**Two unsynchronised account sets.** Staff have an account in the legacy daily system (SiteGround,
**US**, original build with admin self-registration) *and* an account in the Laravel app (OCI
Riyadh). Every step below therefore has to be performed **in both systems**, and a departure handled
in one leaves the other open [PLACEHOLDER — joiner/leaver procedure covering both systems].

| Data | Trigger | Retention | Today | Mechanism | Destruction method |
|---|---|---|---|---|---|
| Active account (each system separately) | — | While employed / engaged | Two account sets exist and are administered separately; a change in one does not reach the other | — | — |
| Account after departure | HR leaving date [PLACEHOLDER — HR feed] | **Immediately:** set `active = 0` (Control → Users, audited `user.update`), revoke trusted devices, clear sessions. **Then:** soft-delete (`user.delete`, `deleted_at`) after **[PLACEHOLDER — proposed ninety days]**. **Identity fields (`name`, `full_name`) must be kept** for as long as any clinical or audit row attributes an action to the user (FKs are `nullOnDelete`; `actor_name` is denormalised into `audit_log` precisely so attribution survives). **Contact fields (`email`, `username`) may be scrubbed** after the soft-delete period [NEEDS LEGAL CONFIRMATION — employment-record retention rules] | Deactivation is manual; no scheduled review | GAP — quarterly access review; departure checklist | SQL update (scrub) rather than row delete; never hard-delete a user who appears in `audit_log.actor_id`, `admissions.*_by`, `handover_revisions.author_id`, etc. |
| Applicants who never completed registration | `pending_registrations.expires_at` | Thirty minutes | See §2.4 | See §2.4 | SQL delete |
| `report_recipients` | Removal by an admin (`report_recipient.remove`) | Until removed; review quarterly | Manual | — | Row delete (the audit row records the address) |

### 2.6 Logs, telemetry and outputs

Unless a row says otherwise these are **Laravel (C2/C6)** artifacts. The legacy daily system's own
logs and exports on SiteGround (**US**) are outside every rule below and have never been inventoried
[VERIFY — legacy log and export inventory]. Note also that exports and printed sheets exist in
**both** systems: since 2026-09-03 **every** Laravel export and report PDF leaves an audit row and
carries a `SECRET-`/`CONFIDENTIAL-` filename prefix (CONFIRMED-FACTS C12/C13), while the legacy
daily system's exports leave no trace and carry no label at all.

| Data | Trigger | Retention | Today | Mechanism | Destruction method |
|---|---|---|---|---|---|
| Application log `storage/logs/laravel-YYYY-MM-DD.log` | File date | `daily` channel with `LOG_DAILY_DAYS` (**default fourteen days**); **proposed ninety days** in production so a slow-burn incident is still investigable [PLACEHOLDER; VERIFY no sector minimum for security logs] | Depends on the live `.env` — DEPLOY-LARAVEL.md §2 recommends `daily`/`warning`; the stock `single` channel grows unbounded [VERIFY live value] | Config only | File deletion by the logger; if PHI is found in a log, treat the file as Secret and shred |
| Container stdout/stderr logs (Docker json-file) | — | Docker default is unbounded [VERIFY `max-size`/`max-file` on the live daemon] | — | GAP — set a log-driver rotation in Coolify/daemon config | Docker rotation |
| CSP violation reports | — | They are only log lines (no table, no file of their own) → follow the application log | Log-only by design (`CspReportController`) | — | With the log |
| `security.failed_logins`, `audit.integrity_failure` notifications | — | See §2.2 non-patient notifications | — | — | — |
| Registry exports (CSV/XLSX) on staff devices — **Laravel** | Download | **Proposed: delete within thirty days or on task completion, whichever is first; never retained on personal devices** [PLACEHOLDER] | No rule; server keeps no copy | Policy + training; the `registry.export*` audit row is the register of what exists, and the file name carries a `SECRET-` prefix | Secure delete on the device; empty recycle bin; check cloud-sync folders |
| Audit-log exports, statistics exports, report PDFs — **Laravel** | Download | As above [PLACEHOLDER] | No rule; server keeps no copy | Policy + training; **audited since 2026-09-03** (`audit.export.*`, `statistics.export.*`, `report.pdf.*` — C12), so the audit log is the register here too; `SECRET-`/`CONFIDENTIAL-` filename prefix and a classification footer on every PDF (C13) | As above |
| Exports and printed output from the **legacy daily system** | Download / printing | As above [PLACEHOLDER] | No rule, **no audit row and no classification label** — nothing records that an export happened **GAP** | Policy only, until cutover retires this system | As above; there is no register to reconcile against |
| Monthly report PDF (aggregate) | Sent on the first of the month at 06:00 | At recipients' mailboxes per the hospital e-mail retention policy [VERIFY]; plus whatever **SiteGround** retains in the `dmc-im.com` mailbox and its logs, in the **United States** [VERIFY relay retention] | Template confirmed aggregate-only — no MRN, no name (CONFIRMED-FACTS C6) | Mail policy | Mailbox deletion; provider-side deletion per §5.4 |
| Queued job payloads (`jobs` table) carrying the PDF | Job completion | `.env.example` sets `QUEUE_CONNECTION=sync`, so the job runs inline and nothing persists in `jobs` [VERIFY live value]; if a real queue driver is enabled, payloads persist until success and failures land in `failed_jobs` | — | `queue:prune-failed` if a queue driver is enabled | SQL delete |
| Printed handover / service sheets | Printing | **End of shift or when superseded, whichever is first** [PLACEHOLDER — clinical rule] | No rule | Ward procedure | Cross-cut shredding in a confidential-waste bin |

### 2.7 Configuration and code

| Data | Retention | Notes |
|---|---|---|
| `settings` row, `.env` (both systems) | Life of the system; secrets rotated per INCIDENT-RESPONSE.md §7.4 | Old secret values must not be kept anywhere after rotation. The legacy build stores its secrets in plaintext configuration (CONFIRMED-FACTS B2) |
| Source code (GitHub) | Indefinite | Contains no personal data by design; keep the history clean (no `Demo.sql` in history as of this draft; legacy credentials were rotated after exposure). The repository is **public** by a time-boxed owner decision and is to be made private before go-live (CONFIRMED-FACTS D3) |
| Reference tables (`icd10`, `countries`, …) | Indefinite | Public data |
| This documentation | Life of the system + the audit period | Versions kept in git |

---

## 3. Legal holds

| Step | Who | How |
|---|---|---|
| Place a hold | DPO (once appointed; the owner interim) | Written note in the incident/complaint file naming the data (patients, date range, users) and the reason, and naming **which copies in §2.0 it covers** — a hold that names only the Laravel copy leaves the daily record unprotected |
| Suspend destruction | System owner (processor side) | Do not run `audit:prune --confirm` or any future records prune; do not delete `INC-…` snapshots or dumps; extend log retention for the window; **freeze any legacy-side deletion or cutover step** for the affected rows |
| Release | DPO | Written release; destruction resumes at the next scheduled run |

Because the operator company, not the hospital, executes every one of these steps, a hold is only as
good as the instruction the controller can give it — another reason the Art. 17 contract is the top
action (CONFIRMED-FACTS A5).

---

## 4. Implementation checklist (engineering / ops)

| # | Item | Status | Owner | Date |
|---|---|---|---|---|
| 1 | Obtain the clinical-record retention period and trigger from legal | [NEEDS LEGAL CONFIRMATION] | [PLACEHOLDER — DPO] | [PLACEHOLDER] |
| 2 | Decide delete-vs-anonymise for expired clinical rows | Open | [PLACEHOLDER — DPO + clinical owner] | [PLACEHOLDER] |
| 3 | Build `records:prune` (dry-run default, `--confirm`, audited, refuses when the period is unset) or the anonymiser | GAP | [PLACEHOLDER] | [PLACEHOLDER] |
| 4 | Schedule `audit:prune` **dry-run** monthly with notification; document the quarterly operator run | GAP | [PLACEHOLDER] | [PLACEHOLDER] |
| 5 | Backfill pre-chain audit hashes | [VERIFY] | [PLACEHOLDER] | [PLACEHOLDER] |
| 6 | Confirm WORM bucket lifecycle expires objects after the seven-year lock | [VERIFY] | [PLACEHOLDER] | [PLACEHOLDER] |
| 7 | Backups: ninety-day lifecycle rule; encrypt; stale alert; restore test | **Done for the Laravel copy** (nightly encrypted off-box dump, `backup:verify`, logged restore drill); the ninety-day figure remains a legal placeholder | [PLACEHOLDER] | [PLACEHOLDER] |
| 8 | Encrypt-at-creation + off-box + seven-day delete for manual pre-deploy dumps | GAP | [PLACEHOLDER] | [PLACEHOLDER] |
| 9 | Inventory and destroy legacy dumps (CONFIRMED-FACTS D1, incl. the plaintext dump and key files on the OCI host); verify git-history purge | GAP | [PLACEHOLDER] | [PLACEHOLDER] |
| 9a | **Decide and record what happens to the legacy daily database (C1) and to SiteGround-held backups (C5)** — cutover date, destruction path, provider deletion confirmation | GAP — highest priority in this schedule | [PLACEHOLDER — owner + DPO] | [PLACEHOLDER] |
| 9b | Obtain SiteGround's backup scope, retention and deletion terms; cover them in the transfer safeguard | GAP | [PLACEHOLDER — IT lead] | [PLACEHOLDER] |
| 9c | **Sign the controller–processor contract** so these periods bind the operator company (IR Art. 17 minimum content [PROPOSED]) | GAP — top action (A0) | [PLACEHOLDER — DPO + owner] | [PLACEHOLDER] |
| 10 | Nightly sweeps: expired sessions (file driver), `pending_registrations`, `trusted_devices` (after ninety days), `auth:clear-resets` | GAP | [PLACEHOLDER] | [PLACEHOLDER] |
| 11 | Set `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS` per the agreed period; Docker log rotation | [VERIFY live values] | [PLACEHOLDER] | [PLACEHOLDER] |
| 12 | Departure checklist + quarterly access review, **covering both systems' account sets** | GAP | [PLACEHOLDER — HR / System owner] | [PLACEHOLDER] |
| 13 | Staff rule for exports and printed sheets; add classification labels to exports | **Labels done on the Laravel side 2026-09-03** (filename prefixes + PDF/print footers, C13); the **staff handling rule** and the legacy system's unlabelled exports remain GAP | [PLACEHOLDER] | [PLACEHOLDER] |
| 14 | Inventory the legacy schema, logs and exports on SiteGround so this schedule can be applied to C1/C5 at all | GAP | [PLACEHOLDER — processor] | [PLACEHOLDER] |

---

## 5. Destruction methods — what "destroyed" means per medium

### 5.1 Database rows (MySQL / InnoDB)

- A `DELETE` removes the row logically; the bytes may persist in the tablespace until the page is
  reused, and in the binary log / redo log if enabled [VERIFY binlog configuration]. For sensitive
  data this is acceptable **only** in combination with encrypted storage whose key is controlled and
  with backups expiring on schedule. In the Laravel copy encryption at rest is **partial** — the
  four narrative columns under `APP_KEY`, everything else relying on the cloud provider's default
  volume encryption, whose keys the provider holds; on the legacy daily system neither condition
  holds.
- Soft delete (`deleted_at`) is **not** destruction.
- Record every prune in the audit log (`audit.pruned` already does this for audit rows; the future
  records prune must do the same, with counts, not identifiers).

### 5.2 Files on the host

Use a secure-delete tool appropriate to the filesystem and volume type (on cloud block storage,
overwriting is not guaranteed to reach the physical medium — rely on volume encryption + key
destruction where available [VERIFY]). Confirm no copies exist in home-directory backups, editor
swap files, or `/tmp`.

### 5.3 Cloud objects and snapshots

Delete the object **and** any versions; delete OCI volume backups/clones and check for dependent
resources; where a customer-managed key protects the object, key destruction is the strongest
form of erasure [VERIFY the key-management setup for the backup bucket and the audit archive].

### 5.4 Processor-held copies

Ask the processor to confirm deletion per the DPA and keep the confirmation [PLACEHOLDER — DPA
clauses]. Here that means, in order: the **operator company** (primary processor — no contract yet,
so there is nothing to invoke), then its sub-processors **SiteGround** (the legacy host, its
backups and the `dmc-im.com` mailbox), **Oracle/OCI** (compute, backups, audit archive) and
**Cloudflare** (edge logs). None of these has a signed DPA on file [PLACEHOLDER — obtain and file].

### 5.5 Paper

Cross-cut shredding into confidential waste; never ordinary recycling.

### 5.6 Record of destruction

For clinical-record and backup destruction keep a **destruction log**: what (category and count,
never identifiers), when, by whom, method, and the approval reference. This is itself
Confidential and retained for the audit-log period.

---

## 6. How the "destroy when the purpose ends" duty interacts with clinical retention

1. **While an episode is active or recently closed**, the purpose (patient-flow management) is
   live: retention is plainly necessary.
2. **After the episode**, the operational purpose ends, but two things keep the data lawful to
   hold: the health-care regulation's record-keeping period [NEEDS LEGAL CONFIRMATION] (a legal
   obligation — the exception the law recognises [VERIFY ARTICLE]), and statistical/quality
   purposes, which the law may permit on anonymised or minimally identifiable data
   [VERIFY ARTICLE — statistics/research provision and whether it requires anonymisation].
3. **When the regulatory period lapses**, the destruction duty resumes: the identifiable record
   must be destroyed or irreversibly anonymised. The hub's statistics (LOS, readmissions,
   mortality) do not need identifiers, so **anonymisation** is the likely right outcome — keep
   the `admissions` shape with `patient_id` severed and `patients` rows removed, strip free text,
   and keep the audit rows (which then reference an MRN that no longer resolves to a person — the
   DPO should confirm whether that residual identifier must also be hashed [VERIFY]).
4. **Backups are not a retention copy.** They exist to recover; they expire on their own short
   schedule regardless of the clinical period. The copies that carry the retention obligation are
   the **live clinical databases — today that means the legacy daily database (C1) as the operative
   record and the Laravel parallel copy (C2), and after cutover only the latter** — together with
   the WORM audit archive.
5. **Audit rows outlive the records they describe** (six/seven years from the action, not from
   the episode). This is deliberate: accountability for *who accessed* a record has its own purpose
   and period [VERIFY any sector rule for access-log retention in health-care].
6. **Staff data** follows employment-record rules, with the attribution carve-out in §2.5:
   the name stays as long as the clinical/audit rows that cite it; contact details go.
7. **Conflicts** (a legal hold vs an expiry, a data-subject destruction request vs the clinical
   period) are decided by the DPO in writing and recorded in the destruction log.
8. **The parallel period is itself a retention question.** Running two systems means holding two
   complete copies of the same sensitive dataset (ROPA A10), one of them outside the Kingdom on an
   un-hardened build. Nothing in the law makes a duplicate unlawful in itself, but the duplicate has
   no independent purpose once cutover happens, so the parallel window should be **as short as the
   project allows** and should end with a documented destruction of the legacy copy and every
   migration dump [PLACEHOLDER — cutover date; destruction certificate].

---

## 7. Review

Review this schedule when legal confirms the clinical period; **at cutover, when the legacy copy is
retired and this schedule stops needing to be applied twice**; after any incident; and at least
annually. Next review: [PLACEHOLDER].

## 8. Change log

| Date | Version | Change | Author |
|---|---|---|---|
| 2026-09-03 | 0.1 | Initial draft | [PLACEHOLDER] |
| 2026-09-03 | 0.1 | Reworked to the confirmed framing: two-system principle and controller/processor split (§1.5–1.6); new §2.0 copy inventory C1–C7; legacy daily database and SiteGround-held backups added as rows in §2.1 and §2.3; automated encrypted backups corrected from "being built" to live with the ninety-day figure kept as a legal placeholder; §2.2/§2.4/§2.6 scoped to the Laravel copy; dual account sets in §2.5; legal holds, destruction methods and checklist items 9a–9c/14 extended to the legacy copy and the processor contract; §6.8 on the parallel period | [PLACEHOLDER] |
| 2026-09-03 | 0.1 | Reconciled §2.6 and checklist item 13 with CONFIRMED-FACTS C12/C13 as re-verified: every Laravel export and report PDF is audited and carries a `SECRET-`/`CONFIDENTIAL-` filename prefix and classification footer, while legacy exports remain unaudited and unlabelled (new row); §6.4 now names which live database carries the retention obligation | [PLACEHOLDER] |
