# REVIEW-FINDINGS.md — DMC Patient-Flow Hub

> **Phase 2 deliverable: the consolidated, severity-rated findings register.**
> Companion to [`CLAUDE.md`](CLAUDE.md) (the architecture map) and
> [`RENOVATION-PLAN.md`](RENOVATION-PLAN.md) (the remediation roadmap, which references the
> IDs below). Read-only review — nothing was changed. Findings come from a file-by-file
> read of the whole codebase plus the `Demo.sql` schema; the highest-severity security
> claims were individually re-verified against source.

**Severity legend**
- **Critical** — exploitable now with severe impact on patient data/safety; fix before anything else.
- **High** — serious risk or near-certain harm; fix in the first hardening wave.
- **Medium** — meaningful risk/debt; schedule deliberately.
- **Low** — cosmetic / hygiene.

`[NEEDS CLINICAL REVIEW]` marks anything where a clinician must confirm intended behavior.

**At a glance:** the dominant risk is **systemic broken access control + SQL injection across
unauthenticated endpoints holding ~15,000 live patient records**, plus **trivial admin
takeover**, **no HTTPS**, and **plaintext production secrets**. These are not isolated bugs —
they stem from a few root causes (below). Counts: **~17 Critical, ~24 High, ~22 Medium, ~10 Low**
(grouped/deduplicated; many Criticals are systemic and recur across dozens of files).

---

## 0. Systemic root causes (fix the cause, not just each instance)

