# Compliance drafts — open items to fill

> **STATUS UPDATE 2026-09-03.** Progress since this catalogue was generated:
> - **Cross-cutting facts confirmed with the owner** and applied to the drafts — see
>   [`CONFIRMED-FACTS.md`](CONFIRMED-FACTS.md) (controller, primary processor = the operator company,
>   OCI/Cloudflare/SiteGround processors, DPO status, dates/review, request channel, medical-record
>   status, PHI-copy inventory, repo-public decision, classification evidence fills).
> - **Legal / framework markers** now have proposed, source-cited answers in
>   [`PROPOSED-CITATIONS.md`](PROPOSED-CITATIONS.md) (all `[PROPOSED]`, for counsel to verify).
> - **Auditor evidence map** in [`EVIDENCE-PACK.md`](EVIDENCE-PACK.md).
> - **Rescanned 2026-09-03 (later the same day):** `ROPA.md`, `DATA-RETENTION.md` and `DPIA.md` were
>   reworked to the confirmed **processor / legacy-daily** framing (DMC as controller, the operator
>   company as primary processor, the legacy `dmc-im.com` app as the daily system and the Laravel app
>   as a parallel copy). **Their three sections below are regenerated against the current files**, so
>   their line numbers are live; the other six sections remain the original scan and their line
>   numbers still predate the confirmed-inputs callout added to every draft (roughly a two-line
>   shift). The net marker count rose because the rework states more open questions than it closes:
>   nothing was removed to make a number look better.
>
> The six untouched sections below are the **original, unmodified** line-by-line list — use this as
> the master checklist; a marker is closed when its value is filled in the draft AND recorded in
> `CONFIRMED-FACTS.md` or confirmed by counsel via `PROPOSED-CITATIONS.md`. Remaining truly-open work:
> the bracketed **[NAME]/[DATE]/contact placeholders** the hospital still owes, and the legal
> **DECISION** rows reserved for counsel/DPO/clinical governance.

Every bracketed marker below must be resolved by the hospital's legal / data-protection officer and clinical governance before these documents are finalised. Generated 2026-09-03; ROPA / DATA-RETENTION / DPIA sections regenerated 2026-09-03 after the processor-framing rework; regenerate after edits.

**Marker meanings:** `[VERIFY …]` = confirm a fact or the exact legal article/period against the source. `[NEEDS LEGAL CONFIRMATION]` = a lawyer must decide (usually a retention period). `[PLACEHOLDER]` = fill in a name / contact / entity. `[SET THIS]` / `[DATE]` = a concrete value or date. Arabic markers (`[تحقّق…]` etc.) mirror the English ones in the Arabic privacy notice. Those five kinds are what this catalogue counts; `[PROPOSED]` tags (counsel-facing citations) are tracked in `PROPOSED-CITATIONS.md` instead, and `[COMPANY LEGAL NAME]` is a `[PLACEHOLDER]` in prose form — both are deliberately outside the counts below.


## Summary

| File | Open items | Δ since the original scan |
|---|---:|---|
| DATA-CLASSIFICATION.md | 56 | — |
| DATA-RETENTION.md | 96 | +21 (rework) |
| DPA-AND-TRANSFERS.md | 51 | — |
| DPIA.md | 125 | +29 (rework) |
| DPO.md | 22 | — |
| INCIDENT-RESPONSE.md | 72 | — |
| PRIVACY-NOTICE.ar.md | 24 | — |
| PRIVACY-NOTICE.en.md | 24 | — |
| ROPA.md | 134 | +25 (rework) |
| **Total** | **604** | **+75** |

## `DATA-CLASSIFICATION.md` — 56 items

