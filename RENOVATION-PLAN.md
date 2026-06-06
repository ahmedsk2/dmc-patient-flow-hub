# DMC Patient-Flow Hub — Renovation Plan

**Prepared:** 2026-06-06 · **Scope:** full read-only review of the DMC Internal-Medicine
patient-flow web app (PHP 8.3 + MySQL + AdminLTE). **Audience:** hospital leadership/IT
management *and* the technical team. **Status:** proposal — no code was changed in this review.

**Companion documents (in the repo):** [`CLAUDE.md`](CLAUDE.md) (architecture map + ER
diagram), [`REVIEW-FINDINGS.md`](REVIEW-FINDINGS.md) (the full severity-rated findings
register; this plan references its IDs, e.g. **SEC-01**).

---

## 1. Executive summary

### Overall health
The application **works and encodes a real, non-trivial clinical workflow** (admission →
assignment → patient-flow → discharge, consultations, statistics, registry). The data model is
recognizable and the unit clearly depends on it. **But its engineering health is poor and its
security posture is critical.** It is ~20,000 lines of un-frameworked, procedural PHP that mixes
SQL, HTML, and JavaScript in single large files, with **no automated tests, no audit trail, and
no separation between the authorization layer and the data-mutation layer.** It holds **~15,000
real patient records and ~323 user accounts.**

### The biggest risks (lead with these)
> **This system is, as currently configured, exploitable by an unauthenticated attacker over
> the network — including full patient-data theft, tampering, and destruction.** These are not
> theoretical; the relevant code was read and the worst cases re-verified line-by-line.

1. **Broken access control (Critical).** The majority of "action" endpoints (discharge, modify,
   delete, transfer, user management, exports) perform **no authentication or authorization** —
   they can be called directly. Anyone can **read, alter, or delete any patient record**
   ([patients/dmc-patient-delete.php](patients/dmc-patient-delete.php) — **SEC-01**), and anyone
   can **make themselves an Administrator** in one request ([dmc-users-update.php](dmc-users-update.php)
   — **SEC-02**). This is OWASP's #1 risk category. [1]
2. **Pervasive SQL injection (Critical).** Most queries are built by pasting request data into
   SQL strings — the exact pattern OWASP and the PHP manual identify as the root cause of
   injection. [2][4] An unauthenticated, internet-reachable endpoint ([fetchicd10.php](fetchicd10.php)
   — **SEC-12**) alone allows extracting any table, including password hashes.
3. **Data destruction is trivially available (Critical).** Two leftover developer files
   ([reset-testcount.php](reset-testcount.php) — **SEC-05**, [test-trans.php](test-trans.php) —
   **SEC-06**) let anyone wipe discharge dates/assignments or write patient rows, unauthenticated.
4. **No transport security and exposed secrets (Critical).** Traffic is forced to plain
   **HTTP** (**SEC-15**); **live database and email passwords are hardcoded in plaintext** in
   source (**SEC-16/17**). Treat those credentials as already compromised.
5. **No audit trail and no transactions (High).** For a clinical record this is a
   patient-safety and medico-legal gap: there is no reliable record of who changed/deleted what
   (**REL-02**), the "who did it" fields are client-forgeable (**SEC-22**), and multi-step
   clinical writes can half-complete (**REL-01**) on the non-transactional MyISAM tables.

### Headline recommendations
- **Act today, before any code project:** rotate the DB and SMTP passwords; delete the two dev
  endpoints; put the app behind HTTPS and restrict it to the hospital network/VPN; take a
  verified backup. (See **Phase 0**.)
- **First engineering wave (≈4-6 weeks):** a single mandatory server-side **auth guard on every
  endpoint**, convert **every** query to **prepared statements**, **encode all output**, add
  **CSRF tokens** and **session/cookie hardening**, fix the **password-reset** design, and stand
  up an **audit log**. These map 1:1 to OWASP guidance. [1][2][3][6][9]