| ID | Root cause | Generates | Representative evidence |
|---|---|---|---|
| **ROOT-1** | **Action/AJAX endpoints include only `dbconnect.php` — no session/role/ownership check.** | Most Critical access-control findings | `patients/*`, `newpatients/*`, `consultations/*`, `registry/*`, `dmc-users-*.php`, `statistics/*`, `dashboard/1.php,3.php` |
| **ROOT-2** | **SQL built by string-interpolating `$_REQUEST`** instead of prepared statements. | Most SQLi findings | `patients/dmc-patient-delete.php:5`, `registry/search-results.php:29-100`, `dmc-users-update.php:13`, `reset-password.php:43`, dozens more |
| **ROOT-3** | **Output (PHI) echoed without `htmlspecialchars`/encoding.** | Most XSS findings | `registry/search-results.php`, `consultations/dmc-consultation-details.php`, all list/modal renderers |
| **ROOT-4** | **Authorization is UI-only** (buttons hidden by role/ownership; server doesn't re-check) **and page handlers run before the role gate.** | IDOR, privilege bypass | `dmc-patients.php:3-104`, `dmc-old-patients.php:4-69`, `dmc-new-consultation.php:222` vs handler |
| **ROOT-5** | **No framework/layering**: PHP+SQL+HTML+JS mixed per file; global `$mysqli`; copy-paste reuse. | Duplication, dead code, untestability, drift | `a4.php` (1.6k LOC), `charts.php` (1.3k), `dmc-patients.php` (1k) |
| **ROOT-6** | **MyISAM main tables → no transactions, no FKs, table locks.** | Integrity/concurrency findings | `picupatients`, `picupatients_temp`, most tables |
| **ROOT-7** | **Client-supplied identity/audit values trusted** (`admitted_by`, `userid`, `entered_by_id`, `position`). | Audit forgery, privilege escalation | `newpatients/dmc-patients-add.php:10`, discharge submits, `dmc-users-update.php` |

---

## A. Security — **highest priority**

### Critical

- **SEC-01 — Unauthenticated patient-record DELETE.** `patients/dmc-patient-delete.php:2-5`
  (`require '../dbconnect.php'`; `DELETE FROM picupatients WHERE ID='$id'`). Anyone on the
  network can permanently destroy any patient record (and via SQLi, many). *Verified.*
  **Fix:** require auth + Admin role server-side; soft-delete + audit; prepared statement.
- **SEC-02 — Unauthenticated privilege escalation to Admin.** `dmc-users-update.php:13-15`
  updates `members.position` (+ all capability flags) for any `member_id` from `$_REQUEST`,
  no auth. POST `position=0` ⇒ Admin. *Verified.* Pairs with **SEC-03**.
  **Fix:** auth + Admin check; never accept `position` for self-service; prepared statement.
- **SEC-03 — Public self-registration can create an Admin.** `register.php:19,61` writes
  `position` straight from POST; the form omits `0` but the server doesn't validate, so a
  crafted POST registers as Admin. **Fix:** server-side allow-list of self-registerable roles
  (never Admin); default to lowest privilege + manual activation.
- **SEC-04 — Unauthenticated member DELETE.** `dmc-users-delete.php:5` — `DELETE FROM members
  WHERE member_id='$id'` from `$_REQUEST`, no auth. Can delete the Admin. **Fix:** as SEC-01.
- **SEC-05 — Unauthenticated, destructive "test" endpoint.** `reset-testcount.php:9` —
  `UPDATE picupatients SET newassign=Null, DISDATE=Null, med_DISDATE=Null, assigned_on=Null,
  consultant_id=Null limit $count`, no auth, `$count` interpolated. Mass-wipes discharge
  dates/assignments on live patients. *Verified.* **Fix:** **delete this file** from prod.
- **SEC-06 — Unauthenticated patient transfer "test" endpoint.** `test-trans.php` writes
  `picupatients` with no auth. **Fix:** **delete this file**.
- **SEC-07 — Unauthenticated patient INSERT + SQLi.** `newpatients/dmc-patients-add.php:24,39`
  (string SELECT + INSERT from `$_REQUEST`, no auth). *Verified.* **Fix:** auth + prepared.
- **SEC-08 — Unauthenticated patient UPDATE + SQLi (registry).**
  `registry/dmc-search-patients-modify.php:17` — full `UPDATE picupatients … WHERE ID='$id'`
  from `$_REQUEST`, no auth; `WHERE ID='1' OR 1=1` rewrites every row. **Fix:** auth + prepared.
- **SEC-09 — Unauthenticated discharge/transfer writes.** All of
  `patients/dmc-patients-discharge-submit.php:44,66`, `…-complete-discharge-submit.php:21`,
  `…-icu-discharge-submit.php:37`, `…-modify.php:27`, `…-update.php:22`,
  `…-submit-changeconsultant.php`, plus `dmc-patients.php:6` (reverse-discharge) — change
  patient clinical state with no auth and (mostly) SQLi. **Fix:** auth + ownership/role + prepared.
- **SEC-10 — Unauthenticated consultation create/modify/delete.** `consultations/*` (add:29,
  delete:5, details:6, modify, specialty-dropdown:14) — no auth; most string-interpolated.
  **Fix:** auth + role + prepared; ownership check on signoff/delete.
- **SEC-11 — Unauthenticated registry/search read + export of PHI.**
  `registry/search-results.php`, `…-diagnosis.php` (+`?export=1` CSV), `…-consultations.php`,
  `…/dmc-search-patient-details.php`, `export_patients.php`, `fetchicd10.php` — return/export
  patient PHI with no auth; `search.php`'s Admin gate is bypassed by calling the sub-endpoints
  directly. **Fix:** auth + Admin on every sub-endpoint and export.
- **SEC-12 — SQL injection from a fully public endpoint.** `fetchicd10.php:10` —
  `… name LIKE '%$_GET[searchTerm]%'`, no auth → UNION-based extraction of any table
  (incl. `members` hashes) from the open internet. **Fix:** auth + prepared `LIKE CONCAT('%',?,'%')`.
- **SEC-13 — SQL injection in password reset, on a public endpoint.** `reset-password.php:43`
  (`… where md5(member_email)='$email' and md5(member_password)='$pass'` from `$_GET`) and
  `:24` (string UPDATE). *Verified.* **Fix:** parameterized, single-use, hashed, expiring token (SEC-14).
- **SEC-14 — Broken password-reset token design.** `forget-password-email.php:67` &
  `send-reset-pass-by-admin.php:55` email `md5($row['member_password'])` (md5 of the bcrypt
  hash) as the token — deterministic, never expires, not single-use; computable by anyone who
  reads the DB. **Fix:** random 256-bit token, stored **hashed** with short expiry, single-use,
  invalidated on use/password-change.
- **SEC-15 — No HTTPS / plaintext transport for PHI.** `.htaccess:3-4` 301-redirects to
  `http://www.dmc-im.com`. Credentials, sessions, and PHI traverse plaintext. **Fix:** force
  HTTPS (redirect 80→443), HSTS; obtain TLS cert.
- **SEC-16 — Hardcoded production DB credentials (×2).** `dbconnect.php:4-7` and
  `DBController.php:3-6` (duplicated). Treat as compromised. **Fix:** move to env/secret store;
  **rotate the DB password now**; remove from source.
- **SEC-17 — Hardcoded SMTP credentials.** `forget-password-email.php:82` &
  `send-reset-pass-by-admin.php:70` (`info@dmc-im.com` / plaintext password). **Fix:** env
  store; **rotate**.

### High

- **SEC-18 — Stored/Reflected XSS of PHI throughout.** Patient fields echoed without escaping
  in `registry/search-results*.php`, `consultations/dmc-consultation-details.php:27-81`
  (incl. reflected `$_REQUEST['bookId']` into JS at :81), all list/modal renderers, and
  `errors.php:4`, `control.php:379,502` (member email in `href`). Combined with the unauth
  write endpoints, an attacker can store a payload that runs in every clinician's session.
  **Fix:** `htmlspecialchars()` on all output (ENT_QUOTES, UTF-8); Content-Security-Policy.
- **SEC-19 — No CSRF protection anywhere.** No forms/AJEX endpoints use anti-CSRF tokens;
  `$_REQUEST` is accepted (so even GET triggers state change). A logged-in clinician visiting
  a malicious page can be made to discharge/delete/reassign patients or escalate an attacker.
  **Fix:** synchronizer CSRF tokens on all state-changing requests; restrict to `$_POST`.
- **SEC-20 — Authorization is UI-only + handlers run before the gate.** Per ROOT-4. E.g.
  signoff handler `dmc-new-consultation.php` executes without the ownership check the button
  implies (`:222`); `dmc-patients.php:3-102` and `dmc-old-patients.php:4-66` mutate before the
  role check at `:104`/`:69`. **Fix:** centralized server-side authz on every endpoint; order gate first.
- **SEC-21 — IDOR on every object.** Endpoints act on any `ID`/`member_id` with no ownership
  check (`profile.php:53` lets any user edit any member's name/email; discharge submits act on
  any patient; consultation modify on any consultation). **Fix:** object-level ownership/role checks.
- **SEC-22 — Spoofable audit identity.** `admitted_by`, `userid`, `trans_discharge_by`,
  `entered_by_id` taken from the request, not the session (`newpatients/dmc-patients-add.php:10`,
  discharge submits, `consultations/dmc-consultation-add.php`). The clinical "who did it"
  record is forgeable. **Fix:** derive actor from server session only.
- **SEC-23 — Weak session/cookie handling.** Remember-me cookies set without
  `HttpOnly`/`Secure`/`SameSite` (`index.php:43-49`); `logout.php` clears cookies without
  matching path/expiry and **does not invalidate the DB token** (valid ≤30 days post-logout);
  no session regeneration on login (fixation); no idle/absolute timeout. **Fix:** set cookie
  flags; `session_regenerate_id(true)` on login; invalidate token on logout; timeouts.
- **SEC-24 — Sensitive-info disclosure via errors/debug.** `dbconnect.php:13` prints raw DB
  error; `display_errors`-style leakage; `var_dump` of full patient PHI to the browser
  (`newpatients/dmc-patients-icu-transfer.php:12`, `…-shuffle.php:175+`,
  `dmc-new-consultation.php:27-32`); committed `php_errorlog` (~37 MB total, incl.
  `newpatients/php_errorlog` 36 MB) leaking queries/paths/PHI. **Fix:** `display_errors=Off`;
  remove debug; central logging outside web root; delete committed logs.
- **SEC-25 — Abandoned dependency with known CVEs.** `registry/export-results-exel.php`
  includes `vendor/PHPExcel.php` (end-of-life). **Fix:** migrate to PhpSpreadsheet (or remove).
- **SEC-26 — `eval()` of server-fetched fragments.** `dashboard.php:87,104` executes
  `<script>` text from fetched HTML → any XSS in that HTML becomes code-exec; blocks CSP.
  **Fix:** return JSON; initialize charts from static JS.
- **SEC-27 — No security headers.** No CSP, HSTS, X-Frame-Options/frame-ancestors,
  X-Content-Type-Options, Referrer-Policy. **Fix:** add via server/`.htaccess` or PHP.

### Medium
- **SEC-28 — `$_REQUEST` everywhere** merges GET/POST/COOKIE, enabling cookie/URL parameter
  injection of action inputs. **Fix:** use `$_POST`/`$_GET` deliberately.
- **SEC-29 — `register.php` uses `mysqli_real_escape_string` not prepared** (`:42,61`):
  multibyte-charset bypass risk. **Fix:** prepared statements.
- **SEC-30 — `members.member_password varchar(64)`** leaves ~4 chars of margin for bcrypt and
  would silently truncate a longer (e.g. Argon2) hash. **Fix:** widen to `varchar(255)`.
- **SEC-31 — Expired-password login path sets session before the `active` check**
  (`index.php:29-31`). **Fix:** check `active` first.

---

## B. Reliability & Data integrity

### High
- **REL-01 — No transactions on multi-statement clinical writes.** ICU transfer
  (`newpatients/dmc-patients-icu-transfer.php` INSERT+UPDATE) and specialty transfer
  (`dmc-patients.php:26-90` INSERT+UPDATE) can half-complete → patient simultaneously
  "active" in two locations or lost. MyISAM can't roll back (ROOT-6). **Fix:** InnoDB + transactions.
- **REL-02 — No audit trail.** No record of who changed/deleted/discharged what and when;
  the only audit-ish fields are client-spoofable (SEC-22). Unacceptable for a clinical record.
  **Fix:** append-only audit log (actor from session, action, entity, before/after, timestamp).
- **REL-03 — Concurrent-edit races.** Two staff editing one patient: last-write-wins with no
  locking/optimistic-concurrency; the shuffle's `UPDATE … WHERE consultant_id IS NULL LIMIT 1`
  (no patient id) can double-assign under concurrency (`newpatients/dmc-patients-shuffle.php:83,163…`).
  **Fix:** targeted `WHERE ID=?`; optimistic concurrency (version/updated_at) on edits.
- **REL-04 — No backup/migration/DR story.** No migrations, no backup config in repo; schema
  changes are ad-hoc. **Fix:** schema migrations tool + scheduled, tested backups + retention.
- **REL-05 — Hard deletes destroy clinical history.** Patient/member/consultation deletes are
  physical (`DELETE`), no soft-delete/audit. **Fix:** soft-delete + audit; restrict to Admin.

### Medium
- **REL-06 — Reverse-discharge leaves stale fields.** `dmc-patients.php:6` clears
  `DISDATE/med_DISDATE/delay` but not `trans_discharge`/`trans_discharge_by`; the registry undo
  clears a different set (`search.php:27-45`) → two "undo" paths, inconsistent end-states.
  **Fix:** one canonical reverse-discharge with consistent field handling + confirmation. [NEEDS CLINICAL REVIEW]
- **REL-07 — Complete-discharge doesn't update `med_DISDATE`** (`…-complete-discharge-submit.php:21`)
  → `DISDATE` and `med_DISDATE` diverge for phase-2 discharges, skewing LOS. [NEEDS CLINICAL REVIEW]
- **REL-08 — ICU transfer drops `BED` and mis-attributes `trans_discharge_by`**
  (`newpatients/dmc-patients-icu-transfer.php:22,34`). **Fix:** copy bed; use session actor.
- **REL-09 — `picupatients_temp` import not atomic** (`dmc-old-patients.php`): SELECT-then-INSERT
  per row, no transaction. **Fix:** transactional bulk import.

---

## C. Database

### High
- **DB-01 — Main patient table is MyISAM** (`picupatients`, `picupatients_temp`, +most tables).
  No transactions, no FK enforcement, table-level locking under concurrent writes. **Fix:**
  migrate all tables to InnoDB (utf8mb4). (Enables REL-01/03.)
- **DB-02 — No foreign keys / referential integrity.** All relationships are app-level;
  orphan `consultant_id`/`admitted_by`/diagnosis ids are possible. **Fix:** add FKs after InnoDB
  migration and data clean-up.
- **DB-03 — Missing indexes on hot lookup columns.** `picupatients` has only PK +
  `ADMDATE`/`DISDATE` indexes; **no index on `MRN`, `consultant_id`, `current_location`,
  `admitted_by`** — every MRN lookup and per-consultant filter table-scans ~15k rows.
  **Fix:** add indexes (and make MRN a typed, indexed column — see DB-04).

### Medium
- **DB-04 — `MRN` is `mediumtext` in `picupatients` but `int` in `consultations`** — type
  mismatch breaks logical joins for non-numeric/zero-padded MRNs and prevents clean indexing.
  **Fix:** standardize MRN type (e.g. `VARCHAR(20)` everywhere) + index. [NEEDS CLINICAL REVIEW: MRN format]
- **DB-05 — Diagnoses stored as JSON arrays of ICD-10 codes in text columns**
  (`picupatients.admissiondiagnosis`, `consultations.indication`) → can't index/join; forces
  PHP-side scans and `JSON_CONTAINS` full scans for stats. **Fix:** normalize to a
  `patient_diagnoses(patient_id, icd10_id)` join table (keep JSON as a denormalized cache if needed).
- **DB-06 — Duplicate/overlapping reference tables.** `speciality` vs `other_specialities`
  (both misspelled `specilaity`); `picupatients` vs identical `picupatients_temp`. **Fix:**
  merge specialty tables (type discriminator); replace temp table with a staged-status flag.
- **DB-07 — Mixed engines & charsets** (InnoDB/MyISAM; latin1/utf8mb3/utf8mb4) → encoding
  corruption risk for non-ASCII patient names. **Fix:** standardize on InnoDB + utf8mb4.
- **DB-08 — Dead tables** `consultation_details`, `Notes` (no code touches them). **Fix:**
  drop after backup, or implement if intended. [NEEDS CLINICAL REVIEW]

---

## D. Code quality & architecture

### High
- **ARCH-01 — No separation of concerns / no framework.** PHP+SQL+HTML+inline-JS per file;
  no router, data layer, or templating; business logic lives in anonymous top-level scope.
  Largest files: `statistics/a4.php` (~1.6k LOC), `charts.php` (~1.3k), `dmc-patients.php`
  (~1k). **Fix:** introduce layering (config, DB helper, auth middleware, templates); longer
  term, a framework. See **§J** for the patch-vs-rebuild call.
- **ARCH-02 — Global `$mysqli` as implicit dependency + two DB idioms.** Prepared
  `DBController` used by only ~3 files; everything else string-builds raw queries. **Fix:**
  one prepared-statement DB helper used everywhere.
- **ARCH-03 — POST-handlers-in-page-files with `<script>window.location>` redirects** instead
  of POST-Redirect-GET (`dmc-new-admissions.php:34-37`, `…-add.php:51`). **Fix:** dedicated
  action endpoints; `header('Location')` + `exit`.
- **ARCH-04 — Zero tests; code is structurally untestable** (globals, side-effecting includes,
  logic embedded in HTML). **Fix:** extract pure functions (census, LOS, shuffle plan,
  readmission) accepting `$mysqli`; add PHPUnit; start with the shuffle algorithm.

### Medium
- **ARCH-05 — `die()`/raw-error output as error handling** (`dbconnect.php:13`, `… $mysqli->error`
  echoed in dozens of files). **Fix:** central error handler; generic user messages.
- **ARCH-06 — Naming hazards.** Schema column `specilaity` (×2 tables, ~10 refs); POST key
  `discahrge_type` (silent wrong-branch if "fixed"); `$access_PICU_*` for a non-PICU unit.
  **Fix:** planned rename migration + find/replace.
- **ARCH-07 — `Auth::update()` references non-existent `$this->conn`** (`Auth.php:33`) — dead,
  would fatal if called. **Fix:** delete.
- **ARCH-08 — `authCookieSessionValidate.php:43-49` expiry check relies on a typo
  (`$isExpiryDareVerified`)**; works only because the typo is consistent, latent bug.
  **Fix:** rename to match the declared `$isExpiryDateVerified`.

---

## E. Simplification (dead code / duplication)  — see SIMP report detail

### High
- **SIMP-01 — ~37 MB of committed `php_errorlog` files** (`newpatients/php_errorlog` 36 MB +
  6 others). **Fix:** delete; `.gitignore`; log outside web root.
- **SIMP-02 — Patient-card render + per-consultant census + ICD-10 resolution duplicated
  ~7-13×** across `active-list.php`, `dmc-patients.php`, `longterm.php`, `tb-patients.php`,
  `dashboard/3.php`, registry & modal renderers — with **inconsistent "active" definitions**
  and N+1 ICD-10 lookups (`SELECT … icd10 WHERE id='$value'` inside loops in ≥6 files).
  **Fix:** shared `census()`, `render_patient_card()`, one cached `load_icd10_map()`. (`tb-patients.php`
  is the clean template.) Est. removes 1,500-2,500 LOC and many N+1s.

### Medium
- **SIMP-03 — Statistics interval (daily/monthly/quarterly) blocks + readmission/destination
  accumulators duplicated across `charts.php`, `charts1.php`, `time1.php`, `a4.php`,
  `a4-monthly.php`** (readmission builder in 9 files). The two A4 reports are near-twins.
  **Fix:** shared period/aggregation helpers; merge the A4 twins. Est. −800-1,200 LOC and fixes
  bugs in one place.
- **SIMP-04 — Dead code/weight:** `dashboard/2.php`/`4.php` (empty), `work-orderes/` (97 files),
  dead role filter `dmc-new-consultation.php:89`, unused `SELECT * FROM picupatients`
  `statistics.php:25` (15k rows), unused `$countries` `longterm.php:27`, `display:none` A4 page,
  `index.php:130` cookie pre-fill, commented blocks. **Fix:** delete.
- **SIMP-05 — Duplicate specialty-dropdown endpoints** (`patients/dmc-specialty-transfer-dropdown.php`
  ≈ `consultations/dmc-consultation-specialty-dropdown.php`, byte-identical bar param names).
  **Fix:** one parameterized partial.

> **Estimated 35-45% of application PHP is duplication/boilerplate; the 4 largest files are
> ~half duplication.**

---

## F. Performance & scalability

### High
- **PERF-01 — A4 reports issue ~1,800 (yearly) / ~3,500+ (monthly) queries per load.**
  `statistics/a4.php` & `a4-monthly.php`: 12-month loops × per-metric full scans + per-day
  census loops + **N+1 readmission subquery per admission**. Will degrade as data grows.
  **Fix:** grouped aggregate SQL (`GROUP BY YEAR/MONTH/DATE`), single self-join for readmissions,
  caching.
- **PERF-02 — Index-defeating queries everywhere.** `MONTH()/YEAR()/WEEK()/QUARTER()` wrap the
  indexed `ADMDATE`/`DISDATE` columns (non-sargable) across `dashboard/*`, `statistics/*` →
  full scans despite the indexes. **Fix:** range predicates (`col BETWEEN ? AND ?`).

### Medium
- **PERF-03 — Dashboard cold load ≈ 70 queries**, mostly full scans, incl. a 31× full-scan
  loop (`dashboard/1.php:11`) and N+1 consultant-name lookups (`dashboard/3.php:50`). **Fix:**
  single GROUP BY DATE query; JOIN member names.
- **PERF-04 — 72k-row `icd10` table loaded into PHP on many pages** (SIMP-02). **Fix:** cache / JOIN.
- **PERF-05 — `SELECT *` used for counts and for 15k-row loads not needed.** **Fix:** select needed
  columns / use `COUNT()`.
- **PERF-06 — Refresh model is full page reload** (dashboard re-runs everything; no caching,
  polling, or incremental update). **Fix:** cache aggregates; AJAX partial refresh; consider a
  short-TTL summary table.

---

## G. UI / Accessibility / Print

### High
- **UI-01 — Clinical status conveyed by COLOR ALONE** (TB green, readmission orange, long-term
  brown, "discharged still in" gold, ICU royal-blue; LOS short/normal/long via pale green/
  yellow/red with no label) — `dmc-patients.php:377-426`. Fails WCAG 1.4.1; a missed TB/isolation
  or long-stay cue is a safety/throughput risk, worsened by glare on bedside tablets. **Fix:**
  add icon + text token to every status; label LOS category.
- **UI-02 — Low contrast on those banners/LOS fills** (e.g. text on `#01ff70`/`#fd7e14`; pale
  LOS cards) fails WCAG AA. **Fix:** AA-compliant fg/bg pairs (≥4.5:1).
- **UI-03 — Labels not associated with inputs; duplicate IDs across looped cards**
  (`id='id'`/`'gender'`/`'datepicker'` repeated per patient; broken `for=`) — screen-reader
  failure and a real data-entry hazard (label/`getElementById` hits the wrong element).
  `dmc-patients.php`, `dmc-new-admissions.php`, `search.php`. **Fix:** unique IDs (suffix
  `$ID`), proper `for`/`id`.
- **UI-04 — Modal focus trapping disabled** (`dmc-patients.php:973`
  `enforceFocus=function(){}`) → keyboard/SR users tab behind the dialog. **Fix:** remove
  override; use Select2 `dropdownParent`.
- **UI-05 — A4 report depends on public CDNs** (Chart.js 3 / Bootstrap 4 from jsdelivr/maxcdn)
  → **reports break on an offline/locked-down hospital network**; also 3 Chart.js majors / 2
  Bootstrap majors across the app. **Fix:** vendor locally; standardize versions.
- **UI-06 — A4 report page is `display:none`, hiding the KPI table + consultant/doughnut
  charts** (`statistics/a4.php:941`) → printed yearly report silently missing core numbers.
  **Fix:** un-hide / relocate canvases & table; verify chart constructors run.
- **UI-07 — Fixed-height `<page>{overflow:hidden}` + invalid `size` on non-`@page` selectors**
  (`a4.php:396-436`) clip overflowing content on paper; print output browser-dependent.
  **Fix:** natural flow + `page-break-inside:avoid`; single valid `@page`.

### Medium
- **UI-08 — Browser-print, not server PDF** → inconsistent "official" reports; depends on user
  enabling background-graphics for the colored counters. **Fix:** server-side PDF (headless
  Chrome/Dompdf) or a print button + print-optimized CSS.
- **UI-09 — Not responsive for tablets:** fixed `col-sm-3` card grid, raw `<table>`s with
  `col-md-*` on `<td>`, an orphan `</table>` (`dmc-new-admissions.php:392`), small default
  modals for dense forms. **Fix:** responsive columns, real `.table.table-responsive`, `.modal-lg`.
- **UI-10 — Inline styles are the de-facto design system; `css/main.css` is a leftover
  login theme with a global `*{margin:0}` reset fighting Bootstrap.** **Fix:** utility classes;
  scope/remove `main.css`.
- **UI-11 — Centered text in all inputs** (`sidebar.php:97`) hampers scanning/transposition
  detection of MRNs/ages. **Fix:** left-align text, right-align numerics.
- **UI-12 — Icon-only destructive controls without `aria-label`** (delete trash icon). **Fix:** add labels.
- **UI-13 — Login page** lacks `<!DOCTYPE>`/`lang`, real labels, and `required` on password
  (`index.php:74,120-132`). **Fix:** add them.

### Low
- **UI-14 — Non-semantic icons** (everything `fa-edit`/`fa-book`/`fa-cog`), **branding leakage**
  (innovia.ai, healthpro.Ai, devs' Gmail in `footer.php:4`), **user-visible typos**
  ("Diaschage", "Specilaity", "Contact you manager", `<spnan>`). **Fix:** tidy.

---

## H. UX & clinical workflow

### Critical
- **UX-01 — "Save on every change event" inline edit with no confirmation, no undo, and a
  green flash that lies.** `dmc-patients.php:780-810`, `dmc-new-admissions.php:756-782` POST on
  each field `change`; success callback ignores the response (cell turns green even if the save
  failed). A bedside mis-tap permanently alters MRN/name/age/diagnosis on a live record.
  **Fix:** explicit/debounced save that reads the response; confirm on identity fields; audit/undo.
- **UX-02 — Broken HTML quote corrupts the core "assign to consultant" action.**
  `newpatients/dmc-assign-to-primary.php:10` (`value='<?php echo $id;?> style=…`) → patient id
  becomes garbage; assignment silently fails/targets the wrong row while the modal looks
  successful → patient left with **no doctor of record**. *Verified.* **Fix:** close the attribute.
- **UX-03 — Client validation is non-empty-only; no server validation.**
  `dmc-new-admissions.php:683-726` doesn't check age numeric, MRN format, or future admit dates;
  server interpolates whatever arrives. Transposed MRN/age attaches data to the wrong patient.
  **Fix:** typed/constrained inputs + inline field errors + server-side validation.

### High
- **UX-04 — No confirmation on Discharge / Complete Discharge / ICU Discharge / Transfer /
  Reverse-Discharge / ICU-transfer** (only plain delete confirms). High-consequence actions
  fire on a single tap. **Fix:** confirm dialog showing **patient name + MRN** on every transition.
- **UX-05 — Inconsistent enumerations** for gender (2 vs 3 options across forms), admit-from,
  location, disposition → fragments registry/stats group-bys (the report hand-buckets DISTO
  strings). **Fix:** single source of truth per enum, identical everywhere. [NEEDS CLINICAL REVIEW]
- **UX-06 — Generic error feedback** (one "fill all fields" line, no field highlighted) and
  **artificial delays** (3s after admit; fake 2s shuffle spinner) + full-page reloads on common
  tasks. **Fix:** per-field inline errors; AJAX in-place updates; remove fake delays.
- **UX-07 — `del()` hides the row regardless of server response**
  (`dmc-patients.php:891`) → clinician believes a failed delete succeeded. **Fix:** act on success only.
- **UX-08 — Pipeline is invisible.** Admission→assignment→two-phase discharge spans similar-
  looking pages with no status/stepper; an admitted-but-unassigned patient (no doctor) just
  sits on another page with no alert. **Fix:** status stepper per card; persistent unassigned-count badge.

### Medium
- **UX-09 — Two-phase discharge unexplained** (med-discharge vs complete); only a gold banner
  signals the in-between state. **Fix:** label the phase + show med-discharge date/delay reason. [NEEDS CLINICAL REVIEW]
- **UX-10 — Inconsistent new-tab opens** (Active Patients, A4 reports) unsignposted. **Fix:** consistent + external-link icon.
- **UX-11 — "New Patient?" toggle is consequential but unexplained** (drives new/old workload
  counts). **Fix:** clear label + help. [NEEDS CLINICAL REVIEW]
- **UX-12 — Fragile/uneditable admit date** (`onfocus="(this.type='date')"` quote bug; date
  fields read-only for most roles → wrong admit date can't be corrected, and it drives LOS).
  **Fix:** one reliable date control; allow authorized correction with confirmation.

---

## I. Clinical-workflow correctness — **[NEEDS CLINICAL REVIEW]**

These encode medical/operational logic; a clinician must confirm intent before any change.

- **CLIN-01 — "72-hour readmission" uses a 30-day lookback.** `statistics/kpis.php:27-58`
  `recent_discharges` CTE: 30-day lower bound vs 3-day upper bound; metric labelled "72hr".
  Also the CTE has no date filter (scans all history). Confirm the intended readmission window.
- **CLIN-02 — Cross-year aggregation bugs.** `statistics/time1.php:69` filters monthly
  discharges by `YEAR(ADMDATE)` not `YEAR(DISDATE)`; quarterly consultation/signoff queries
  (`:109-114`) omit the year filter (aggregate across all years). Same `YEAR(ADMDATE)`-for-LOS
  pattern in `a4.php`. **Fix after confirming intended periods.**
- **CLIN-03 — LOS via `strtotime()/86400`** (`charts.php:1084`, `a4.php`) ignores DST → ±1-day
  errors; `a4.php:3` has the timezone set commented out. Confirm LOS definition (calendar days?
  inclusive of admit/discharge day?) then compute via SQL `DATEDIFF`.
- **CLIN-04 — `settings` thresholds largely defined-but-unenforced.** `short_los`/`long_los`
  read but unused in stats (`statistics.php:37`); `min_hospitalist`/`min_subs`/`max_subs` not
  used in dashboards; capacity = `active/(hospitalist_n × max_hospitalist)×100` (no minimum-
  staffing alert) (`dashboard/3.php:147`). Confirm intended clinical meaning/use.
- **CLIN-05 — Auto-assignment ("shuffle") policy.** The 4-round balancing + ICU/TB handling +
  the hardcoded `< 5` cap inconsistent with `max_subs`, plus nondeterministic `LIMIT 1` assigns.
  Confirm the intended assignment fairness/policy.
- **CLIN-06 — `MORTALITY` field is overloaded** (holds 'Alive'/'Dead'/LAMA/Absconded as a
  status/disposition; set to 'Alive' on a ward transfer). Confirm the intended outcome vocabulary.
- **CLIN-07 — Two-phase discharge semantics** (medical vs complete; `med_DISDATE` vs `DISDATE`;
  `delay` reason). Confirm definitions and which date drives reporting.
- **CLIN-08 — "Active patient" has several definitions** across files (sometimes just
  `DISDATE IS NULL`, sometimes excluding ICU/long-term/TB/medically-discharged). Confirm the
  canonical definition (then centralize per SIMP-02).
- **CLIN-09 — TB classification** is by diagnosis ∈ `tb_list` (an editable ICD-10 list). Confirm
  the list is clinically maintained and complete (drives isolation/registry).

---

## J. Patch vs. rebuild (summary — full reasoning in the plan)

- **Patchable in place (do first, urgently):** all Critical security items (auth on endpoints,
  prepared statements, output encoding, CSRF, HTTPS, secrets rotation, delete dev endpoints),
  session/cookie hardening, the UX-01/02/03 data-safety fixes, delete the 37 MB of logs, add
  indexes, InnoDB migration, audit logging. None require a rewrite; they require disciplined,
  systematic edits across many files.
- **Requires re-platforming (plan deliberately):** true separation of concerns, centralized
  auth middleware, an ORM/query layer eliminating the SQLi class wholesale, templating to kill
  the duplication/XSS surface, testability, and the statistics engine. A framework (Laravel/
  Symfony/Slim) is the only durable path given ~20k LOC of mixed procedural code — but it is a
  multi-month effort and **must be preceded by the emergency hardening**.

*(Cited best-practice backing for these recommendations is being compiled by the deep-research
pass and will be incorporated into `RENOVATION-PLAN.md`.)*