| Line | Marker | Context |
|---:|---|---|
| 5 | [PLACEHOLDER — Information-security lead] | > Version 0.1 · 2026-09-03 · Scheme owner: [PLACEHOLDER — Information-security lead], with the DPO. |
| 27 | [VERIFY control refs] | control identifiers by the security lead [VERIFY control refs]. |
| 34 | [VERIFY] | **Secret** \| All **patient-identifiable health data** (identity + any clinical fact), and **secrets that unloc… |
| 34 | [VERIFY — whether the NDMO/NCA mapping places sensitive personal data at Secret or Confidential; this draft proposes Secret.] | **Secret** \| All **patient-identifiable health data** (identity + any clinical fact), and **secrets that unloc… |
| 84 | [VERIFY queue driver] | `cache`, `jobs` \| transient; the queued monthly-report job carries the PDF bytes (Confidential while aggregate… |
| 96 | [VERIFY] | **MySQL data volume** (Docker) \| OCI block/boot volume on the instance \| **Secret** \| Everything \| At-rest enc… |
| 97 | [PLACEHOLDER — in-Kingdom bucket] | **Automated backups** *(being built)* \| [PLACEHOLDER — in-Kingdom bucket] \| **Secret** \| Everything \| Encrypte… |
| 99 | [VERIFY inventory of local copies] | **Legacy database dumps** (`dmc_prod` exports used for `legacy:import`; the historical `Demo.sql` referenced i… |
| 104 | [VERIFY sample] | **Monthly report PDF** \| E-mailed to `report_recipients` via the US relay; queued in `jobs` \| **Confidential**… |
| 105 | [PLACEHOLDER — clinical rule] | **Printed handover / service sheets** \| Paper on the ward \| **Secret** \| Patient names, MRNs, narrative, code … |
| 110 | [VERIFY] | **Source code** \| GitHub (private) + host checkout \| **Confidential** \| Application logic, security controls, … |
| 112 | [VERIFY cache headers] | **Browser** \| Staff devices \| Page props are **Secret** while rendered \| Rendered PHI \| Idle timeout thirty mi… |
| 112 | [PLACEHOLDER] | **Browser** \| Staff devices \| Page props are **Secret** while rendered \| Rendered PHI \| Idle timeout thirty mi… |
| 121 | [VERIFY] | "DCC ref." cells are for the security lead to fill with the exact control identifiers [VERIFY]. |
| 127 | [VERIFY] | Labelling \| Every export file name, e-mail subject, printed sheet and document carries **SECRET — Patient data… |
| 128 | [VERIFY] | Access \| Named individuals with a clinical or operational need; MFA always; admin-only for bulk views and expo… |
| 129 | [VERIFY] | Storage \| Encrypted at rest; keys separate from data; in-Kingdom only \| **GAP for the MySQL volume (planned)**… |
| 130 | [VERIFY] | Transit \| TLS 1.2+ everywhere; no plain-text protocols; no personal e-mail, chat or removable media \| Cloudfla… |
| 130 | [VERIFY] | Transit \| TLS 1.2+ everywhere; no plain-text protocols; no personal e-mail, chat or removable media \| Cloudfla… |
| 131 | [VERIFY] | Sharing outside the hospital \| Prohibited unless the DPO approves and a contract/legal basis exists; minimum n… |
| 132 | [VERIFY] | Copies / exports \| Only when needed for a defined task; recorded (automatically or manually); deleted when the… |
| 133 | [PLACEHOLDER] | Printing \| Only from the designated handover/service sheet; collected immediately; never left unattended; shre… |
| 133 | [VERIFY] | Printing \| Only from the designated handover/service sheet; collected immediately; never left unattended; shre… |
| 134 | [VERIFY] | Logging of access \| All writes and PHI reads/exports audited with actor + IP; audit rows immutable and shipped… |
| 135 | [VERIFY] | Disposal \| Database rows: per DATA-RETENTION.md; files: cryptographic erasure (delete + key destruction) or se… |
| 136 | [VERIFY] | Incidents \| Any suspected exposure is at least SEV2 \| INCIDENT-RESPONSE.md §2 \| [VERIFY] |
| 142 | [VERIFY term] | Labelling \| **CONFIDENTIAL** (EN) / **خاص** or **مقيد** per the hospital's convention (AR) [VERIFY term] \| Not… |
| 142 | [VERIFY] | Labelling \| **CONFIDENTIAL** (EN) / **خاص** or **مقيد** per the hospital's convention (AR) [VERIFY term] \| Not… |
| 143 | [VERIFY] | Access \| Hospital staff with a role-based need; MFA for system access \| Roles; admin-only for audit/config/sta… |
| 144 | [VERIFY] | Storage \| Access-controlled; encryption at rest recommended; in-Kingdom preferred \| Same host as Secret data (… |
| 145 | [VERIFY] | Transit \| TLS; hospital e-mail acceptable internally; staff personal data may leave the Kingdom only under a d… |
| 145 | [VERIFY] | Transit \| TLS; hospital e-mail acceptable internally; staff personal data may leave the Kingdom only under a d… |
| 146 | [VERIFY] | Sharing \| Internal on a need-to-know basis; external only under NDA/contract \| — \| [VERIFY] |
| 147 | [VERIFY] | Logging \| Writes audited; reads not required \| Config changes → `setting_changes` + `settings.*` audit rows; u… |
| 148 | [VERIFY] | Disposal \| Standard deletion; log rotation; paper recycling after shredding \| `daily` log channel, `LOG_DAILY_… |
| 159 | [VERIFY] | separate accreditation would be required [VERIFY]. |
| 167 | [PLACEHOLDER] | Data owner — clinical (Head of IM) [PLACEHOLDER] \| Confirms the classification of clinical data; sets the prin… |
| 168 | [PLACEHOLDER] | Data owner — staff data (HR / System owner) [PLACEHOLDER] \| Confirms classification of account data; departure… |
| 169 | [PLACEHOLDER] | Information-security lead [PLACEHOLDER] \| Maintains this scheme; maps DCC control refs; verifies technical han… |
| 170 | [PLACEHOLDER] | DPO [PLACEHOLDER] \| Approves any external sharing; owns breach decisions |
| 177 | [PLACEHOLDER — engineering to add headers/footers] | - **Labelling:** exports and printed sheets should carry the level in both languages; until automated, users a… |
| 188 | [PLACEHOLDER] | PHI not encrypted at rest inside MySQL \| [PLACEHOLDER] \| [PLACEHOLDER] \| DPIA action 2 |
| 188 | [PLACEHOLDER] | PHI not encrypted at rest inside MySQL \| [PLACEHOLDER] \| [PLACEHOLDER] \| DPIA action 2 |
| 189 | [PLACEHOLDER] | No automated encrypted backup; manual dumps unencrypted on the host \| [PLACEHOLDER] \| [PLACEHOLDER] \| DPIA act… |
| 189 | [PLACEHOLDER] | No automated encrypted backup; manual dumps unencrypted on the host \| [PLACEHOLDER] \| [PLACEHOLDER] \| DPIA act… |
| 190 | [PLACEHOLDER] | Exports unlabeled; statistics and audit-log exports unaudited \| [PLACEHOLDER] \| [PLACEHOLDER] \| DPIA action 8 |
| 190 | [PLACEHOLDER] | Exports unlabeled; statistics and audit-log exports unaudited \| [PLACEHOLDER] \| [PLACEHOLDER] \| DPIA action 8 |
| 191 | [PLACEHOLDER — governance] | `log_record_opens` default OFF \| [PLACEHOLDER — governance] \| [PLACEHOLDER] \| DPIA R6 |
| 191 | [PLACEHOLDER] | `log_record_opens` default OFF \| [PLACEHOLDER — governance] \| [PLACEHOLDER] \| DPIA R6 |
| 192 | [PLACEHOLDER — legal] | Cloudflare edge decryption legal status \| [PLACEHOLDER — legal] \| [PLACEHOLDER] \| DPIA R5 |
| 192 | [PLACEHOLDER] | Cloudflare edge decryption legal status \| [PLACEHOLDER — legal] \| [PLACEHOLDER] \| DPIA R5 |
| 193 | [PLACEHOLDER — clinical] | Paper handling rule for printed sheets \| [PLACEHOLDER — clinical] \| [PLACEHOLDER] \| — |
| 193 | [PLACEHOLDER] | Paper handling rule for printed sheets \| [PLACEHOLDER — clinical] \| [PLACEHOLDER] \| — |
| 194 | [PLACEHOLDER] | Legacy PHI dump copies not inventoried \| [PLACEHOLDER] \| [PLACEHOLDER] \| DATA-RETENTION.md |
| 194 | [PLACEHOLDER] | Legacy PHI dump copies not inventoried \| [PLACEHOLDER] \| [PLACEHOLDER] \| DATA-RETENTION.md |
| 202 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Initial draft \| [PLACEHOLDER] |

## `DATA-RETENTION.md` — 96 items

| Line | Marker | Context |
|---:|---|---|
| 7 | [PLACEHOLDER — DPO] | > Version 0.1 · 2026-09-03 · Schedule owner: [PLACEHOLDER — DPO], with the System owner for implementation. |
| 17 | [VERIFY ARTICLE — destruction duty and its exceptions] | period only [VERIFY ARTICLE — destruction duty and its exceptions]. Separately, Saudi |
| 42 | [PLACEHOLDER — contract reference once signed] | these obligations is contractually binding on it [PLACEHOLDER — contract reference once signed]. |
| 56 | [PLACEHOLDER — processor to state what deletion is possible on the account] | C1 \| The **daily** clinical database \| Legacy PHP app, **SiteGround shared hosting, United States** \| Operator… |
| 60 | [VERIFY — SiteGround backup scope, retention and deletion on request] | C5 \| Whatever SiteGround retains of the daily database \| **United States**, inside SiteGround's platform \| Sit… |
| 62 | [VERIFY] | C7 \| The `dmc-im.com` mailbox \| SiteGround, **United States** \| Operator company \| Mailbox policy [VERIFY] (§2… |
| 72 | [NEEDS LEGAL CONFIRMATION] | asserted anywhere in this document** [NEEDS LEGAL CONFIRMATION]. |
| 76 | [VERIFY which trigger the regulation uses; special rules for minors or deceased patients] | `patients`, `admissions`, `admission_diagnoses`, `consultations`, `consultation_followups`, `handovers`, `hand… |
| 76 | [NEEDS LEGAL CONFIRMATION] | `patients`, `admissions`, `admission_diagnoses`, `consultations`, `consultation_followups`, `handovers`, `hand… |
| 76 | [VERIFY whether the hub is part of the medical record] | `patients`, `admissions`, `admission_diagnoses`, `consultations`, `consultation_followups`, `handovers`, `hand… |
| 76 | [PLACEHOLDER — DPO + clinical owner] | `patients`, `admissions`, `admission_diagnoses`, `consultations`, `consultation_followups`, `handovers`, `hand… |
| 77 | [PLACEHOLDER — processor; cutover date] | The **same clinical records** in the legacy daily database \| **Legacy daily (C1)** — SiteGround, **United Stat… |
| 89 | [VERIFY the six-year figure against any sector requirement for access-log retention] | `audit_log` rows (local database) \| `created_at` \| **Six years** (`settings.audit_retention_years`, default si… |
| 90 | [VERIFY configured] | Audit archive NDJSON objects (WORM bucket) \| Object creation \| **Seven years** (bucket retention lock) \| Immut… |
| 91 | [VERIFY] | **Why seven off-box vs six local** \| \| The archive must outlive the local rows by a margin so that (a) a local… |
| 92 | [VERIFY] | Pre-chain audit rows (NULL `row_hash`) \| — \| As above \| Present until backfilled [VERIFY] \| Backfill, then nor… |
| 94 | [PLACEHOLDER] | Non-patient `notifications` (`audit.integrity_failure`, `security.failed_logins`, consultation/handover bell r… |
| 100 | [VERIFY sufficient against the clinical-record and audit periods — a backup only needs to cover recovery, not retention, because the live database and the WORM archive are the retention copies] | Automated encrypted off-box backups \| **Laravel (C4)** — in-Kingdom OCI bucket `dmc-db-backups` \| Backup creat… |
| 101 | [VERIFY] | Whatever SiteGround retains of the **daily** database \| **Legacy (C5)** — SiteGround, **United States** \| Prov… |
| 102 | [PLACEHOLDER] | Manual pre-deploy dumps `~/pre-deploy-*.sql.gz` \| Laravel host (C6) \| Creation (DEPLOY-LARAVEL.md §7) \| **Prop… |
| 102 | [VERIFY] | Manual pre-deploy dumps `~/pre-deploy-*.sql.gz` \| Laravel host (C6) \| Creation (DEPLOY-LARAVEL.md §7) \| **Prop… |
| 103 | [PLACEHOLDER] | Incident dumps / OCI volume snapshots named `INC-…` \| Laravel (C6) \| Incident closure \| For the duration of th… |
| 104 | [PLACEHOLDER — date] | Legacy database dumps and exports \| **C6** — operator workstations and the OCI host under `/home/ubuntu/migrat… |
| 104 | [VERIFY local copies destroyed] | Legacy database dumps and exports \| **C6** — operator workstations and the OCI host under `/home/ubuntu/migrat… |
| 105 | [NEEDS LEGAL CONFIRMATION] | The legacy application's own database \| **Legacy daily (C1)** — SiteGround, **United States**; still the live … |
| 105 | [PLACEHOLDER — cutover date] | The legacy application's own database \| **Legacy daily (C1)** — SiteGround, **United States**; still the live … |
| 116 | [VERIFY path on the live host] | Sessions (`storage/framework/sessions/*` files; `sessions` table if the driver is `database`) \| Last activity … |
| 117 | [PLACEHOLDER] | `trusted_devices` \| `expires_at` (fixed window `settings.mfa_trusted_device_hours`, default twenty-four hours;… |
| 118 | [VERIFY] | `pending_registrations` \| `expires_at` (**thirty minutes** after creation) \| Delete at expiry \| The controller… |
| 128 | [PLACEHOLDER — joiner/leaver procedure covering both systems] | in one leaves the other open [PLACEHOLDER — joiner/leaver procedure covering both systems]. |
| 133 | [PLACEHOLDER — HR feed] | Account after departure \| HR leaving date [PLACEHOLDER — HR feed] \| **Immediately:** set `active = 0` (Control… |
| 133 | [PLACEHOLDER — proposed ninety days] | Account after departure \| HR leaving date [PLACEHOLDER — HR feed] \| **Immediately:** set `active = 0` (Control… |
| 133 | [NEEDS LEGAL CONFIRMATION — employment-record retention rules] | Account after departure \| HR leaving date [PLACEHOLDER — HR feed] \| **Immediately:** set `active = 0` (Control… |
| 141 | [VERIFY — legacy log and export inventory] | [VERIFY — legacy log and export inventory]. Note also that exports and printed sheets exist in |
| 148 | [PLACEHOLDER; VERIFY no sector minimum for security logs] | Application log `storage/logs/laravel-YYYY-MM-DD.log` \| File date \| `daily` channel with `LOG_DAILY_DAYS` (**d… |
| 148 | [VERIFY live value] | Application log `storage/logs/laravel-YYYY-MM-DD.log` \| File date \| `daily` channel with `LOG_DAILY_DAYS` (**d… |
| 149 | [VERIFY `max-size`/`max-file` on the live daemon] | Container stdout/stderr logs (Docker json-file) \| — \| Docker default is unbounded [VERIFY `max-size`/`max-file… |
| 152 | [PLACEHOLDER] | Registry exports (CSV/XLSX) on staff devices — **Laravel** \| Download \| **Proposed: delete within thirty days … |
| 153 | [PLACEHOLDER] | Audit-log exports, statistics exports, report PDFs — **Laravel** \| Download \| As above [PLACEHOLDER] \| No rule… |
| 154 | [PLACEHOLDER] | Exports and printed output from the **legacy daily system** \| Download / printing \| As above [PLACEHOLDER] \| N… |
| 155 | [VERIFY] | Monthly report PDF (aggregate) \| Sent on the first of the month at 06:00 \| At recipients' mailboxes per the ho… |
| 155 | [VERIFY relay retention] | Monthly report PDF (aggregate) \| Sent on the first of the month at 06:00 \| At recipients' mailboxes per the ho… |
| 156 | [VERIFY live value] | Queued job payloads (`jobs` table) carrying the PDF \| Job completion \| `.env.example` sets `QUEUE_CONNECTION=s… |
| 157 | [PLACEHOLDER — clinical rule] | Printed handover / service sheets \| Printing \| **End of shift or when superseded, whichever is first** [PLACEH… |
| 188 | [NEEDS LEGAL CONFIRMATION] | 1 \| Obtain the clinical-record retention period and trigger from legal \| [NEEDS LEGAL CONFIRMATION] \| [PLACEHO… |
| 188 | [PLACEHOLDER — DPO] | 1 \| Obtain the clinical-record retention period and trigger from legal \| [NEEDS LEGAL CONFIRMATION] \| [PLACEHO… |
| 188 | [PLACEHOLDER] | 1 \| Obtain the clinical-record retention period and trigger from legal \| [NEEDS LEGAL CONFIRMATION] \| [PLACEHO… |
| 189 | [PLACEHOLDER — DPO + clinical owner] | 2 \| Decide delete-vs-anonymise for expired clinical rows \| Open \| [PLACEHOLDER — DPO + clinical owner] \| [PLAC… |
| 189 | [PLACEHOLDER] | 2 \| Decide delete-vs-anonymise for expired clinical rows \| Open \| [PLACEHOLDER — DPO + clinical owner] \| [PLAC… |
| 190 | [PLACEHOLDER] | 3 \| Build `records:prune` (dry-run default, `--confirm`, audited, refuses when the period is unset) or the ano… |
| 190 | [PLACEHOLDER] | 3 \| Build `records:prune` (dry-run default, `--confirm`, audited, refuses when the period is unset) or the ano… |
| 191 | [PLACEHOLDER] | 4 \| Schedule `audit:prune` **dry-run** monthly with notification; document the quarterly operator run \| GAP \| … |
| 191 | [PLACEHOLDER] | 4 \| Schedule `audit:prune` **dry-run** monthly with notification; document the quarterly operator run \| GAP \| … |
| 192 | [VERIFY] | 5 \| Backfill pre-chain audit hashes \| [VERIFY] \| [PLACEHOLDER] \| [PLACEHOLDER] |
| 192 | [PLACEHOLDER] | 5 \| Backfill pre-chain audit hashes \| [VERIFY] \| [PLACEHOLDER] \| [PLACEHOLDER] |
| 192 | [PLACEHOLDER] | 5 \| Backfill pre-chain audit hashes \| [VERIFY] \| [PLACEHOLDER] \| [PLACEHOLDER] |
| 193 | [VERIFY] | 6 \| Confirm WORM bucket lifecycle expires objects after the seven-year lock \| [VERIFY] \| [PLACEHOLDER] \| [PLAC… |
| 193 | [PLACEHOLDER] | 6 \| Confirm WORM bucket lifecycle expires objects after the seven-year lock \| [VERIFY] \| [PLACEHOLDER] \| [PLAC… |
| 193 | [PLACEHOLDER] | 6 \| Confirm WORM bucket lifecycle expires objects after the seven-year lock \| [VERIFY] \| [PLACEHOLDER] \| [PLAC… |
| 194 | [PLACEHOLDER] | 7 \| Backups: ninety-day lifecycle rule; encrypt; stale alert; restore test \| **Done for the Laravel copy** (ni… |
| 194 | [PLACEHOLDER] | 7 \| Backups: ninety-day lifecycle rule; encrypt; stale alert; restore test \| **Done for the Laravel copy** (ni… |
| 195 | [PLACEHOLDER] | 8 \| Encrypt-at-creation + off-box + seven-day delete for manual pre-deploy dumps \| GAP \| [PLACEHOLDER] \| [PLAC… |
| 195 | [PLACEHOLDER] | 8 \| Encrypt-at-creation + off-box + seven-day delete for manual pre-deploy dumps \| GAP \| [PLACEHOLDER] \| [PLAC… |
| 196 | [PLACEHOLDER] | 9 \| Inventory and destroy legacy dumps (CONFIRMED-FACTS D1, incl. the plaintext dump and key files on the OCI … |
| 196 | [PLACEHOLDER] | 9 \| Inventory and destroy legacy dumps (CONFIRMED-FACTS D1, incl. the plaintext dump and key files on the OCI … |
| 197 | [PLACEHOLDER — owner + DPO] | 9a \| **Decide and record what happens to the legacy daily database (C1) and to SiteGround-held backups (C5)** … |
| 197 | [PLACEHOLDER] | 9a \| **Decide and record what happens to the legacy daily database (C1) and to SiteGround-held backups (C5)** … |
| 198 | [PLACEHOLDER — IT lead] | 9b \| Obtain SiteGround's backup scope, retention and deletion terms; cover them in the transfer safeguard \| GA… |
| 198 | [PLACEHOLDER] | 9b \| Obtain SiteGround's backup scope, retention and deletion terms; cover them in the transfer safeguard \| GA… |
| 199 | [PLACEHOLDER — DPO + owner] | 9c \| **Sign the controller–processor contract** so these periods bind the operator company (IR Art. 17 minimum… |
| 199 | [PLACEHOLDER] | 9c \| **Sign the controller–processor contract** so these periods bind the operator company (IR Art. 17 minimum… |
| 200 | [PLACEHOLDER] | 10 \| Nightly sweeps: expired sessions (file driver), `pending_registrations`, `trusted_devices` (after ninety … |
| 200 | [PLACEHOLDER] | 10 \| Nightly sweeps: expired sessions (file driver), `pending_registrations`, `trusted_devices` (after ninety … |
| 201 | [VERIFY live values] | 11 \| Set `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS` per the agreed period; Docker log rotation \| [VERIFY live value… |
| 201 | [PLACEHOLDER] | 11 \| Set `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS` per the agreed period; Docker log rotation \| [VERIFY live value… |
| 201 | [PLACEHOLDER] | 11 \| Set `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS` per the agreed period; Docker log rotation \| [VERIFY live value… |
| 202 | [PLACEHOLDER — HR / System owner] | 12 \| Departure checklist + quarterly access review, **covering both systems' account sets** \| GAP \| [PLACEHOLD… |
| 202 | [PLACEHOLDER] | 12 \| Departure checklist + quarterly access review, **covering both systems' account sets** \| GAP \| [PLACEHOLD… |
| 203 | [PLACEHOLDER] | 13 \| Staff rule for exports and printed sheets; add classification labels to exports \| **Labels done on the La… |
| 203 | [PLACEHOLDER] | 13 \| Staff rule for exports and printed sheets; add classification labels to exports \| **Labels done on the La… |
| 204 | [PLACEHOLDER — processor] | 14 \| Inventory the legacy schema, logs and exports on SiteGround so this schedule can be applied to C1/C5 at a… |
| 204 | [PLACEHOLDER] | 14 \| Inventory the legacy schema, logs and exports on SiteGround so this schedule can be applied to C1/C5 at a… |
| 213 | [VERIFY binlog configuration] | reused, and in the binary log / redo log if enabled [VERIFY binlog configuration]. For sensitive |
| 227 | [VERIFY] | destruction where available [VERIFY]). Confirm no copies exist in home-directory backups, editor |
| 234 | [VERIFY the key-management setup for the backup bucket and the audit archive] | form of erasure [VERIFY the key-management setup for the backup bucket and the audit archive]. |
| 242 | [PLACEHOLDER — obtain and file] | **Cloudflare** (edge logs). None of these has a signed DPA on file [PLACEHOLDER — obtain and file]. |
| 261 | [NEEDS LEGAL CONFIRMATION] | hold: the health-care regulation's record-keeping period [NEEDS LEGAL CONFIRMATION] (a legal |
| 262 | [VERIFY ARTICLE] | obligation — the exception the law recognises [VERIFY ARTICLE]), and statistical/quality |
| 264 | [VERIFY ARTICLE — statistics/research provision and whether it requires anonymisation] | [VERIFY ARTICLE — statistics/research provision and whether it requires anonymisation]. |
| 270 | [VERIFY] | DPO should confirm whether that residual identifier must also be hashed [VERIFY]). |
| 278 | [VERIFY any sector rule for access-log retention in health-care] | and period [VERIFY any sector rule for access-log retention in health-care]. |
| 288 | [PLACEHOLDER — cutover date; destruction certificate] | migration dump [PLACEHOLDER — cutover date; destruction certificate]. |
| 296 | [PLACEHOLDER] | annually. Next review: [PLACEHOLDER]. |
| 302 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Initial draft \| [PLACEHOLDER] |
| 303 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Reworked to the confirmed framing: two-system principle and controller/processor split (§1.… |
| 304 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Reconciled §2.6 and checklist item 13 with CONFIRMED-FACTS C12/C13 as re-verified: every La… |

## `DPA-AND-TRANSFERS.md` — 51 items

| Line | Marker | Context |
|---:|---|---|
| 8 | [VERIFY ARTICLE] | > Square-bracketed markers are open items; `[VERIFY ARTICLE]` / `[VERIFY]` mean the obligation is |
| 8 | [VERIFY] | > Square-bracketed markers are open items; `[VERIFY ARTICLE]` / `[VERIFY]` mean the obligation is |
| 18 | [VERIFY] | P1 \| **Oracle Cloud Infrastructure (OCI)** \| Oracle [ENTITY — PLACEHOLDER]; HQ United States \| Compute, block … |
| 18 | [VERIFY current documents] | P1 \| **Oracle Cloud Infrastructure (OCI)** \| Oracle [ENTITY — PLACEHOLDER]; HQ United States \| Compute, block … |
| 19 | [VERIFY current version and whether the plan tier allows negotiation] | P2 \| **Cloudflare** \| Cloudflare, Inc.; HQ United States \| DNS, reverse proxy / WAF, TLS termination at the ed… |
| 20 | [VERIFY] | P3 \| **Transactional SMTP relay** \| [PROVIDER NAME / ENTITY — PLACEHOLDER]; hosted in the United States \| Outb… |
| 20 | [VERIFY] | P3 \| **Transactional SMTP relay** \| [PROVIDER NAME / ENTITY — PLACEHOLDER]; hosted in the United States \| Outb… |
| 20 | [VERIFY] | P3 \| **Transactional SMTP relay** \| [PROVIDER NAME / ENTITY — PLACEHOLDER]; hosted in the United States \| Outb… |
| 21 | [VERIFY] | P4 \| **GitHub** \| GitHub, Inc. (Microsoft); HQ United States \| Source-code hosting, CI (GitHub Actions) \| Sour… |
| 29 | [VERIFY ARTICLE] | every row must be tied to its provision by counsel [VERIFY ARTICLE]. |
| 38 | [VERIFY period] | C6 \| **Security** measures appropriate to sensitive data; **breach notification** to the controller without un… |
| 47 | [VERIFY ARTICLE / VERIFY the current text of the regulations] | [VERIFY ARTICLE / VERIFY the current text of the regulations]: |
| 55 | [VERIFY] | specific situations (e.g. protecting a person's vital interests) [VERIFY]. |
| 59 | [VERIFY when a TRA is mandatory] | continuous or large-scale) [VERIFY when a TRA is mandatory], kept on file and reviewed. |
| 88 | [VERIFY] | D11 \| **Cross-border transfer mechanism** — SCCs / approved clauses under the Data Transfer Regulations [VERIF… |
| 102 | [VERIFY] | Location \| Riyadh region (in-Kingdom). Confirm the tenancy's **home region** and that **no cross-region replic… |
| 103 | [VERIFY what is enabled on this tenancy and who holds the keys] | Encryption \| In transit: TLS 1.2+. At rest: platform-level volume encryption is a feature of the provider [VER… |
| 103 | [PLACEHOLDER] | Encryption \| In transit: TLS 1.2+. At rest: platform-level volume encryption is a feature of the provider [VER… |
| 104 | [VERIFY current titles and that they cover the PDPL] | What to sign \| Oracle cloud services agreement / ordering document + Oracle's data-processing terms for cloud … |
| 105 | [VERIFY] | Transfer analysis \| Processing is in-Kingdom, so no transfer for storage/processing. **Residual question:** Or… |
| 105 | [NEEDS LEGAL CONFIRMATION] | Transfer analysis \| Processing is in-Kingdom, so no transfer for storage/processing. **Residual question:** Or… |
| 121 | [VERIFY against Cloudflare's DPA and logging documentation for the plan in use] | Duration of processing at the edge \| Momentary (in memory for the life of the request). No storage of payloads… |
| 123 | [VERIFY Cloudflare's transparency and government-request commitments] | Destination and law \| Cloudflare's edge nearest the user — observed in-Kingdom, but selection is automatic and… |
| 124 | [VERIFY current version; whether it is negotiable at the plan tier; whether it contains clauses acceptable under the Saudi Data Transfer Regulations or must be supplemented with authority-approved standard contractual clauses] | Existing contractual safeguards \| Cloudflare's standard terms and Data Processing Addendum (self-serve plans) … |
| 125 | [VERIFY] | Existing technical safeguards \| TLS 1.2+ end-to-end; origin firewall admits Cloudflare ranges only [VERIFY]; P… |
| 127 | [VERIFY with Cloudflare] | Mitigation options \| **A. Regional Services (Data Localization Suite).** Cloudflare's feature that pins TLS te… |
| 127 | [VERIFY] | Mitigation options \| **A. Regional Services (Data Localization Suite).** Cloudflare's feature that pins TLS te… |
| 127 | [VERIFY availability] | Mitigation options \| **A. Regional Services (Data Localization Suite).** Cloudflare's feature that pins TLS te… |
| 127 | [NEEDS LEGAL CONFIRMATION] | Mitigation options \| **A. Regional Services (Data Localization Suite).** Cloudflare's feature that pins TLS te… |
| 132 | [DATE — 12 months from approval] | Review date \| [DATE — 12 months from approval] |
| 134 | [VERIFY] | **What to sign:** Cloudflare DPA (plan-appropriate) [VERIFY]; if option A, the Enterprise / DLS order |
| 136 | [VERIFY] | [VERIFY]. **Owner:** DPO + IT lead. **Status:** OPEN — decision pending. |
| 142 | [VERIFY by inspecting a current monthly PDF and the mail templates before signing this off] | What it carries \| Email-verification and MFA-enrolment codes, password-reset links, username reminders, MFA-re… |
| 143 | [VERIFY templates] | Personal data \| Staff name and email address, time-limited codes and signed links (credential material in tran… |
| 145 | [VERIFY] | Risks \| Provider log retention of message bodies (OTP codes / reset links) [VERIFY]; provider breach; lawful a… |
| 146 | [VERIFY] | Options \| **1. Move to an in-Kingdom relay** (hospital mail system or a Saudi-hosted provider): removes the tr… |
| 156 | [VERIFY] | Personal data \| Developer identities in commits and accounts. **Must never hold patient data.** Action items: … |
| 156 | [VERIFY] | Personal data \| Developer identities in commits and accounts. **Must never hold patient data.** Action items: … |
| 156 | [VERIFY] | Personal data \| Developer identities in commits and accounts. **Must never hold patient data.** Action items: … |
| 159 | [VERIFY] | What to sign \| GitHub terms + DPA as applicable [VERIFY]; internal policy: no data files in the repository. |
| 167 | [VERIFY ARTICLE] | A1 \| Legal review of §2–§3 wording; cite provisions for every `[VERIFY ARTICLE]`. \| Legal \| [DATE] \| OPEN |
| 167 | [DATE] | A1 \| Legal review of §2–§3 wording; cite provisions for every `[VERIFY ARTICLE]`. \| Legal \| [DATE] \| OPEN |
| 168 | [DATE] | A2 \| Collect and file signed agreements / DPAs for P1–P4; complete §4 grid. \| IT lead + Legal \| [DATE] \| OPEN |
| 169 | [DATE] | A3 \| OCI: confirm home region, no cross-region copies, key management, support-access statement. \| IT lead \| [… |
| 170 | [DATE] | A4 \| Cloudflare: ask the account team whether a Saudi-Arabia region is available for Regional Services; price … |
| 171 | [DATE] | A5 \| SMTP: decide in-Kingdom relay vs. safeguards; rotate credentials in Control → System if moving. \| IT lead… |
| 172 | [DATE] | A6 \| GitHub: history check for data/secrets; enable protections; record result. \| Dev lead \| [DATE] \| OPEN |
| 173 | [DATE] | A7 \| Update privacy notice §7 (both languages + `resources/lang`) once A4–A5 are decided. \| DPO + Dev \| [DATE]… |
| 174 | [VERIFY] | A8 \| Add the hub to the hospital-wide record of processing; register / update with the competent authority [VE… |
| 174 | [DATE] | A8 \| Add the hub to the hospital-wide record of processing; register / update with the competent authority [VE… |
| 175 | [DATE] | A9 \| Confirm sector-specific health-data localisation requirements (§3 overlay). \| Legal \| [DATE] \| OPEN |

## `DPIA.md` — 125 items

| Line | Marker | Context |
|---:|---|---|
| 10 | [PLACEHOLDER — DPO] | > Assessment owner: [PLACEHOLDER — DPO]. Technical contributor: [PLACEHOLDER]. Clinical contributor: [PLACEHOL… |
| 10 | [PLACEHOLDER] | > Assessment owner: [PLACEHOLDER — DPO]. Technical contributor: [PLACEHOLDER]. Clinical contributor: [PLACEHOL… |
| 10 | [PLACEHOLDER] | > Assessment owner: [PLACEHOLDER — DPO]. Technical contributor: [PLACEHOLDER]. Clinical contributor: [PLACEHOL… |
| 161 | [VERIFY ARTICLE — transfer definition] | [VERIFY ARTICLE — transfer definition]. |
| 168 | [VERIFY] | SiteGround takes, in the United States [VERIFY]. |
| 176 | [PLACEHOLDER — governance instruction] | Is each data element necessary for the purpose? \| Demographics are minimal (age, not date of birth; nationalit… |
| 176 | [VERIFY] | Is each data element necessary for the purpose? \| Demographics are minimal (age, not date of birth; nationalit… |
| 177 | [VERIFY] | Purpose limitation \| Purposes are operational and internal; statistics are a compatible further use [VERIFY]. … |
| 178 | [VERIFY] | Accuracy \| Attribution is session-sourced; multi-row writes are transactional; data-quality digest runs daily.… |
| 179 | [NEEDS LEGAL CONFIRMATION] | Storage limitation \| No retention period is defined for clinical rows; nothing is ever purged — **in either sy… |
| 180 | [PLACEHOLDER — owner + DPO] | **Duplication** \| The same dataset is held twice, in two jurisdictions, under two different control regimes, f… |
| 181 | [PLACEHOLDER — HR/IT policy] | Transparency \| A privacy notice is served by the Laravel app at `/privacy`; the legacy daily site — the one st… |
| 181 | [PLACEHOLDER] | Transparency \| A privacy notice is served by the Laravel app at `/privacy`; the legacy daily site — the one st… |
| 182 | [PLACEHOLDER] | Data-subject rights \| Fulfilled indirectly via the medical-records office, and only fully if **both** copies a… |
| 183 | [PLACEHOLDER — owner/date] | Processors \| **Primary processor: the operator company — no controller–processor contract exists (top action).… |
| 184 | [VERIFY] | International transfers \| **The whole daily clinical dataset is hosted in the United States** (SiteGround); pl… |
| 203 | [VERIFY] | consultation with the competent authority [VERIFY]. |
| 228 | [VERIFY] | **Existing controls** \| Mandatory TOTP MFA for every user (`EnsureMfaEnrolled`); phased registration with e-ma… |
| 230 | [PLACEHOLDER — owner, date] | **Planned** \| Extend encryption at rest beyond the four narrative columns — today everything else relies on th… |
| 230 | [PLACEHOLDER — owner, date] | **Planned** \| Extend encryption at rest beyond the four narrative columns — today everything else relies on th… |
| 230 | [PLACEHOLDER] | **Planned** \| Extend encryption at rest beyond the four narrative columns — today everything else relies on th… |
| 230 | [PLACEHOLDER] | **Planned** \| Extend encryption at rest beyond the four narrative columns — today everything else relies on th… |
| 236 | [VERIFY the exact production change and date; VERIFY whether historical rows in the affected window were corrected] | **Scenario** \| A systematic fault causes records to state something false about a patient. **Worked example — … |
| 240 | [PLACEHOLDER — owner, date] | **Planned** \| Add a startup/health assertion that the effective timezone equals the configured hospital zone a… |
| 240 | [PLACEHOLDER] | **Planned** \| Add a startup/health assertion that the effective timezone equals the configured hospital zone a… |
| 249 | [VERIFY] | **Existing controls** \| **Laravel side:** nightly encrypted off-box dump to the in-Kingdom bucket `dmc-db-back… |
| 251 | [PLACEHOLDER — owner, date] | **Planned** \| Establish, test and document a backup and restore path for the **legacy daily database** for as … |
| 251 | [VERIFY] | **Planned** \| Establish, test and document a backup and restore path for the **legacy daily database** for as … |
| 251 | [PLACEHOLDER — DPO] | **Planned** \| Establish, test and document a backup and restore path for the **legacy daily database** for as … |
| 262 | [PLACEHOLDER] | **Planned** \| Quarterly access review of roles and capability flags in Control → Users [PLACEHOLDER]; decide w… |
| 262 | [PLACEHOLDER — governance] | **Planned** \| Quarterly access review of roles and capability flags in Control → Users [PLACEHOLDER]; decide w… |
| 262 | [PLACEHOLDER] | **Planned** \| Quarterly access review of roles and capability flags in Control → Users [PLACEHOLDER]; decide w… |
| 268 | [VERIFY] | **Scope** \| **Laravel parallel copy only** — the legacy daily site is served from SiteGround and shows none of… |
| 273 | [VERIFY; owner PLACEHOLDER; date PLACEHOLDER] | **Planned** \| Legal determination whether this constitutes a transfer and what safeguard applies (DPA, an Ente… |
| 284 | [PLACEHOLDER — decision] | **Planned** \| Turn `log_record_opens` ON [PLACEHOLDER — decision]; periodic review of `registry.export` and th… |
| 284 | [PLACEHOLDER] | **Planned** \| Turn `log_record_opens` ON [PLACEHOLDER — decision]; periodic review of `registry.export` and th… |
| 284 | [PLACEHOLDER — HR] | **Planned** \| Turn `log_record_opens` ON [PLACEHOLDER — decision]; periodic review of `registry.export` and th… |
| 295 | [VERIFY] | **Planned** \| Relay DPA and transfer safeguard — standard contractual clauses plus a transfer risk assessment … |
| 295 | [PLACEHOLDER] | **Planned** \| Relay DPA and transfer safeguard — standard contractual clauses plus a transfer risk assessment … |
| 306 | [VERIFY done] | **Planned** \| Backfill pre-chain rows so the whole history verifies [VERIFY done]; after any database restore,… |
| 306 | [PLACEHOLDER — add to restore runbook] | **Planned** \| Backfill pre-chain rows so the whole history verifies [VERIFY done]; after any database restore,… |
| 312 | [VERIFY — how legacy changes are made and by whom] | **Scope** \| The **Laravel** delivery chain. The legacy daily system is not deployed from this pipeline at all … |
| 317 | [PLACEHOLDER — date] | **Planned** \| **Make the repository private before go-live** [PLACEHOLDER — date]; dependency vulnerability sc… |
| 317 | [PLACEHOLDER] | **Planned** \| **Make the repository private before go-live** [PLACEHOLDER — date]; dependency vulnerability sc… |
| 317 | [VERIFY] | **Planned** \| **Make the repository private before go-live** [PLACEHOLDER — date]; dependency vulnerability sc… |
| 317 | [VERIFY] | **Planned** \| **Make the repository private before go-live** [PLACEHOLDER — date]; dependency vulnerability sc… |
| 326 | [VERIFY] | **Existing controls** \| SSH key auth [VERIFY]; Cloudflare-only ingress; **nightly encrypted off-box backups** … |
| 326 | [VERIFY lock mode] | **Existing controls** \| SSH key auth [VERIFY]; Cloudflare-only ingress; **nightly encrypted off-box backups** … |
| 328 | [PLACEHOLDER — owner, date] | **Planned** \| Extend encryption at rest beyond the narrative columns, with customer-managed keys, so host comp… |
| 328 | [PLACEHOLDER] | **Planned** \| Extend encryption at rest beyond the narrative columns, with customer-managed keys, so host comp… |
| 328 | [PLACEHOLDER] | **Planned** \| Extend encryption at rest beyond the narrative columns, with customer-managed keys, so host comp… |
| 328 | [PLACEHOLDER] | **Planned** \| Extend encryption at rest beyond the narrative columns, with customer-managed keys, so host comp… |
| 339 | [NEEDS LEGAL CONFIRMATION] | **Planned** \| DATA-RETENTION.md schedule applied to **every** copy C1–C7; legal confirmation of the clinical p… |
| 339 | [PLACEHOLDER — owner + DPO] | **Planned** \| DATA-RETENTION.md schedule applied to **every** copy C1–C7; legal confirmation of the clinical p… |
| 339 | [PLACEHOLDER — engineering] | **Planned** \| DATA-RETENTION.md schedule applied to **every** copy C1–C7; legal confirmation of the clinical p… |
| 348 | [VERIFY] | **Existing controls** \| Laravel side: URLs PHI-free (so web/CDN/CSP logs are clean); CSP reports carry no PHI;… |
| 349 | [VERIFY] | **Residual** \| L3 × S3 = **9 Medium** for the Laravel copy; higher for the legacy side, which is unassessed [V… |
| 350 | [PLACEHOLDER] | **Planned** \| Classification and handling rules for each store (DATA-CLASSIFICATION.md); log-scrubbing review … |
| 350 | [PLACEHOLDER — clinical governance] | **Planned** \| Classification and handling rules for each store (DATA-CLASSIFICATION.md); log-scrubbing review … |
| 350 | [PLACEHOLDER — processor] | **Planned** \| Classification and handling rules for each store (DATA-CLASSIFICATION.md); log-scrubbing review … |
| 359 | [VERIFY — authentication and authorisation actually in force on the live legacy build] | **Existing controls** \| **None of the controls this assessment credits elsewhere.** A hardened lineage exists … |
| 361 | [PLACEHOLDER — owner, date] | **Planned** \| An owner decision, not an engineering task: **either complete cutover** so the Laravel app repla… |
| 372 | [VERIFY; owner PLACEHOLDER] | **Planned** \| Legal analysis of the transfer and the applicable safeguard [VERIFY; owner PLACEHOLDER]; standar… |
| 372 | [VERIFY] | **Planned** \| Legal analysis of the transfer and the applicable safeguard [VERIFY; owner PLACEHOLDER]; standar… |
| 372 | [PLACEHOLDER — cutover date] | **Planned** \| Legal analysis of the transfer and the applicable safeguard [VERIFY; owner PLACEHOLDER]; standar… |
| 383 | [PLACEHOLDER — owner] | **Planned** \| Fix and hold a **cutover date** so the parallel window is as short as the project allows [PLACEH… |
| 383 | [PLACEHOLDER] | **Planned** \| Fix and hold a **cutover date** so the parallel window is as short as the project allows [PLACEH… |
| 383 | [PLACEHOLDER — DPO] | **Planned** \| Fix and hold a **cutover date** so the parallel window is as short as the project allows [PLACEH… |
| 415 | [PLACEHOLDER — cutover date] | [PLACEHOLDER — cutover date]. R15 ends with them. R3 stays High because the backup work reached the |
| 421 | [VERIFY] | requires consultation with the competent authority, and on what date it must end [VERIFY]. |
| 431 | [PLACEHOLDER — owner + clinical governance] | **0a** \| **Decide and date the cutover** that replaces the dmc-im.com code with the Laravel app — the single m… |
| 431 | [PLACEHOLDER] | **0a** \| **Decide and date the cutover** that replaces the dmc-im.com code with the Laravel app — the single m… |
| 432 | [PLACEHOLDER — DPO + owner] | **0b** \| **Sign the controller–processor contract** with the operator company, with the Implementing Regulatio… |
| 432 | [PLACEHOLDER] | **0b** \| **Sign the controller–processor contract** with the operator company, with the Implementing Regulatio… |
| 433 | [PLACEHOLDER — processor] | **0c** \| Backup, retention and deletion path for the **legacy** daily database while it remains the record; ob… |
| 433 | [PLACEHOLDER] | **0c** \| Backup, retention and deletion path for the **legacy** daily database while it remains the record; ob… |
| 434 | [PLACEHOLDER] | 1 \| Automated encrypted off-box backups, ninety-day retention, tested restore, stale-backup alert \| R3, R10 \| … |
| 434 | [PLACEHOLDER] | 1 \| Automated encrypted off-box backups, ninety-day retention, tested restore, stale-backup alert \| R3, R10 \| … |
| 435 | [PLACEHOLDER] | 2 \| Extend encryption at rest beyond the four narrative columns (approach to be chosen: volume-level with cust… |
| 435 | [PLACEHOLDER] | 2 \| Extend encryption at rest beyond the four narrative columns (approach to be chosen: volume-level with cust… |
| 436 | [PLACEHOLDER] | 3 \| Incident-response runbook adopted and exercised — **covering both systems**, including who responds to an … |
| 436 | [PLACEHOLDER] | 3 \| Incident-response runbook adopted and exercised — **covering both systems**, including who responds to an … |
| 437 | [PLACEHOLDER] | 4 \| Privacy notice, DPO appointment, sub-processor DPAs (SiteGround, OCI, Cloudflare) \| R5, R7, R14, transpare… |
| 437 | [PLACEHOLDER] | 4 \| Privacy notice, DPO appointment, sub-processor DPAs (SiteGround, OCI, Cloudflare) \| R5, R7, R14, transpare… |
| 438 | [PLACEHOLDER — legal] | 5 \| Legal finding on Cloudflare edge as a transfer; configure regional services or re-architect \| R5 \| [PLACEH… |
| 438 | [PLACEHOLDER] | 5 \| Legal finding on Cloudflare edge as a transfer; configure regional services or re-architect \| R5 \| [PLACEH… |
| 439 | [PLACEHOLDER — legal + engineering] | 6 \| Confirm clinical retention period; implement retention schedule \| R11 \| [PLACEHOLDER — legal + engineering… |
| 439 | [PLACEHOLDER] | 6 \| Confirm clinical retention period; implement retention schedule \| R11 \| [PLACEHOLDER — legal + engineering… |
| 439 | [NEEDS LEGAL CONFIRMATION] | 6 \| Confirm clinical retention period; implement retention schedule \| R11 \| [PLACEHOLDER — legal + engineering… |
| 440 | [PLACEHOLDER] | 7 \| Schedule `audit:prune` dry-run; backfill pre-chain hashes; restore-reconciliation step \| R8, R11 \| [PLACEH… |
| 440 | [PLACEHOLDER] | 7 \| Schedule `audit:prune` dry-run; backfill pre-chain hashes; restore-reconciliation step \| R8, R11 \| [PLACEH… |
| 441 | [PLACEHOLDER] | 8 \| Audit the statistics and audit-log exports; decide default for `log_record_opens` \| R6, R12 \| [PLACEHOLDER… |
| 441 | [PLACEHOLDER] | 8 \| Audit the statistics and audit-log exports; decide default for `log_record_opens` \| R6, R12 \| [PLACEHOLDER… |
| 442 | [PLACEHOLDER] | 9 \| Timezone/clock assertion in `/health`; align MySQL container zone \| R2 \| [PLACEHOLDER] \| [PLACEHOLDER] \| O… |
| 442 | [PLACEHOLDER] | 9 \| Timezone/clock assertion in `/health`; align MySQL container zone \| R2 \| [PLACEHOLDER] \| [PLACEHOLDER] \| O… |
| 443 | [PLACEHOLDER — HR/clinical governance] | 10 \| Quarterly access review; staff confidentiality training \| R4, R6 \| [PLACEHOLDER — HR/clinical governance]… |
| 443 | [PLACEHOLDER] | 10 \| Quarterly access review; staff confidentiality training \| R4, R6 \| [PLACEHOLDER — HR/clinical governance]… |
| 444 | [PLACEHOLDER] | 11 \| Patch/reboot cadence; SSH source restriction (deferred by the owner); **make the repository private befor… |
| 444 | [PLACEHOLDER] | 11 \| Patch/reboot cadence; SSH source restriction (deferred by the owner); **make the repository private befor… |
| 445 | [PLACEHOLDER] | 12 \| Sweeps for expired `pending_registrations`, `trusted_devices`, `password_reset_tokens` \| R11 \| [PLACEHOLD… |
| 445 | [PLACEHOLDER] | 12 \| Sweeps for expired `pending_registrations`, `trusted_devices`, `password_reset_tokens` \| R11 \| [PLACEHOLD… |
| 446 | [PLACEHOLDER — processor] | 13 \| Inventory and destroy the PHI copies listed in CONFIRMED-FACTS D1 (operator workstations; the plaintext d… |
| 446 | [PLACEHOLDER] | 13 \| Inventory and destroy the PHI copies listed in CONFIRMED-FACTS D1 (operator workstations; the plaintext d… |
| 447 | [PLACEHOLDER — HR + processor] | 14 \| Joiner/leaver procedure and quarterly access review **spanning both systems' account sets** \| R4, R11 \| [… |
| 447 | [PLACEHOLDER] | 14 \| Joiner/leaver procedure and quarterly access review **spanning both systems' account sets** \| R4, R11 \| [… |
| 455 | [PLACEHOLDER] | DPO / legal \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 455 | [PLACEHOLDER] | DPO / legal \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 456 | [PLACEHOLDER] | Clinical governance (Head of IM) \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 456 | [PLACEHOLDER] | Clinical governance (Head of IM) \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 457 | [PLACEHOLDER] | IT / information security \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 457 | [PLACEHOLDER] | IT / information security \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 458 | [PLACEHOLDER] | Staff representatives (as users) \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 458 | [PLACEHOLDER] | Staff representatives (as users) \| [PLACEHOLDER] \| [PLACEHOLDER] \| |
| 459 | [PLACEHOLDER] | Patients / patient-experience office \| [PLACEHOLDER] \| [PLACEHOLDER] \| Whether a patient view is needed given … |
| 459 | [PLACEHOLDER] | Patients / patient-experience office \| [PLACEHOLDER] \| [PLACEHOLDER] \| Whether a patient view is needed given … |
| 460 | [VERIFY whether required] | Competent authority (prior consultation) \| [VERIFY whether required] \| \| |
| 468 | [PLACEHOLDER] | Data Protection Officer \| [PLACEHOLDER] \| \| \| |
| 469 | [PLACEHOLDER] | Clinical owner \| [PLACEHOLDER] \| \| \| |
| 470 | [PLACEHOLDER] | System owner \| [PLACEHOLDER] \| \| \| |
| 471 | [PLACEHOLDER] | Information-security lead \| [PLACEHOLDER] \| \| \| |
| 472 | [PLACEHOLDER] | Executive sponsor \| [PLACEHOLDER] \| \| \| |
| 474 | [PLACEHOLDER] | Conditions attached to acceptance (if any): [PLACEHOLDER] |
| 480 | [PLACEHOLDER — cutover date agreed at sign-off] | interim** [PLACEHOLDER — cutover date agreed at sign-off]. |
| 489 | [VERIFY any mandated review frequency] | incident (INCIDENT-RESPONSE.md); and at least annually [VERIFY any mandated review frequency]. |
| 490 | [PLACEHOLDER] | Next review: [PLACEHOLDER]. |

## `DPO.md` — 22 items

| Line | Marker | Context |
|---:|---|---|
| 7 | [VERIFY ARTICLE] | > marker is an open item: `[VERIFY ARTICLE]` means the obligation is described in words and the |
| 9 | [PLACEHOLDER] | > and cited by legal counsel; `[PLACEHOLDER]` means a fact only the hospital can supply. |
| 20 | [VERIFY ARTICLE] | involves processing **sensitive** personal data [VERIFY ARTICLE]. Health data is sensitive personal data |
| 22 | [NEEDS LEGAL CONFIRMATION] | assume it is in scope unless counsel concludes otherwise [NEEDS LEGAL CONFIRMATION]. |
| 36 | [VERIFY ARTICLE] | Basis of appointment \| Internal designation (employee) **or** external service contract [CHOOSE]. \| Either is … |
| 37 | [VERIFY whether the regulations or SDAIA guidance prescribe minimum qualifications or training] | Qualifications \| Working knowledge of the PDPL, its Implementing Regulations and the Data Transfer Regulations… |
| 46 | [VERIFY ARTICLE for each row] | [VERIFY ARTICLE for each row]. |
| 54 | [VERIFY ARTICLE — when a DPIA is mandatory] | R5 \| **Data-protection impact assessment** where the regulations require one for high-risk processing (sensiti… |
| 55 | [VERIFY PERIOD] | R6 \| **Data-subject requests** — receive, verify, coordinate and answer within the period the regulations set.… |
| 56 | [VERIFY ARTICLE — notification period and thresholds] | R7 \| **Breach management** — assess, contain, document, and notify the competent authority and (where required… |
| 60 | [VERIFY — National Data Governance Platform registration requirement] | R11 \| **Point of contact** for the competent authority and for data subjects; keep the hospital's registration… |
| 66 | [VERIFY ARTICLE] | the competent authority** [VERIFY ARTICLE]. For the hub: |
| 74 | [VERIFY] | Competent authority (SDAIA) [VERIFY] \| Controller registration + DPO details, updated on any change. |
| 80 | [VERIFY ARTICLE] | [VERIFY ARTICLE]. |
| 86 | [NEEDS LEGAL CONFIRMATION] | reviewed for conflict [NEEDS LEGAL CONFIRMATION]. |
| 98 | [NEEDS LEGAL CONFIRMATION] | Confirm audit-log retention (6 y in-app, 7 y immutable archive) and backup retention (90 d) still match policy… |
| 111 | [VERIFY ARTICLE] | Regulations [VERIFY ARTICLE], [HOSPITAL LEGAL NAME] (Commercial Registration / Licence No. |
| 112 | [PLACEHOLDER] | [PLACEHOLDER]), as controller, hereby designates: |
| 118 | [DATE] | Effective: [DATE] |
| 128 | [VERIFY] | subjects and communicate them to the competent authority [VERIFY]. |
| 148 | [DATE] | Registered: Controller + DPO details filed on the competent authority's platform on [DATE] [VERIFY] |
| 148 | [VERIFY] | Registered: Controller + DPO details filed on the competent authority's platform on [DATE] [VERIFY] |

## `INCIDENT-RESPONSE.md` — 72 items

| Line | Marker | Context |
|---:|---|---|
| 6 | [PLACEHOLDER — Information-security lead] | > Runbook owner: [PLACEHOLDER — Information-security lead]. Exercise this at least twice a year (§12). |
| 15 | [VERIFY — definition in the law / Implementing Regulations] | - A **personal-data breach** is an incident that leads, or may lead, to accidental or unlawful destruction, lo… |
| 38 | [PLACEHOLDER] | **Incident lead** \| [PLACEHOLDER] / [PLACEHOLDER] \| [PLACEHOLDER] \| Declares severity, owns decisions, keeps t… |
| 38 | [PLACEHOLDER] | **Incident lead** \| [PLACEHOLDER] / [PLACEHOLDER] \| [PLACEHOLDER] \| Declares severity, owns decisions, keeps t… |
| 38 | [PLACEHOLDER] | **Incident lead** \| [PLACEHOLDER] / [PLACEHOLDER] \| [PLACEHOLDER] \| Declares severity, owns decisions, keeps t… |
| 39 | [PLACEHOLDER] | **Technical lead** \| [PLACEHOLDER] / [PLACEHOLDER] \| [PLACEHOLDER] \| Executes containment and recovery (§7, §1… |
| 39 | [PLACEHOLDER] | **Technical lead** \| [PLACEHOLDER] / [PLACEHOLDER] \| [PLACEHOLDER] \| Executes containment and recovery (§7, §1… |
| 39 | [PLACEHOLDER] | **Technical lead** \| [PLACEHOLDER] / [PLACEHOLDER] \| [PLACEHOLDER] \| Executes containment and recovery (§7, §1… |
| 40 | [PLACEHOLDER] | **Communications** \| [PLACEHOLDER] \| [PLACEHOLDER] \| Internal updates, staff notices, patient notices, media (… |
| 40 | [PLACEHOLDER] | **Communications** \| [PLACEHOLDER] \| [PLACEHOLDER] \| Internal updates, staff notices, patient notices, media (… |
| 41 | [PLACEHOLDER] | **DPO / legal** \| [PLACEHOLDER] \| [PLACEHOLDER] \| Breach determination, regulator and data-subject notificatio… |
| 41 | [PLACEHOLDER] | **DPO / legal** \| [PLACEHOLDER] \| [PLACEHOLDER] \| Breach determination, regulator and data-subject notificatio… |
| 42 | [PLACEHOLDER — Head of IM] | **Clinical owner** \| [PLACEHOLDER — Head of IM] \| [PLACEHOLDER] \| Clinical-safety decisions (paper fallback, r… |
| 42 | [PLACEHOLDER] | **Clinical owner** \| [PLACEHOLDER — Head of IM] \| [PLACEHOLDER] \| Clinical-safety decisions (paper fallback, r… |
| 43 | [PLACEHOLDER] | **Executive sponsor** \| [PLACEHOLDER] \| [PLACEHOLDER] \| Approves external notifications and major service deci… |
| 43 | [PLACEHOLDER] | **Executive sponsor** \| [PLACEHOLDER] \| [PLACEHOLDER] \| Approves external notifications and major service deci… |
| 62 | [VERIFY plan features] | Cloudflare dashboard \| Traffic spikes, WAF events, origin errors \| Cloudflare zone analytics / security events… |
| 63 | [VERIFY enabled] | OCI console \| Instance health, volume state, unexpected API activity \| OCI Monitoring / Audit service [VERIFY … |
| 64 | [PLACEHOLDER — reporting channel] | People \| Staff reporting odd behaviour, a lost device, a mis-sent e-mail \| [PLACEHOLDER — reporting channel] \|… |
| 72 | [VERIFY whether a DSN is configured in production] | Error tracking (Sentry) \| Optional per DEPLOY-LARAVEL.md §2; [VERIFY whether a DSN is configured in production… |
| 79 | [PLACEHOLDER — phone/chat] | 1. Whoever notices: report to the technical lead **immediately** by [PLACEHOLDER — phone/chat]. Do not investi… |
| 82 | [VERIFY the exact trigger] | 4. If **any** personal data may have been exposed, altered or lost: inform the DPO now, not after containment.… |
| 106 | [PLACEHOLDER — e.g. a shared document outside the OCI tenancy] | Keep it in a place that survives the incident: [PLACEHOLDER — e.g. a shared document outside the OCI tenancy].… |
| 116 | [VERIFY `SESSION_DRIVER` in the live `.env`] | Production uses the **file** session driver [VERIFY `SESSION_DRIVER` in the live `.env`]: |
| 135 | [VERIFY — confirm `active` is re-checked per request, otherwise also run §7.1] | 1. Control → Users → open the user → untick **Active** → Save (`PUT /control/users/{id}`, audited as `user.upd… |
| 145 | [VERIFY the rule set] | - **Confirm the origin still only accepts Cloudflare**: the OCI security list / NSG must allow HTTPS only from… |
| 153 | [PLACEHOLDER — engineering script] | `APP_KEY` \| App `.env` / Coolify env \| Generate a new key **only** with a re-encryption plan: decrypt `users.m… |
| 177 | [VERIFY log availability] | 2. Note: **statistics exports and the audit-log export are not audited** — check web-server / Cloudflare acces… |
| 199 | [VERIFY plan retention] | **Cloudflare** \| Export security events / analytics for the window [VERIFY plan retention]; note any rule chan… |
| 200 | [VERIFY enabled] | **OCI Audit** \| Export the tenancy audit events for the window (console / API changes, key creation) [VERIFY e… |
| 201 | [VERIFY availability] | **E-mail** \| Preserve the relay provider's send logs for the window [VERIFY availability]; preserve affected m… |
| 212 | [VERIFY throughout] | ### 9.1 Who must be told, and when [VERIFY throughout] |
| 216 | [VERIFY ARTICLE in the Implementing Regulations, the exact wording of the trigger ("becoming aware" vs "confirming"), whether the period runs in calendar hours, whether phased/partial notification is allowed, and the required content] | **Competent authority — SDAIA** (Saudi Data & AI Authority, the personal-data regulator) \| A personal-data bre… |
| 216 | [PLACEHOLDER — URL / reference number procedure] | **Competent authority — SDAIA** (Saudi Data & AI Authority, the personal-data regulator) \| A personal-data bre… |
| 217 | [VERIFY the harm threshold and any exceptions] | **Affected data subjects** (patients, staff) \| Where the breach may cause harm to the data subject or their in… |
| 217 | [VERIFY exact wording and whether a fixed period applies] | **Affected data subjects** (patients, staff) \| Where the breach may cause harm to the data subject or their in… |
| 217 | [PLACEHOLDER] | **Affected data subjects** (patients, staff) \| Where the breach may cause harm to the data subject or their in… |
| 218 | [VERIFY applicability, thresholds and channels — e.g. MoH incident reporting, CHI/NPHIES obligations, accreditation body requirements] | **Ministry of Health** and/or the **Council of Health Insurance** and any health-sector regulator or accredito… |
| 218 | [VERIFY] | **Ministry of Health** and/or the **Council of Health Insurance** and any health-sector regulator or accredito… |
| 218 | [PLACEHOLDER] | **Ministry of Health** and/or the **Council of Health Insurance** and any health-sector regulator or accredito… |
| 219 | [VERIFY scope and reporting duty] | **National Cybersecurity Authority (NCA)** or sector CERT \| Cybersecurity incidents where the hospital is with… |
| 219 | [VERIFY] | **National Cybersecurity Authority (NCA)** or sector CERT \| Cybersecurity incidents where the hospital is with… |
| 219 | [PLACEHOLDER] | **National Cybersecurity Authority (NCA)** or sector CERT \| Cybersecurity incidents where the hospital is with… |
| 220 | [PLACEHOLDER] | **Processors** (OCI, Cloudflare, SMTP relay, GitHub) \| When their service is involved or their cooperation is … |
| 221 | [VERIFY] | **Cyber-insurance / legal counsel** \| Per policy [VERIFY] \| Per policy \| [PLACEHOLDER] \| Executive sponsor |
| 221 | [PLACEHOLDER] | **Cyber-insurance / legal counsel** \| Per policy [VERIFY] \| Per policy \| [PLACEHOLDER] \| Executive sponsor |
| 250 | [PLACEHOLDER] | Use the authority's own form where one exists [PLACEHOLDER]; this template ensures the content is ready. Headi… |
| 257 | [PLACEHOLDER legal name, registration, address] | Controller: [PLACEHOLDER legal name, registration, address] |
| 258 | [PLACEHOLDER name, e-mail, phone] | DPO: [PLACEHOLDER name, e-mail, phone] |
| 299 | [PLACEHOLDER — DPO contact, hours, language options] | للتواصل / How to contact us: [PLACEHOLDER — DPO contact, hours, language options] |
| 302 | [PLACEHOLDER] | Clinical sensitivity: where diagnoses, TB status, resuscitation status or a death outcome were exposed, the cl… |
| 310 | [PLACEHOLDER] | Media / public \| Only with executive sponsor + legal approval \| Communications \| Holding statement [PLACEHOLDE… |
| 320 | [PLACEHOLDER — clinical owner to define] | - The unit's paper census / whiteboard procedure [PLACEHOLDER — clinical owner to define]; the printed handove… |
| 395 | [PLACEHOLDER] | 1 \| \| \| [PLACEHOLDER] \| [PLACEHOLDER] \| \| Open \| |
| 395 | [PLACEHOLDER] | 1 \| \| \| [PLACEHOLDER] \| [PLACEHOLDER] \| \| Open \| |
| 397 | [PLACEHOLDER — meeting] | Rules: every action has one owner and one date; "verified" means someone other than the owner checked it works… |
| 405 | [PLACEHOLDER — Q4 2026] | [PLACEHOLDER — Q4 2026] \| **PHI exfiltration by a compromised admin**: a burst of `login.failed` then `login.s… |
| 406 | [PLACEHOLDER — Q1 2027] | [PLACEHOLDER — Q1 2027] \| **Ransomware / host loss**: the instance is encrypted; latest manual dump is nine da… |
| 407 | [PLACEHOLDER — Q2 2027] | [PLACEHOLDER — Q2 2027] \| **Audit-chain break at 02:30**: `audit.integrity_failure` fires; cause unknown \| Tec… |
| 408 | [PLACEHOLDER — Q3 2027] | [PLACEHOLDER — Q3 2027] \| **Auth bypass report**: a staff member reports seeing another consultant's inbox \| F… |
| 420 | [PLACEHOLDER] | Incident lead \| [PLACEHOLDER] \| \| \| |
| 421 | [PLACEHOLDER] | Technical lead \| [PLACEHOLDER] \| \| \| |
| 422 | [PLACEHOLDER] | DPO / legal \| [PLACEHOLDER] \| \| \| |
| 423 | [PLACEHOLDER] | Communications \| [PLACEHOLDER] \| \| \| |
| 424 | [PLACEHOLDER] | Clinical owner \| [PLACEHOLDER] \| \| \| |
| 425 | [PLACEHOLDER] | Executive sponsor \| [PLACEHOLDER] \| \| \| |
| 426 | [PLACEHOLDER — tenancy, support tier] | OCI support \| [PLACEHOLDER — tenancy, support tier] \| \| \| |
| 427 | [PLACEHOLDER — plan, account] | Cloudflare support \| [PLACEHOLDER — plan, account] \| \| \| |
| 428 | [PLACEHOLDER] | SMTP relay support \| [PLACEHOLDER] \| \| \| |
| 429 | [PLACEHOLDER] | Competent authority (SDAIA) breach portal \| [PLACEHOLDER] \| \| \| |
| 430 | [PLACEHOLDER] | Sector regulator(s) \| [PLACEHOLDER] \| \| \| |
| 458 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Initial draft \| [PLACEHOLDER] |

## `PRIVACY-NOTICE.ar.md` — 24 items

| Line | Marker | Context |
|---:|---|---|
| 14 | [تحقّق من رقم المادة] | > العلامات الموضوعة بين قوسين مربعين — `[تحقّق من رقم المادة]`، `[تحقّق …]`، `[يتطلب تأكيداً قانونياً]`، `[… —… |
| 14 | [تحقّق …] | > العلامات الموضوعة بين قوسين مربعين — `[تحقّق من رقم المادة]`، `[تحقّق …]`، `[يتطلب تأكيداً قانونياً]`، `[… —… |
| 14 | [يتطلب تأكيداً قانونياً] | > العلامات الموضوعة بين قوسين مربعين — `[تحقّق من رقم المادة]`، `[تحقّق …]`، `[يتطلب تأكيداً قانونياً]`، `[… —… |
| 26 | [تحقّق من المرجع — المرسوم الملكي رقم م/19 لعام 1443هـ وتعديلاته] | لا يسجّل المرضى الدخول إلى المنصة ولا يستخدمونها مباشرة. وقد كُتب هذا الإشعار لك لأن بياناتك تُعالج فيها، ولأن… |
| 62 | [تحقّق من رقم المادة] | **الأساس النظامي:** يجيز النظام معالجة البيانات الصحية دون الحاجة إلى موافقتك متى كانت المعالجة تُجرى من جهة ت… |
| 62 | [تحقّق من رقم المادة] | **الأساس النظامي:** يجيز النظام معالجة البيانات الصحية دون الحاجة إلى موافقتك متى كانت المعالجة تُجرى من جهة ت… |
| 62 | [يتطلب تأكيداً قانونياً] | **الأساس النظامي:** يجيز النظام معالجة البيانات الصحية دون الحاجة إلى موافقتك متى كانت المعالجة تُجرى من جهة ت… |
| 62 | [يتطلب تأكيداً قانونياً] | **الأساس النظامي:** يجيز النظام معالجة البيانات الصحية دون الحاجة إلى موافقتك متى كانت المعالجة تُجرى من جهة ت… |
| 69 | [يتطلب تأكيداً قانونياً] | - الجهات العامة — كوزارة الصحة أو جهة تنظيمية أو المحكمة — وذلك فقط حين يوجب النظام الإفصاح أو يجيزه [يتطلب تأ… |
| 80 | [يتطلب تأكيداً قانونياً — التوطين التعاقدي] | كلاودفلير (Cloudflare) \| يقع أمام الموقع لصدّ الهجمات وتوفير الاتصال الآمن (TLS)، ويرى حركة البيانات لحظياً عن… |
| 84 | [تحقّق من رقم المادة] | **نقل البيانات خارج المملكة:** لا ننقل بيانات المرضى خارج المملكة لتخزينها أو معالجتها. ومقدّم الخدمة الوحيد خ… |
| 84 | [يتطلب تأكيداً قانونياً] | **نقل البيانات خارج المملكة:** لا ننقل بيانات المرضى خارج المملكة لتخزينها أو معالجتها. ومقدّم الخدمة الوحيد خ… |
| 90 | [يتطلب تأكيداً قانونياً — مدة الحفظ المطبّقة] | سجلات التنويم والاستشارات والتسليم \| طوال المدة التي تقتضيها قواعد حفظ السجلات الطبية في المستشفى، لأن سجلات ا… |
| 91 | [يتطلب تأكيداً قانونياً] | سجل الوصول (سجل التدقيق) \| ست سنوات داخل المنصة؛ وتُحفظ أيضاً نسخة مقاومة للعبث في وحدة تخزين غير قابلة للتعدي… |
| 92 | [يتطلب تأكيداً قانونياً] | النسخ الاحتياطية \| تسعون يوماً ثم تُستبدل [يتطلب تأكيداً قانونياً]. |
| 113 | [تحقّق من رقم المادة] | يمنحك النظام حقوقاً على بياناتك الشخصية. ومع مراعاة الشروط والاستثناءات الواردة فيه [تحقّق من رقم المادة]، يحق… |
| 118 | [يتطلب تأكيداً قانونياً] | - طلب إتلاف بياناتك متى انتفت الحاجة إليها للغرض الذي جُمعت من أجله — مع ملاحظة أن السجلات السريرية يجب عادةً … |
| 119 | [يتطلب تأكيداً قانونياً] | - سحب الموافقة متى كانت الموافقة هي الأساس النظامي (ولا ينطبق ذلك على الغرض السريري الأساسي الموضح أعلاه) [يتط… |
| 122 | [تحقّق من المدة] | **كيفية ممارسة حقوقك:** تتولى إدارة السجلات الطبية / إدارة المعلومات الصحية في المستشفى معالجة الطلبات، فهي ال… |
| 122 | [تحقّق] | **كيفية ممارسة حقوقك:** تتولى إدارة السجلات الطبية / إدارة المعلومات الصحية في المستشفى معالجة الطلبات، فهي ال… |
| 122 | [يتطلب تأكيداً قانونياً] | **كيفية ممارسة حقوقك:** تتولى إدارة السجلات الطبية / إدارة المعلومات الصحية في المستشفى معالجة الطلبات، فهي ال… |
| 126 | [تحقّق — تأكيد الجهة المختصة الحالية وقناة الشكاوى] | إذا لم تكن راضياً عن طريقة تعاملنا مع بياناتك أو طلبك، فيرجى التواصل أولاً مع مسؤول حماية البيانات لدينا (بيان… |
| 144 | [تحقّق من رقم المادة] | 1 \| التحقق من مرجع النظام ومن كل إشارة `[تحقّق من رقم المادة]` (أساس معالجة البيانات الحساسة لجهات تقديم الخدم… |
| 150 | [يُستكمل] | 7 \| استكمال كل `[يُستكمل]`: الاسم القانوني لجهة التحكم وعنوانها وترخيصها، وبيانات مسؤول حماية البيانات، وبيانا… |

## `PRIVACY-NOTICE.en.md` — 24 items

| Line | Marker | Context |
|---:|---|---|
| 14 | [VERIFY ARTICLE] | > Square-bracketed markers — `[VERIFY ARTICLE]`, `[VERIFY …]`, `[NEEDS LEGAL CONFIRMATION]`, `[… — PLACEHOLDER… |
| 14 | [VERIFY …] | > Square-bracketed markers — `[VERIFY ARTICLE]`, `[VERIFY …]`, `[NEEDS LEGAL CONFIRMATION]`, `[… — PLACEHOLDER… |
| 14 | [NEEDS LEGAL CONFIRMATION] | > Square-bracketed markers — `[VERIFY ARTICLE]`, `[VERIFY …]`, `[NEEDS LEGAL CONFIRMATION]`, `[… — PLACEHOLDER… |
| 24 | [VERIFY CITATION — Royal Decree M/19 of 1443H, as amended] | Patients do not log in to the hub and do not use it directly. This notice is written for you because informati… |
| 60 | [VERIFY ARTICLE] | **Legal basis.** Under the PDPL, health data may be processed without your consent where the processing is car… |
| 60 | [VERIFY ARTICLE] | **Legal basis.** Under the PDPL, health data may be processed without your consent where the processing is car… |
| 60 | [NEEDS LEGAL CONFIRMATION] | **Legal basis.** Under the PDPL, health data may be processed without your consent where the processing is car… |
| 60 | [NEEDS LEGAL CONFIRMATION] | **Legal basis.** Under the PDPL, health data may be processed without your consent where the processing is car… |
| 67 | [NEEDS LEGAL CONFIRMATION] | - Public authorities — such as the Ministry of Health, a regulator or a court — only where the law requires or… |
| 78 | [NEEDS LEGAL CONFIRMATION — contractual localisation] | Cloudflare \| Sits in front of the website to block attacks and to provide the secure (TLS) connection. It sees… |
| 82 | [VERIFY ARTICLE] | **Transfers outside the Kingdom.** We do not transfer your patient data outside the Kingdom to store or proces… |
| 82 | [NEEDS LEGAL CONFIRMATION] | **Transfers outside the Kingdom.** We do not transfer your patient data outside the Kingdom to store or proces… |
| 88 | [NEEDS LEGAL CONFIRMATION — applicable retention period] | Admission, consultation and handover records \| As long as the hospital’s medical-record retention rules requir… |
| 89 | [NEEDS LEGAL CONFIRMATION] | Access trail (audit log) \| Six years inside the hub; a tamper-evident copy is also kept in immutable storage i… |
| 90 | [NEEDS LEGAL CONFIRMATION] | Backups \| Ninety days, then overwritten [NEEDS LEGAL CONFIRMATION]. |
| 111 | [VERIFY ARTICLE] | The PDPL gives you rights over your personal data. Subject to the conditions and exceptions in the law [VERIFY… |
| 116 | [NEEDS LEGAL CONFIRMATION] | - ask for your data to be destroyed when it is no longer needed for the purpose it was collected for — noting … |
| 117 | [NEEDS LEGAL CONFIRMATION] | - withdraw consent where consent is the legal basis (this does not apply to the core clinical purpose describe… |
| 120 | [VERIFY PERIOD] | **How to exercise them.** Requests are handled by the hospital’s Medical Records / Health Information Manageme… |
| 120 | [VERIFY] | **How to exercise them.** Requests are handled by the hospital’s Medical Records / Health Information Manageme… |
| 120 | [NEEDS LEGAL CONFIRMATION] | **How to exercise them.** Requests are handled by the hospital’s Medical Records / Health Information Manageme… |
| 124 | [VERIFY — confirm the current competent authority and its complaint channel] | If you are unhappy with how we handle your data or your request, please contact our Data Protection Officer fi… |
| 142 | [VERIFY ARTICLE] | 1 \| Confirm the PDPL citation and every `[VERIFY ARTICLE]` reference (sensitive-data basis for health-service … |
| 148 | [PLACEHOLDER] | 7 \| Fill every `[PLACEHOLDER]`: controller legal name/address/licence, DPO contact, HIM office contact, patien… |

## `ROPA.md` — 134 items

| Line | Marker | Context |
|---:|---|---|
| 9 | [PLACEHOLDER — DPO] | > Review owner: [PLACEHOLDER — DPO]. Next review: [PLACEHOLDER — date, at most annually or on any change to th… |
| 9 | [PLACEHOLDER — date, at most annually or on any change to the activities below] | > Review owner: [PLACEHOLDER — DPO]. Next review: [PLACEHOLDER — date, at most annually or on any change to th… |
| 16 | [VERIFY ARTICLE — definition of sensitive data including health data] | [VERIFY ARTICLE — definition of sensitive data including health data], so every activity below |
| 31 | [VERIFY] | **[VERIFY]** \| A legal or regulatory statement that must be confirmed against the current text of the law/regu… |
| 32 | [NEEDS LEGAL CONFIRMATION] | **[NEEDS LEGAL CONFIRMATION]** \| A retention period or obligation that the hospital's legal function must set;… |
| 33 | [PLACEHOLDER] | **[PLACEHOLDER]** \| A name, contact, date or contractual reference the owner must fill in. `[COMPANY LEGAL NAM… |
| 35 | [VERIFY] | **SENSITIVE** \| Health data or other sensitive-category data under the law [VERIFY]. |
| 44 | [VERIFY — registering entity and public-entity status] | **Controller** (legal entity) \| **Dammam Medical Complex (DMC)**, Dammam, Eastern Province — under the **Easte… |
| 44 | [PLACEHOLDER] | **Controller** (legal entity) \| **Dammam Medical Complex (DMC)**, Dammam, Eastern Province — under the **Easte… |
| 45 | [PLACEHOLDER — COMPANY LEGAL NAME, Commercial Registration No., city] | **Processor** (primary) \| **[COMPANY LEGAL NAME] — the developer/operator company**, together with the individ… |
| 47 | [PLACEHOLDER] | Data Protection Officer \| **Not appointed yet**; treated as mandatory because the core activity is processing … |
| 48 | [PLACEHOLDER — NAME] | Clinical owner of the hub \| Head of Internal Medicine, DMC — [PLACEHOLDER — NAME] \| [PLACEHOLDER] |
| 48 | [PLACEHOLDER] | Clinical owner of the hub \| Head of Internal Medicine, DMC — [PLACEHOLDER — NAME] \| [PLACEHOLDER] |
| 49 | [PLACEHOLDER — NAME/OFFICE] | Staff-data owner \| DMC hospital administration — [PLACEHOLDER — NAME/OFFICE] \| [PLACEHOLDER] |
| 49 | [PLACEHOLDER] | Staff-data owner \| DMC hospital administration — [PLACEHOLDER — NAME/OFFICE] \| [PLACEHOLDER] |
| 50 | [PLACEHOLDER] | System owner (application) \| The developer/owner — **on the processor side**, not hospital IT \| [PLACEHOLDER] |
| 51 | [PLACEHOLDER] | Information-security lead \| The developer/owner — **on the processor side** \| [PLACEHOLDER] |
| 52 | [PLACEHOLDER — NAME] | Approving body \| DMC clinical governance / quality committee — [PLACEHOLDER — NAME] \| [PLACEHOLDER] |
| 52 | [PLACEHOLDER] | Approving body \| DMC clinical governance / quality committee — [PLACEHOLDER — NAME] \| [PLACEHOLDER] |
| 84 | [VERIFY] | Component \| Provider / location \| Role under the law [VERIFY] \| Personal data it can see \| Safeguard in place … |
| 86 | [VERIFY] | **Operator company — [COMPANY LEGAL NAME]** (and the individual developer/owner) \| Wherever the company and it… |
| 87 | [VERIFY the same account carries the web hosting] | **Legacy PHP app + its MySQL database — `www.dmc-im.com` (the DAILY system)** \| **SiteGround shared hosting, U… |
| 87 | [PLACEHOLDER — SiteGround contracting entity, DPA, transfer safeguard] | **Legacy PHP app + its MySQL database — `www.dmc-im.com` (the DAILY system)** \| **SiteGround shared hosting, U… |
| 88 | [VERIFY firewall rule] | Laravel application + MySQL 8.4 (Docker) on one compute instance \| Oracle Cloud Infrastructure, region **me-ri… |
| 88 | [PLACEHOLDER — OCI DPA / order] | Laravel application + MySQL 8.4 (Docker) on one compute instance \| Oracle Cloud Infrastructure, region **me-ri… |
| 89 | [PLACEHOLDER] | Audit-log archive bucket (S3-compatible object storage, WORM, seven-year lock) \| OCI Object Storage, region de… |
| 90 | [PLACEHOLDER] | Encrypted database backups \| OCI Object Storage bucket `dmc-db-backups`, **in-Kingdom**; local encrypted copie… |
| 91 | [VERIFY — Cloudflare DPA and whether edge decryption constitutes a transfer outside the Kingdom] | Cloudflare (DNS, reverse proxy, TLS termination, WAF) — **Laravel app only** \| Cloudflare, Inc. — **non-Saudi … |
| 92 | [PLACEHOLDER — relay DPA; cross-border transfer safeguard — see §4] | SMTP relay \| **SiteGround** — the `dmc-im.com` mailbox (`mail.dmc-im.com:587` STARTTLS), **United States**, co… |
| 93 | [VERIFY every local copy has been destroyed] | GitHub (source repository) \| GitHub, Inc. — non-Saudi \| Not a processor of personal data by design (code only)… |
| 93 | [PLACEHOLDER] | GitHub (source repository) \| GitHub, Inc. — non-Saudi \| Not a processor of personal data by design (code only)… |
| 94 | [VERIFY] | Coolify (deployment orchestrator) \| Self-hosted on the same OCI instance [VERIFY] \| Internal tooling of the pr… |
| 95 | [VERIFY device policy] | Staff browsers \| Hospital workstations / personal devices [VERIFY device policy] \| End users (controller side)… |
| 102 | [VERIFY] | words; **SENSITIVE** = health data or data revealing a sensitive characteristic [VERIFY]. |
| 118 | [VERIFY] | `patients` (`2026_06_08_120003`, soft-delete `2026_06_14_010007`) \| `mrn` (unique identifier), `name`, `gender… |
| 135 | [VERIFY `SESSION_DRIVER`] | `sessions` table (`0001_01_01_000000`) **or** file sessions in `storage/framework/sessions` (production uses t… |
| 147 | [VERIFY queue driver] | `cache`, `jobs`, `migrations` \| Transient framework state; the queued monthly-report job carries the generated… |
| 161 | [VERIFY] | # \| Activity \| System today → where the data sits \| Purpose (short) \| Legal basis (in words) [VERIFY] \| Data s… |
| 163 | [VERIFY] | A1 \| Admission management \| **Legacy daily** → SiteGround, **US**; also **Laravel parallel** → OCI Riyadh \| Tr… |
| 163 | [NEEDS LEGAL CONFIRMATION] | A1 \| Admission management \| **Legacy daily** → SiteGround, **US**; also **Laravel parallel** → OCI Riyadh \| Tr… |
| 163 | [PLACEHOLDER — Head of IM] | A1 \| Admission management \| **Legacy daily** → SiteGround, **US**; also **Laravel parallel** → OCI Riyadh \| Tr… |
| 164 | [VERIFY] | A2 \| Consultation ledger \| **Laravel source of truth** (since the module's cutover) → OCI Riyadh; historical r… |
| 164 | [NEEDS LEGAL CONFIRMATION] | A2 \| Consultation ledger \| **Laravel source of truth** (since the module's cutover) → OCI Riyadh; historical r… |
| 164 | [PLACEHOLDER] | A2 \| Consultation ledger \| **Laravel source of truth** (since the module's cutover) → OCI Riyadh; historical r… |
| 165 | [VERIFY] | A3 \| Handover \| **Laravel only** → OCI Riyadh; **no legacy equivalent exists** \| Cross-cover narrative, checkp… |
| 165 | [NEEDS LEGAL CONFIRMATION] | A3 \| Handover \| **Laravel only** → OCI Riyadh; **no legacy equivalent exists** \| Cross-cover narrative, checkp… |
| 165 | [PLACEHOLDER] | A3 \| Handover \| **Laravel only** → OCI Riyadh; **no legacy equivalent exists** \| Cross-cover narrative, checkp… |
| 166 | [VERIFY compatibility/statistics provision] | A4 \| Statistics and reporting \| **Laravel parallel** → OCI Riyadh (computed from the parallel copy); the legac… |
| 166 | [PLACEHOLDER] | A4 \| Statistics and reporting \| **Laravel parallel** → OCI Riyadh (computed from the parallel copy); the legac… |
| 167 | [VERIFY] | A5 \| User and account management \| **Both, separately** — the legacy daily system keeps its own accounts (**US… |
| 167 | [NEEDS LEGAL CONFIRMATION] | A5 \| User and account management \| **Both, separately** — the legacy daily system keeps its own accounts (**US… |
| 167 | [PLACEHOLDER — System owner] | A5 \| User and account management \| **Both, separately** — the legacy daily system keeps its own accounts (**US… |
| 168 | [VERIFY — note legitimate interest is not available for sensitive data] | A6 \| Audit logging (incl. break-glass, shipping, verification) \| **Laravel only** → OCI Riyadh + in-Kingdom WO… |
| 168 | [PLACEHOLDER — Security lead] | A6 \| Audit logging (incl. break-glass, shipping, verification) \| **Laravel only** → OCI Riyadh + in-Kingdom WO… |
| 169 | [VERIFY] | A7 \| Backups \| **Laravel only** → in-Kingdom OCI bucket `dmc-db-backups`; **legacy-side backups are whatever S… |
| 169 | [PLACEHOLDER — System owner] | A7 \| Backups \| **Laravel only** → in-Kingdom OCI bucket `dmc-db-backups`; **legacy-side backups are whatever S… |
| 170 | [VERIFY] | A8 \| Transactional e-mail \| **Both** → the same `dmc-im.com` mailbox on SiteGround, **US** \| Deliver OTPs, pas… |
| 170 | [PLACEHOLDER] | A8 \| Transactional e-mail \| **Both** → the same `dmc-im.com` mailbox on SiteGround, **US** \| Deliver OTPs, pas… |
| 171 | [VERIFY] | A9 \| Application and security telemetry \| **Laravel only** → OCI Riyadh (container logs, CSP reports) \| Detect… |
| 171 | [PLACEHOLDER] | A9 \| Application and security telemetry \| **Laravel only** → OCI Riyadh (container logs, CSP reports) \| Detect… |
| 172 | [VERIFY — further-processing/compatibility] | A10 \| **Parallel operation and migration copy** \| **Laravel parallel** → OCI Riyadh, built and refreshed from … |
| 172 | [PLACEHOLDER — cutover date; destruction certificate] | A10 \| **Parallel operation and migration copy** \| **Laravel parallel** → OCI Riyadh, built and refreshed from … |
| 172 | [PLACEHOLDER — System owner + DPO] | A10 \| **Parallel operation and migration copy** \| **Laravel parallel** → OCI Riyadh, built and refreshed from … |
| 182 | [VERIFY ARTICLE — health-data processing conditions; whether consent is additionally required from the patient, and whether the hospital's general admission consent / privacy notice covers this secondary system] | **Legal basis (words)** \| Processing of health data that is necessary for a licensed health-care provider to d… |
| 182 | [VERIFY] | **Legal basis (words)** \| Processing of health data that is necessary for a licensed health-care provider to d… |
| 185 | [VERIFY name] | **Source** \| Entered by staff at admission (from the hospital's primary record system [VERIFY name]); bulk imp… |
| 187 | [VERIFY — transfer safeguard for the legacy hosting] | **Processors / transfers** \| **Daily copy:** SiteGround, **United States** — a transfer outside the Kingdom of… |
| 187 | [VERIFY — transfer classification and safeguard] | **Processors / transfers** \| **Daily copy:** SiteGround, **United States** — a transfer outside the Kingdom of… |
| 188 | [NEEDS LEGAL CONFIRMATION] | **Retention** \| No policy today in **either** system; Laravel rows are never hard-deleted by routine flow (sof… |
| 190 | [VERIFY — other workstream] | **Gaps** \| **The system this activity actually runs on is the un-hardened legacy build on US hosting** — the s… |
| 191 | [PLACEHOLDER — Head of Internal Medicine (clinical); System owner (technical, processor side)] | **Owner** \| [PLACEHOLDER — Head of Internal Medicine (clinical); System owner (technical, processor side)] |
| 201 | [VERIFY] | **Legal basis (words)** \| As A1 [VERIFY]. |
| 206 | [VERIFY] | **Processors / transfers** \| Live ledger: as A1's parallel copy (OCI in-Kingdom; Cloudflare edge [VERIFY]). Fr… |
| 207 | [NEEDS LEGAL CONFIRMATION] | **Retention** \| As A1 [NEEDS LEGAL CONFIRMATION]. Follow-ups are append-only. The **frozen legacy copy of the … |
| 207 | [PLACEHOLDER — DPO] | **Retention** \| As A1 [NEEDS LEGAL CONFIRMATION]. Follow-ups are append-only. The **frozen legacy copy of the … |
| 209 | [PLACEHOLDER — governance instruction] | **Gaps** \| Free-text `response_note` / follow-up `note` can carry more than the minimum — clinical guidance on… |
| 210 | [PLACEHOLDER] | **Owner** \| [PLACEHOLDER] |
| 217 | [VERIFY — extent of live handover use in the parallel period; clinical governance and processor to state] | **System(s) today / where** \| **Laravel app only**, on **OCI Riyadh (in-Kingdom)**. The legacy daily system ha… |
| 220 | [VERIFY] | **Legal basis (words)** \| As A1; additionally the provider's duty of continuity of care and clinical record-ke… |
| 225 | [VERIFY] | **Processors / transfers** \| OCI (in-Kingdom); Cloudflare edge decryption [VERIFY]. No US-hosted copy of hando… |
| 226 | [NEEDS LEGAL CONFIRMATION] | **Retention** \| Revisions and signatures are retained indefinitely by design (append-only). Period [NEEDS LEGA… |
| 228 | [PLACEHOLDER — governance decision on default] | **Gaps** \| `log_record_opens` defaults **OFF** — reads of clinical narrative are unlogged unless an admin enab… |
| 229 | [PLACEHOLDER] | **Owner** \| [PLACEHOLDER] |
| 236 | [VERIFY — which system's numbers management actually receives] | **System(s) today / where** \| **Laravel parallel copy** → OCI Riyadh: every figure described here is computed … |
| 239 | [VERIFY ARTICLE — further-processing compatibility and any statistics/research provision] | **Legal basis (words)** \| Health-service management as A1; statistical/quality purposes as a purpose compatibl… |
| 242 | [PLACEHOLDER — list] | **Recipients** \| Admin role only for registry/statistics/reports/exports/audit (`admin` route group, `routes/w… |
| 243 | [VERIFY endpoint/device controls] | **Processors / transfers** \| Monthly PDF leaves the Kingdom via the **SiteGround** relay in the **United State… |
| 244 | [VERIFY relay retention] | **Retention** \| Exports: no server copy; user-held. PDF: at recipients' mailboxes and at the relay [VERIFY rel… |
| 247 | [PLACEHOLDER] | **Owner** \| [PLACEHOLDER] |
| 254 | [PLACEHOLDER — joiner/leaver procedure covering both systems] | **System(s) today / where** \| **Both systems keep their own, unsynchronised staff accounts.** The legacy daily… |
| 257 | [VERIFY] | **Legal basis (words)** \| Employment / contractual relationship; the controller's obligation to implement secu… |
| 263 | [NEEDS LEGAL CONFIRMATION] | **Retention** \| Accounts: deactivated on departure, retained for attribution (FKs are `nullOnDelete`; soft-del… |
| 263 | [VERIFY] | **Retention** \| Accounts: deactivated on departure, retained for attribution (FKs are `nullOnDelete`; soft-del… |
| 265 | [PLACEHOLDER] | **Gaps** \| Recovery codes in `pending_registrations.totp_recovery_codes` are plaintext for up to thirty minute… |
| 265 | [VERIFY] | **Gaps** \| Recovery codes in `pending_registrations.totp_recovery_codes` are plaintext for up to thirty minute… |
| 266 | [PLACEHOLDER — System owner] | **Owner** \| [PLACEHOLDER — System owner] |
| 273 | [PLACEHOLDER — interim compensating control for the legacy period, security lead] | **System(s) today / where** \| **Laravel app only** — the `audit_log` table on **OCI Riyadh** and the hourly ND… |
| 276 | [VERIFY ARTICLE] | **Legal basis (words)** \| The controller's obligation to keep records enabling accountability and to secure pe… |
| 283 | [VERIFY backfill was run in production] | **Gaps** \| Pre-chain rows written before the hash-chain migration have NULL hashes and are not verifiable unti… |
| 283 | [PLACEHOLDER — governance decision] | **Gaps** \| Pre-chain rows written before the hash-chain migration have NULL hashes and are not verifiable unti… |
| 284 | [PLACEHOLDER — Security lead] | **Owner** \| [PLACEHOLDER — Security lead] |
| 290 | [VERIFY — SiteGround backup scope, retention and location] | **Description** \| **Laravel copy:** a nightly encrypted off-box dump runs to the in-Kingdom OCI bucket `dmc-db… |
| 294 | [VERIFY] | **Legal basis (words)** \| Inherits the bases of the source data; the controller's duty to protect data against… |
| 296 | [PLACEHOLDER — whether the controller should hold an escrowed copy, DPO decision] | **Recipients** \| The operator company's operators only; the hospital has no independent copy [PLACEHOLDER — wh… |
| 298 | [NEEDS LEGAL CONFIRMATION] | **Retention** \| Local encrypted copy two days; bucket **ninety days — a placeholder pending legal** [NEEDS LEG… |
| 298 | [VERIFY] | **Retention** \| Local encrypted copy two days; bucket **ninety days — a placeholder pending legal** [NEEDS LEG… |
| 300 | [PLACEHOLDER — System owner] | **Owner** \| [PLACEHOLDER — System owner]; target date [PLACEHOLDER]. |
| 300 | [PLACEHOLDER] | **Owner** \| [PLACEHOLDER — System owner]; target date [PLACEHOLDER]. |
| 307 | [VERIFY — that legacy mail uses the same mailbox] | **System(s) today / where** \| **Both systems send through the same mailbox.** Production mail leaves the Larav… |
| 310 | [VERIFY] | **Legal basis (words)** \| Employment/contract; security obligation [VERIFY]. |
| 313 | [VERIFY ARTICLE — transfer conditions and whether a US-hosted relay for staff e-mail qualifies; alternative: an in-Kingdom relay] | **Recipients / processors** \| **SiteGround** — the `dmc-im.com` mailbox, **hosted in the United States** → tra… |
| 314 | [VERIFY] | **Retention** \| None locally (messages are not stored; `MAIL_MAILER=log` in non-production writes them to the … |
| 315 | [VERIFY `mail_encryption` value in production] | **Security measures** \| TLS to the relay [VERIFY `mail_encryption` value in production]; encrypted stored cred… |
| 316 | [PLACEHOLDER] | **Owner** \| [PLACEHOLDER] |
| 323 | [VERIFY — legacy log inventory and retention] | **System(s) today / where** \| **Laravel app only**, on **OCI Riyadh** (container logs and the in-app notificat… |
| 326 | [VERIFY] | **Legal basis (words)** \| Security obligation [VERIFY]. |
| 331 | [PLACEHOLDER] | **Owner** \| [PLACEHOLDER] |
| 341 | [VERIFY — whether migration/parallel running needs its own basis or is compatible further processing] | **Legal basis (words)** \| The same bases as the source data: the copy serves the original health-care purpose … |
| 344 | [VERIFY — how dumps are transported and whether they are encrypted in transit] | **Processors / transfers** \| The copy itself stays in-Kingdom. The **source** is outside the Kingdom, so each … |
| 345 | [PLACEHOLDER — cutover date; destruction certificate; DPO] | **Retention** \| **The duplicate ends at cutover**: once the legacy code is replaced, the legacy database and e… |
| 347 | [PLACEHOLDER — owner] | **Gaps** \| The duplicate itself is the gap: it doubles the exposed surface, and the weaker of the two copies i… |
| 348 | [PLACEHOLDER — System owner + DPO] | **Owner** \| [PLACEHOLDER — System owner + DPO] |
| 352 | [VERIFY] | ## 5. Data-subject rights handling [VERIFY] |
| 355 | [VERIFY ARTICLE — enumeration and exceptions for health data / medical records] | [VERIFY ARTICLE — enumeration and exceptions for health data / medical records]. In these systems: |
| 362 | [PLACEHOLDER] | details are [PLACEHOLDER]. |
| 366 | [PLACEHOLDER — procedure] | Access \| Admin registry search by MRN + export in the Laravel app (audited) \| No patient-facing channel; must … |
| 366 | [PLACEHOLDER — procedure covering both systems] | Access \| Admin registry search by MRN + export in the Laravel app (audited) \| No patient-facing channel; must … |
| 367 | [PLACEHOLDER] | Correction \| Modify patient / admission in the Laravel app (audited, `patient.modify`) \| Clinical-record corre… |
| 368 | [NEEDS LEGAL CONFIRMATION] | Destruction \| Soft delete only; hard delete Admin + step-up (Laravel) \| Clinical retention obligations may ove… |
| 368 | [PLACEHOLDER — procedure] | Destruction \| Soft delete only; hard delete Admin + step-up (Laravel) \| Clinical retention obligations may ove… |
| 369 | [PLACEHOLDER — where the notice is published to patients] | Information (privacy notice) \| A privacy notice exists in the Laravel app at `/privacy`; the legacy daily site… |
| 377 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Initial draft from schema and code review \| [PLACEHOLDER] |
| 378 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Reconciled A4 and A6 with CONFIRMED-FACTS C12/C13 as re-verified against the code: every La… |
| 379 | [PLACEHOLDER] | 2026-09-03 \| 0.1 \| Per-activity rework to the confirmed framing: DMC as controller and the operator company as… |