- **Stabilize (parallel):** delete ~37 MB of committed logs and dead code, **migrate MyISAM →
  InnoDB**, add missing indexes, and fix the **data-entry safety** defects that can attach data
  to the wrong patient (**UX-01/02/03**).
- **Then renovate:** de-duplicate the codebase (35-45% is duplication/boilerplate), rework the
  statistics engine, and overhaul UI/UX, accessibility, and the printed reports.
- **Strategy:** **harden in place first, then migrate incrementally to a modern PHP framework**
  (strangler-fig pattern [25]) — **do not attempt a big-bang rewrite**, and do not defer the
  security fixes waiting for a rewrite. Reasoning in **§6**.

### Bottom line for decision-makers
The unit can keep using the app, but **the Phase 0 containment steps should happen immediately**
and the Phase 1 security work should be resourced as an urgent, time-boxed project. The current
exposure is the kind that leads to a reportable health-data breach. [21][22]

---

## 2. How to read this plan

- **Priority:** P0 (emergency, do now) · P1 (urgent, first wave) · P2 (important) · P3 (nice-to-have).
- **Effort (rough, for a 1-2 dev team):** **S** = hours-2 days · **M** = days-2 weeks ·
  **L** = weeks-months.
- **Finding IDs** (e.g. **SEC-01**) cross-reference [`REVIEW-FINDINGS.md`](REVIEW-FINDINGS.md).
- **Risk of the change itself** is called out where a fix could plausibly break clinical use.
- `[n]` = citation in **§8**. Items marked ✅ were independently verified to a 3-vote bar against
  primary sources during this review; others cite the relevant primary authority.

---

## 3. Recommendations by area

### 3.1 Security — *highest priority*

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk of the change |
|---|---|---|---|---|---|---|
| S1 | No auth on action endpoints; UI-only authz; handlers run before role gate (**SEC-01,04,07–11, SEC-20/21, ROOT-1/4**) | Add a single included **`require_auth.php`** guard at the top of *every* non-public script: `session_start`, validate session, enforce **deny-by-default** role + **object-ownership** checks before any DB work. Centralize the role/capability matrix (from `permissions.docx`). [1][10]✅ | P1 | L | Session hardening (S6) | Medium — must mirror `permissions.docx` exactly or staff lose access; mitigate with a per-endpoint allow-list reviewed with a clinician |
| S2 | SQL injection everywhere (**SEC-07–13, ROOT-2**) | Convert **all** queries to **parameterized prepared statements** (mysqli/PDO); bind every value; allow-list any dynamic identifiers/`ORDER BY`; if PDO, set `ATTR_EMULATE_PREPARES=>false`. Remove all `$_REQUEST` string-interpolation. Prepared statements are OWASP's & PHP's primary defense; `mysqli_real_escape_string` is "STRONGLY DISCOURAGED". [2][3][4][5]✅ | P1 | L | A shared DB helper (C2) | Medium — broad edit surface; do per-module with tests; behavior should be identical |
| S3 | Stored/Reflected XSS of PHI (**SEC-18, ROOT-3**) | Wrap **all** output in `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`; use context-appropriate encoding for JS/URL/attribute contexts. Add **Content-Security-Policy** as defense-in-depth (after removing `eval`, SEC-26). [6][7][8]✅ | P1 | M | Remove `eval` (C-arch) | Low |
| S4 | Privilege escalation (**SEC-02/03**) | Never accept `position`/capability flags from the client for self-service; server-side allow-list of self-registerable roles (never Admin); admin-only, audited role changes. | P1 | S | S1 | Low |
| S5 | Broken password reset; weak hashing config (**SEC-13/14, SEC-30**) | Replace the `md5(hash)` token with a **random 256-bit, single-use, time-limited token stored hashed**; parameterize the reset queries; keep `password_hash()` (bcrypt/Argon2id) and widen `member_password` to `varchar(255)`. [12][14][18] | P1 | M | S2 | Low |
| S6 | Weak sessions/cookies; no CSRF (**SEC-19/23**) | Set `HttpOnly`+`Secure`+`SameSite` on cookies; `session_regenerate_id(true)` on login; idle + absolute timeouts; invalidate the remember-me token on logout. Add **synchronizer CSRF tokens** to all state-changing requests; accept `$_POST` only. [9][13]✅(CSRF) | P1 | M | HTTPS (S8) | Low-Medium — timeouts change UX; pick clinically sensible values |
| S7 | Secrets in code (**SEC-16/17**) | Move DB/SMTP creds to environment/secret store outside web root; **rotate both now**; remove from source and history. | P0/P1 | S | — | Low |
| S8 | No HTTPS / headers (**SEC-15/27**) | Force HTTPS (redirect 80→443), enable **HSTS**; add CSP, X-Frame-Options/`frame-ancestors`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`. [23][24] | P0/P1 | S | TLS cert | Low |
| S9 | Destructive dev endpoints; PHI in logs/debug; abandoned PHPExcel (**SEC-05/06/24/25**) | **Delete** `reset-testcount.php`, `test-trans.php`; remove all `var_dump`/debug echoes; `display_errors=Off`; log outside web root; replace PHPExcel with **PhpSpreadsheet**. | P0/P1 | S-M | — | Low |
| S10 | `$_REQUEST`, `eval`, IDOR object checks (**SEC-26/28, SEC-21**) | Use `$_POST`/`$_GET` deliberately; return **JSON** from dashboard fragments (no `eval`); object-ownership checks on every record action. | P1-P2 | M | S1/S2 | Low |

### 3.2 Reliability & data integrity

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk |
|---|---|---|---|---|---|---|
| R1 | No audit trail; forgeable actor (**REL-02, SEC-22**) | Append-only **audit log** (actor from **session**, action, entity, before/after, timestamp) on every clinical write; never trust client `userid`/`admitted_by`. Aligns with HIPAA audit-controls expectation. [20][21][19] | P1 | M | S1 | Low |
| R2 | No transactions; half-completed transfers (**REL-01/08/09**) | After InnoDB migration (D1), wrap multi-statement writes (transfers, imports, two-phase discharge) in **transactions**; copy `BED`; fix actor attribution. [16] | P1-P2 | M | D1 | Low |
| R3 | Concurrent-edit races; nondeterministic shuffle (**REL-03**) | Targeted `UPDATE … WHERE ID=?` in the shuffle; **optimistic concurrency** (version/`updated_at`) on patient edits. | P2 | M | D1 | Medium — changes assignment behavior; validate with a clinician (**CLIN-05**) |
| R4 | Hard deletes destroy history (**REL-05**) | **Soft-delete** + audit; restrict delete to Admin server-side. | P1-P2 | M | S1, audit (R1) | Low |
| R5 | No backup/DR/migration story (**REL-04**) | Scheduled, **tested** DB backups + retention; adopt a schema-**migrations** tool. | P0/P1 | S-M | — | Low |
| R6 | Inconsistent reverse-discharge / phase-2 dates (**REL-06/07**) | One canonical reverse-discharge; decide `med_DISDATE` vs `DISDATE` semantics. **[NEEDS CLINICAL REVIEW]** | P2 | S | Clinical sign-off | Medium — clinical semantics |

### 3.3 Database

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk |
|---|---|---|---|---|---|---|
| D1 | MyISAM main tables (**DB-01, ROOT-6**) | **Migrate all tables to InnoDB** (utf8mb4) — enables transactions, row-locking, FKs. Standard, documented procedure. [15] | P1 | M | Backup (R5) | Medium — test thoroughly; index/locking behavior changes |
| D2 | No FKs / orphan refs (**DB-02**) | Clean orphans, then add **foreign keys** (consultant/admitted_by/diagnosis). [16] | P2 | M | D1 | Medium — exposes existing bad data; fix data first |
| D3 | Missing indexes; non-sargable stat queries (**DB-03, PERF-02**) | Add indexes on `MRN`, `consultant_id`, `current_location`; rewrite stats to use **range predicates** instead of `MONTH()/YEAR()` on indexed columns (function-wrapping defeats indexes). [17] | P1-P2 | M | D4 (MRN type) | Low |
| D4 | MRN type mismatch; diagnoses as JSON; duplicate tables (**DB-04/05/06**) | Standardize **MRN type** + index; normalize diagnoses to a `patient_diagnoses` join table (keep JSON as cache); merge `speciality`/`other_specialities`; replace `picupatients_temp` with a status flag. **[NEEDS CLINICAL REVIEW: MRN format]** | P2-P3 | L | D1, migrations | Medium — data backfill |
| D5 | Mixed charsets; dead tables (**DB-07/08**) | Standardize utf8mb4; drop `consultation_details`/`Notes` after backup (or implement). | P2-P3 | S-M | D1 | Low |

### 3.4 Code quality & architecture

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk |
|---|---|---|---|---|---|---|
| C1 | No layering; logic in HTML; untestable (**ARCH-01/04, ROOT-5**) | Extract a thin layer: `config.php`, a **DB helper**, **auth guard**, shared **template partials**, and **pure functions** (census, LOS, shuffle-plan, readmission). Add **PHPUnit**; first test target = the shuffle algorithm. | P2 | L | C2 | Low (additive) |
| C2 | Duplicated DB connection + two idioms (**ARCH-02, SIMP-01-DB**) | One connection from `config.php`; one prepared-statement helper used everywhere (folds into S2). | P1 | S-M | S7 | Low |
| C3 | POST-in-page + JS redirects (**ARCH-03**) | Dedicated action endpoints using **POST-Redirect-GET** (`header('Location')`). | P2 | M | S1 | Low |
| C4 | `die()`/raw-error output; naming hazards; latent bugs (**ARCH-05/06/07/08**) | Central error handler + generic messages; planned rename migration (`specilaity`, `discahrge_type`, `$access_PICU_*`); delete dead `Auth::update()`; fix `$isExpiryDate` typo. | P2 | M | — | Low-Medium (renames) |

### 3.5 Simplification (dead code / duplication)

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk |
|---|---|---|---|---|---|---|
| X1 | ~37 MB committed logs (**SIMP-01**) | Delete all `php_errorlog`; `.gitignore`; log outside web root. | P0/P1 | S | — | Low |
| X2 | Patient-card/census/ICD-10 duplicated ~7-13× with N+1 (**SIMP-02**) | Shared `census()`, `render_patient_card()`, cached `load_icd10_map()` (use `tb-patients.php` as the clean template). Centralize the **one** definition of "active" (**CLIN-08**). Est. −1,500-2,500 LOC, kills N+1s. | P2 | L | C1, CLIN-08 | Low-Medium — visible lists change; snapshot-test outputs |
| X3 | Stats interval/readmission blocks duplicated across 9 files; A4 twins (**SIMP-03, PERF-01**) | Shared period/aggregation helpers; merge A4 yearly/monthly; grouped-SQL aggregation. Fixes cross-year bugs in one place (**CLIN-02**). | P2-P3 | L | C1, CLIN-02 | Medium — report numbers must reconcile pre/post |
| X4 | Dead code/weight (**SIMP-04/05**) | Delete empty dashboard fragments, `work-orderes/`, dead role filter, unused `SELECT`s, `display:none` A4 page, duplicate dropdown endpoint. | P1-P2 | S | — | Low |

### 3.6 Performance & scalability

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk |
|---|---|---|---|---|---|---|
| P1 | A4 reports ~1,800/3,500+ queries; dashboard ~70 (**PERF-01/03**) | Replace per-month/per-day/N+1 loops with **grouped aggregate SQL**; JOIN names; cache aggregates. | P2 | L | D3, X3 | Medium — verify report parity |
| P2 | Function-wrapped indexed dates (**PERF-02**) | Range predicates (`col BETWEEN ? AND ?`). [17] | P1-P2 | M | D3 | Low |
| P3 | 72k-row ICD-10 loaded per page; `SELECT *` (**PERF-04/05**) | Cache ICD-10 / JOIN; select needed columns; `COUNT()` for counts. | P2 | M | X2 | Low |
| P4 | Full-reload refresh model (**PERF-06**) | Cache aggregates (short TTL/summary table); AJAX partial refresh. | P3 | M | P1 | Low |

### 3.7 UI, accessibility & print

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk |
|---|---|---|---|---|---|---|
| U1 | Clinical status by **color alone**; low contrast (**UI-01/02**) | Add **icon + text** to every status (TB, readmission, long-term, "still in", ICU); visible LOS-category label; WCAG-AA contrast. *Patient-safety relevant* (missed TB/isolation cue). | P1-P2 | S-M | — | Low |
| U2 | Labels not associated; duplicate IDs in loops; focus trap disabled (**UI-03/04**) | Unique IDs per row; proper `for`/`id`; restore modal focus trapping (Select2 `dropdownParent`). | P2 | M | — | Low |
| U3 | A4 reports: CDN-dependent, `display:none` hides KPI table, fixed-height clipping, browser-print (**UI-05/06/07/08**) | Vendor CSS/JS locally; un-hide the KPI/charts page; natural flow + `page-break-inside:avoid`; move to **server-side PDF**. | P2 | M-L | X3 | Medium — report layout rework |
| U4 | Not responsive for tablets; inline-style "design system"; centered inputs (**UI-09/10/11**) | Responsive columns/tables, `.modal-lg`; utility classes; left-align text. | P2-P3 | M | — | Low |
| U5 | Login a11y; icons/branding/typos (**UI-12/13/14**) | Doctype/`lang`/labels/`required`; semantic icons; fix branding & typos; `aria-label` on icon buttons. | P3 | S | — | Low |

### 3.8 UX & clinical workflow

| # | Problem (IDs) | Proposed change | Pri | Effort | Dependencies | Risk |
|---|---|---|---|---|---|---|
| W1 | Save-on-every-change, no confirm, lying green flash (**UX-01**) | Debounced/explicit save that **reads the server response**; confirm on identity fields (MRN/name); undo via audit. *Prevents silent wrong-patient edits.* | P1 | M | R1 | Low |
| W2 | Broken quote corrupts "assign to consultant" (**UX-02**) | One-line fix to close the `value` attribute in [newpatients/dmc-assign-to-primary.php:10](newpatients/dmc-assign-to-primary.php). **Highest safety-to-effort ratio.** | P1 | S | — | Low |
| W3 | Validation non-empty-only; no server validation (**UX-03**) | Typed/constrained inputs (age numeric range, MRN format, no future admit date) + **server-side validation** + inline field errors. | P1-P2 | M | S2 | Low |
| W4 | No confirmation on discharge/transfer/reverse (**UX-04/07**) | Confirm dialog showing **patient name + MRN** on every transition; act on success only. | P1 | S-M | — | Low |
| W5 | Inconsistent enums; invisible pipeline; unexplained two-phase discharge (**UX-05/08/09/11/12**) | Single source of truth per enum; per-card **status stepper** + unassigned-count badge; label the discharge phases & "New Patient?" toggle. **[NEEDS CLINICAL REVIEW]** | P2 | M | CLIN-05/06/07 | Low |

### 3.9 Clinical-workflow correctness — **[NEEDS CLINICAL REVIEW]**
These (**CLIN-01…09**) are not bugs to fix unilaterally — a clinician must confirm intent first:
readmission window (30-day vs "72hr", **CLIN-01**), cross-year aggregation & LOS/DST (**CLIN-02/03**),
`settings` thresholds' meaning/use (**CLIN-04**), auto-assignment policy (**CLIN-05**), `MORTALITY`
vocabulary (**CLIN-06**), two-phase discharge semantics (**CLIN-07**), the canonical "active"
definition (**CLIN-08**), and TB-list maintenance (**CLIN-09**). See **§7**.

---

## 4. Prioritized, phased roadmap

Sequenced to respect dependencies (auth guard + DB helper before per-endpoint hardening; backup
before InnoDB; InnoDB before FKs/transactions; "active" definition before list de-duplication;
clinical sign-off before changing clinical logic).

### Phase 0 — Emergency containment (hours-days, mostly ops, no rewrite) — **P0**
1. **Rotate** the DB and SMTP passwords (**S7**); remove them from any shared/Downloads copies.
2. **Delete** `reset-testcount.php` and `test-trans.php` from production (**S9**).
3. **Force HTTPS** and, if feasible, **restrict the app to the hospital network/VPN** until
   Phase 1 lands (**S8**).
4. **Take a verified, restorable backup** of the database (**R5**).
5. Delete the committed `php_errorlog` files; turn off `display_errors` (**X1/S9**).
> **Exit:** secrets rotated, dev endpoints gone, traffic encrypted/restricted, backup verified.

### Phase 1 — Critical security & data-integrity (≈4-6 weeks) — **P1**
Mostly **patch-in-place**, done module-by-module with a test added per module.
1. **Central auth guard** on every endpoint; centralize the role/capability matrix (**S1, S4**).
2. **Prepared statements** everywhere via a shared DB helper (**S2, C2**).
3. **Output encoding** + remove `eval`; baseline **CSP** (**S3, S10**).
4. **CSRF tokens** + **session/cookie hardening** (**S6**).
5. **Fix password reset** + hashing config (**S5**).
6. **Audit log** foundation; actor from session only (**R1, SEC-22**).
7. **HTTPS/headers** finalized (**S8**); secrets fully externalized (**S7**).
8. Data-safety quick wins: **W2** (assign-quote), **W1/W4** (confirmations & trustworthy saves),
   **W3** (validation).
> **Exit:** no unauthenticated endpoint; no string-built query; all output encoded; CSRF on all
> writes; reset flow safe; every clinical write audited. Re-run a security pass to confirm.

### Phase 2 — Stabilization & quick wins (overlapping, ≈3-6 weeks) — **P1/P2**
1. **MyISAM → InnoDB** (**D1**) → **transactions** on multi-step writes (**R2**).
2. **Indexes** + **sargable** stat queries (**D3, P2**).
3. **Soft-delete** (**R4**); backups/migrations tooling (**R5**).
4. Delete remaining dead code/weight (**X4**); central error handling (**C4**).
5. **Status color+icon/contrast** accessibility safety fix (**U1**).
> **Exit:** transactional integrity on clinical writes; no full-table-scan on hot paths;
> reversible deletes; key a11y safety cue fixed.

### Phase 3 — Refactor & UI/UX overhaul (≈2-4 months) — **P2/P3**
1. Introduce layering + **PHPUnit**; extract shared helpers/partials (**C1, C3**).
2. **De-duplicate** lists/census/ICD-10 (**X2**) on a single "active" definition (**CLIN-08**).
3. **Statistics engine** rework: grouped SQL + caching; merge A4 twins (**X3, P1, P4**).
4. **UI/UX overhaul**: responsive/tablet, accessibility, the data-entry workflow, the status
   pipeline (**U2/U4, W5**), and **server-side PDF** reports (**U3**).
5. Normalize **diagnoses/MRN/specialty** schema (**D4/D5, D2**).
> **Exit:** materially smaller, testable codebase; fast, correct stats; accessible, tablet-ready
> UI; reliable printed reports.

### Phase 4 — Re-platform decision & strangler migration (≈4+ months) — **P3**
If approved (**§6**), incrementally migrate to a modern framework (Laravel/Symfony/Slim) using
the **strangler-fig** pattern [25][26]: stand up the framework alongside, route new and legacy modules
through it one at a time behind the existing URLs, retire procedural files as they're replaced.
Add CI. **Never a big-bang rewrite.**

### Phase 5 — Nice-to-haves — **P3**
Branding/typo cleanup (**U5**), semantic icons, real-time dashboard refresh (**P4**), MFA,
richer reporting.

---

## 5. Effort & priority summary

| Phase | Theme | Priority | Rough effort | Can start |
|---|---|---|---|---|
| 0 | Containment (ops) | P0 | S | **Now** |
| 1 | Security + data-integrity core | P1 | L (4-6 wk) | After Phase 0 backup |
| 2 | InnoDB, indexes, soft-delete, a11y safety | P1/P2 | M-L | Overlaps Phase 1 |
| 3 | De-dup, stats engine, UI/UX, schema | P2/P3 | L (2-4 mo) | After Phase 1 |
| 4 | Framework migration (strangler) | P3 | L (4+ mo) | After Phase 1, decision-gated |
| 5 | Nice-to-haves | P3 | S-M | Anytime |

---

## 6. Patch in place vs. rewrite / re-platform

### What is patchable in place (do **not** wait for a rewrite)
**Every Critical and most High items** — auth guard, prepared statements, output encoding, CSRF,
session hardening, HTTPS/headers, secrets rotation, deleting dev endpoints, audit logging, InnoDB
migration, indexes, soft-delete, and the data-entry safety fixes. None require a framework; they
require disciplined, systematic edits and tests. **This is the bulk of the risk reduction and it
must happen on the current codebase, now.**

### What realistically requires re-platforming
Durable separation of concerns, centralized auth **middleware**, an ORM/query layer that
eliminates the SQL-injection *class* wholesale, templating that removes the duplication/XSS
surface, a real test harness, and a maintainable statistics engine. Retrofitting all of this onto
~20k LOC of mixed procedural PHP eventually *reconstructs* a framework by hand.

### Honest recommendation
**Harden in place first (Phases 0-2), then migrate incrementally (Phase 4) — do not big-bang
rewrite.** Reasoning:
- The security exposure is too urgent to gate on a months-long rewrite; hardening is achievable
  on the current code and delivers the most risk reduction per week.
- A big-bang rewrite of a live clinical system is high-risk and historically prone to failure;
  the **strangler-fig** approach [25] lets the app keep running while modules move to a framework
  one at a time, with each step independently shippable and testable. Framework upgrade/migration
  tooling supports this incremental path [26].
- Some cleaner files (`tb-patients.php`, `statistics/kpis.php`, `control.php`) already show the
  target patterns and can seed the shared helpers, making Phase 3 a natural on-ramp to Phase 4.
- **Decision gate:** commit to migration only after Phase 1, and choose the framework based on
  team familiarity (Laravel for batteries-included; Symfony for long-term/structured; Slim for a
  lighter touch).

---

## 7. Decisions & input needed

**Clinical (must confirm before changing the logic) — CLIN-01…09:**
- The intended **readmission window** (code: 30-day lookback labelled "72hr") — **CLIN-01**.
- Intended **reporting periods** (cross-year aggregation bugs) and the **LOS** definition
  (calendar days? inclusive? DST handling) — **CLIN-02/03**.
- Meaning/intended use of the **`settings` thresholds** (`short_los`/`long_los`/`min/max_*`) and
  the capacity formula — **CLIN-04**.
- The **auto-assignment ("shuffle") policy** (fairness, ICU/TB handling, caps) — **CLIN-05**.
- The **`MORTALITY`/disposition vocabulary** and the **two-phase discharge** semantics
  (`med_DISDATE` vs `DISDATE`, `delay`) — **CLIN-06/07**; the canonical **"active patient"**
  definition — **CLIN-08**; **TB-list** maintenance ownership — **CLIN-09**.
- **MRN format** (numeric vs alphanumeric/zero-padded) — drives **D4**.

**Product / operational / leadership:**
- **Is the app internet-facing or intranet/VPN-only?** (Sets breach blast-radius and urgency.)
- **Hosting & TLS**: can we enable HTTPS and restrict network access immediately? (Phase 0.)
- **Compliance regime** (HIPAA / local health-data law) and **breach-notification obligations**
  given the current exposure. [21][22]
- **Budget/timeline & team** for Phases 1-3; **framework choice** for Phase 4.
- **Backup/retention policy** and who owns ongoing maintenance (hospital vs the white-label vendor).
- **Open items from CLAUDE.md §10** (e.g. `members.active` default — confirmed `NULL`, so
  self-registered accounts are inactive until an Admin activates; the **SEC-02** unauth
  role/`active` update is the self-contained escalation path).

---

## 8. Evidence basis & citations

This plan's security recommendations are grounded in primary sources. During this review, the
core claims (✅) were **independently verified to a 3-vote adversarial bar against primary
sources** (OWASP + php.net); the remaining citations are the relevant primary authorities
(fetched during research) for areas not put through that same verification pass — flagged
transparently so they can be confirmed in a focused follow-up if desired.

**Verified ✅ (OWASP / PHP, 3-vote):**
1. OWASP Top 10 2021 — A01 Broken Access Control. https://owasp.org/Top10/2021/A01_2021-Broken_Access_Control/
2. OWASP Top 10 2021 — A03 Injection. https://owasp.org/Top10/2021/A03_2021-Injection/
3. OWASP — SQL Injection Prevention Cheat Sheet. https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html
4. PHP Manual — SQL Injection. https://www.php.net/manual/en/security.database.sql-injection.php
5. PHP Manual — mysqli prepared statements. https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
6. OWASP — Cross-Site Scripting Prevention Cheat Sheet. https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
7. PHP Manual — htmlspecialchars. https://www.php.net/manual/en/function.htmlspecialchars.php
8. OWASP — Content Security Policy Cheat Sheet. https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html
9. OWASP — CSRF Prevention Cheat Sheet. https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
10. OWASP — Authorization Cheat Sheet. https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
11. OWASP — Top 10 → Cheat Sheet index. https://cheatsheetseries.owasp.org/IndexTopTen.html

**Primary authorities for the remaining areas (cite-and-confirm):**
12. PHP Manual — password_hash(). https://www.php.net/manual/en/function.password-hash.php
13. PHP Manual — Session Security. https://www.php.net/manual/en/session.security.ini.php
14. OWASP — Session Management / Forgot Password / Password Storage Cheat Sheets. https://cheatsheetseries.owasp.org/
15. MySQL 8.4 — Converting Tables to InnoDB. https://dev.mysql.com/doc/refman/8.4/en/converting-tables-to-innodb.html
16. MySQL 8.4 — InnoDB & ACID / Foreign Keys. https://dev.mysql.com/doc/refman/8.4/en/mysql-acid.html
17. MySQL 8.4 — Optimization & Indexes (sargability). https://dev.mysql.com/doc/refman/8.4/en/optimization-indexes.html
18. NIST SP 800-63B — Digital Identity (authentication). https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-63B-4.pdf
19. NIST SP 800-92 — Guide to Computer Security Log Management. https://csrc.nist.gov/pubs/sp/800/92/final
20. NIST SP 800-66r2 — Implementing the HIPAA Security Rule. https://csrc.nist.gov/pubs/sp/800/66/r2/final
21. HIPAA Security Rule — 45 CFR §164.312 (Technical safeguards incl. audit controls). https://www.ecfr.gov/current/title-45/subtitle-A/subchapter-C/part-164/subpart-C/section-164.312
22. HHS — Breach Notification Rule. https://www.hhs.gov/hipaa/for-professionals/breach-notification/index.html
23. OWASP — Secure Headers Project. https://owasp.org/www-project-secure-headers/
24. NCSC — Using TLS to protect data. https://www.ncsc.gov.uk/guidance/using-tls-to-protect-data
25. M. Fowler — Strangler Fig Application (incremental migration). https://martinfowler.com/bliki/StranglerFigApplication.html
26. Symfony — Migration / upgrade guidance. https://symfony.com/doc/current/migration.html

*Verification stats from this review's research pass: 6 search angles, 33 primary sources
fetched, 161 candidate claims, 25 verified (25 confirmed / 0 refuted), synthesized to 8
high-confidence findings. The session-hardening, password, MySQL, audit/HIPAA, PHI-handling, and
framework-migration sections are backed by the named primary sources above but were not run
through the 3-vote pass — a focused follow-up can confirm specifics if required for an audit.*

---

*End of plan. A polished `.docx` version accompanies this Markdown copy.*
