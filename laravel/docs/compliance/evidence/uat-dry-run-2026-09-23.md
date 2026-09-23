# UAT technical dry run — 2026-09-23

> **What this is — and is not.** A technical rehearsal of [`UAT-TEST-PLAN.md`](../../UAT-TEST-PLAN.md)
> run by Claude Code on the owner's instruction, against a **local copy with fake demo data**. It proves
> the application behaves as documented for every role before people spend time on it. It is **not** the
> clinical UAT sign-off: that still needs the owner and clinicians, on the real system, with real
> accounts (the Go/No-Go table in the test plan stays blank until they do it).

## Method

- **Environment:** the Laravel app at `main` `379ef29` served locally, MySQL 8.4 in a throwaway
  container, `migrate:fresh` + `DemoSeeder` (16 demo consultants, 442 fake patients, 482 admissions,
  180 consultations). Production was never touched.
- **Accounts:** eight walkthrough accounts, one per role and capability variant — Admin; Registrar
  (add, assign, modify); Consultant A (on service, coordinates consultations); Consultant B (same
  specialty); a consultant in another specialty; Resident with Can Manage; Resident with no
  capabilities; Observer.
- **Signing in:** a local-only harness link (kept outside the repository) establishes the same session a
  password + MFA login does. **No password or authenticator code was typed** — so the sign-in, MFA,
  registration and password screens themselves were not exercised by hand; the automated suite covers
  them.
- **Checks after every state-changing action:** the database changed exactly as
  [`DATABASE-AND-BEHAVIOR.md`](../../DATABASE-AND-BEHAVIOR.md) §5 documents; the board / list / page
  shows it; the dashboard and statistics moved by the right amount per
  [`DASHBOARD-AND-STATISTICS-METRICS.md`](../../DASHBOARD-AND-STATISTICS-METRICS.md).
- **Then** an authorization sweep of every role against every route (refused actions must write
  nothing), an **independent recomputation of every dashboard and statistics figure** from the
  database, and an adversarial re-check of each reported defect.

## Results

| Flow | UAT sections | Rows | Pass | Fail | Blocked | Not run |
|---|---|---:|---:|---:|---:|---:|
| Admissions & assignment | 0, 3 | 25 | 25 | 0 | 0 | 0 |
| Patients board & flow | 4 | 21 | 20 | 1 ¹ | 0 | 0 |
| Handover & notifications | 5 | 14 | 14 | 0 | 0 | 0 |
| Consultations | 6 | 11 | 11 | 0 | 0 | 0 |
| Registry, dashboard, statistics, reports (admin) | 7, 8, 9 | 34 | 33 | 1 | 0 | 0 |
| Control panel, import, merge, audit (admin) | 10–13 | 27 | 19 | 4 | 1 ² | 3 ³ |
| Authorization matrix + non-credential parts of 1, 14, 15 | 2 (1, 14, 15) | 30 | 30 ⁴ | 0 | 0 | 0 |
| **Statistics reconciliation** (UI vs independent SQL) | 8, 9 | 28 | **28** | 0 | 0 | 0 |
| **Total** | | **190** | **180** | **6** | **1** | **3** |

¹ The long-term view lists discharged long-term episodes — by design (the long-term registry keeps
closed rows; `PatientsController` PERF-03 comment), so not a defect.
² CTL-06 asked the admin to create a user; the app deliberately has no such action (self-registration +
activation). The test plan's wording is corrected.
³ Committing a bulk import (preview only, by instruction), and the failed-login anomaly population
(needs a typed wrong password).
⁴ One row was first blocked because an earlier flow had legitimately reset that account's MFA; re-run
the same day with the account re-enrolled — the resident with Can Manage can discharge and undo on
another consultant's patient (audited), the resident without it is refused (403, nothing written).

**Every dashboard, statistics, active-list and consultation-dashboard figure matched an independent
recomputation from the database.**

## Defects found and fixed

| # | Severity | Defect | Fix (as shipped) |
|---|---|---|---|
| 1 | Critical | Patient merge: the source/target pickers rendered nothing in the built app (a runtime template string; the production Vue build has no template compiler, the test build does), so only auto-detected duplicates could be merged | The picker is a real single-file component (`PatientPicker.vue`); a guard test (`noRuntimeTemplates.spec.js`) fails on any runtime template string in app code |
| 2 | Major | Deactivating or deleting a user blocked future logins but left their live sessions working | Deactivate and delete now revoke trusted devices and delete the user's `sessions` rows (`sessions_ended` in the audit detail); every request also re-checks that the account is active and not deleted, signs it out with a generic message and writes `session.evicted` |
| 3 | Major | "Record every chart open" (`log_record_opens`) was implemented but had no switch in Control | Control → Settings → Security: "Log every record and handover open" |
| 4 | Minor | Reversing a consultation sign-off copied the clinical note into the audit log in plaintext (the live column is encrypted) | The audit row keeps `note_encrypted` (ciphertext under `APP_KEY`) and `note_length`, never the text; the audit viewer and the audit CSV/XLSX export show `[encrypted note]`. Key-rotation consequence documented (ENCRYPTION-AT-REST.md §4). Production had no such rows |
| 5 | Minor | Registry labelled every open consultation "Active" | Real status (New / Active / Ongoing / Signed off); a sign-off date wins over an open status |
| 6 | Minor | The consultations ledger's "New" count included legacy-inconsistent rows (open status + sign-off date) the physician dashboard excludes | Such rows are filed under Signed off on the ledger — the same rule as the dashboard, the handover sheet and the Registry. Production had no such rows |
| 7 | Minor | Control never said MFA is mandatory for everyone; the Security page said "MFA enforcement is off" and listed no one | Accurate caption and wording; the Security page and the dashboard admin band count every active unenrolled user regardless of the inert `mfa_enforcement` setting |
| 8 | Minor | A non-admin trying reverse-discharge or delete was sent to the password prompt before being refused | `admin` runs before `stepup` on both routes: 403 first |

**Re-verified live after the fixes** (same local copy, built assets, CSP enforced): both merge
pickers render and search; a deactivated user's open session was signed out on its next request
(`session.evicted`) and the account re-activated; the record-open switch saved only its own field
and a handover open wrote `handover.read`; the Registry shows the four statuses; ledger and dashboard
agree (57 new, 60 open, drifted rows under Signed off, 183 total on both the ledger and the
Registry); a same-day sign-off reversed with a synthetic marker note left no plaintext in the audit
row and the viewer showed the placeholder; a registrar with no step-up got 403 on both admin routes
with nothing written; the Security page and the dashboard band both count the 16 unenrolled demo
accounts. Every fix also has an automated regression test.

Doc corrections the run prompted: Observer access (board, active list and handovers only — CLAUDE.md
and the test plan), residents with Can Manage may transfer/discharge (test-plan matrix), the Active List
covers assigned patients only (DATABASE-AND-BEHAVIOR.md), and CTL-06's wording.

## Second pass — the rows the first pass could not run, and the real system (same day)

The owner asked for the remaining rows to be completed too, noting that test accounts and test data are
temporary (the database is wiped and re-migrated from the legacy system at cutover). Two limits still
held, by design: **nobody typed a password, authenticator code or recovery code into the site** (a hard
rule for the assistant, even with the owner's permission), and **nothing was ever signed in to on the
live site** — there is no way in without a password, and pulling real patient screens into the
assistant would move patient data out of the Kingdom.

**How.** Three more isolated local servers, each on its own copy of the fake demo data and set up like
production (debug off, sessions and cache in the database, encrypted sessions, CSP enforced):
- **Credential journeys as automated test code** — a scripted HTTP client with synthetic accounts it
  created itself, codes computed from their own secrets, and emailed codes read from the local mail log
  (AUTH-01 … AUTH-37, CTL-06/07/09, AUD-04, NF-09, plus login by email, forgot-username, trusted
  device, deleted-user eviction, and a server-side check that nobody can register as Admin).
- **The leftover functional rows** — bulk import committed (IMP-01 … 06), shuffle with hospitalist and
  subspecialty pools, next-day boundaries (reverse discharge / sign-off refused the next day, undo
  medical discharge, the handover reminder, the "New" flag, the readmission-window edge), Section 15
  (odd input, concurrent edits, an aborted request, impossible dates, back / refresh), NF-10 (a
  discharge just after midnight) and NF-13 (double submit).
- **A browser pass** — every sign-in screen rendered and audited with axe without entering a
  credential; phone and tablet widths, dark mode, keyboard-only, command palette, unsaved-changes
  guard, 404 / 403, print styles and page timings.
- Then the gaps a coverage critic listed: a second consultant refused on another's patient (discharge,
  transfer, sign-off), a resident unable to raise their own role through Profile, no route that edits
  or deletes the audit log, and every PDF / CSV / XLSX download opened and checked.

**The real system (read-only).** On the live site, without signing in: http redirects to https, there is
no "remember me", the sign-up roles exclude Admin, an unknown account gets the same "if that account
exists" message, and unknown pages get a friendly 404. On the production database, through scripts that
run inside a **read-only transaction** (the database itself refuses any write, the run is rolled back,
and only numbers are printed):

| Check | Result |
|---|---|
| Every Dashboard, Statistics (last full month and year to date), Consultation dashboard, ledger and Active List figure — the app's own value against an independent recomputation written from the metrics doc | **52 / 52 match** |
| Every signed-in page, as a real user of each role present (Admin, Registrar, Consultant, Resident — production has no Observer account), through the full middleware stack | **148 requests: 0 server errors, 0 access-rule breaches, 0 writes** |
| Page time on the real volume (17,435 patients, 37,662 episodes) | median 11 ms, 90% under 135 ms, slowest ordinary page 0.7 s (Statistics) — **except Patient Merge: 57–63 s** (defect 10) |
| Data integrity: inverted dates, two open episodes for one patient, ages outside 0–150, drifted consultations, orphan diagnoses, outcomes outside Alive / Dead | all 0 |
| Application log since the last deploy | 0 server errors, 0 CSP violations, 0 warnings |

| Area | Rows | Pass | Failed, fixed now | Partial / by design | Not run |
|---|---:|---:|---:|---:|---:|
| Credential journeys (scripted) | 47 | 44 | 1 | 2 | 0 |
| Functional leftovers | 21 | 20 | 1 | 0 | 0 |
| Browser / non-functional | 15 | 10 | 3 | 1 | 1 |
| Coverage-critic gaps (authorization, audit, downloads) | 14 | 14 | 0 | 0 | 0 |
| Live site, public surface | 5 | 5 | 0 | 0 | 0 |
| Production, read-only (figures + page loads) | 200 | 199 | 1 | 0 | 0 |

The one "not run" row is NF-04 (Arabic / RTL): the app offers no language switch. "Partial / by
design": AUTH-07 — closing the tab or the browser does not end the session by itself (the cookie lasts
the session lifetime; the 30-minute idle timeout ends it; there is no "remember me") — the row is
reworded, and whether shared ward computers should drop the session when the browser closes is left to
the owner; the MFA challenge's 8-try cap is never reached because a stricter 5-per-minute limit trips first
(AUTH-05 reworded); and NF-11 — the print styles were checked, paper printing needs a person.

### Defects found in the second pass and fixed

| # | Severity | Defect | Fix |
|---|---|---|---|
| 9 | Major | A discharge dated before the admission (medical, complete or ICU discharge), or a completion dated before the medical discharge, crashed with a server error: the database refused it, but the app had not checked first. Nothing was stored. The same gap existed in Modify (moving the admission date after a recorded discharge) | Each action and Modify now check the date order first — a field message, nothing written (the same day is allowed). Any database-rule violation that still slips through becomes a plain "not saved" message instead of a server error |
| 10 | Major | Admin → Patient Merge took **57 s** on the real volume (a patient-to-patient comparison that no index could serve), over the 60 s limit for web requests, so on the live site the page could not open. Invisible on local data | The finder first picks the few candidate MRN / name keys, then compares only those rows: **116 ms** on production with identical results (14 pairs) |
| 11 | Minor | Forgot-username never showed its confirmation after submitting | The confirmation is shown |
| 12 | Minor | Light-mode grey help text (3.3:1) and teal link / step text (3.9:1) were below the WCAG AA 4.5:1 minimum | The grey text token is darkened in place (4.6–5.0:1); brand text uses the darker teal already used for text elsewhere; the contrast gate now covers both |
| 13 | Minor | On phones and tablets the page title was clipped or squeezed out of the header (a fixed-height header whose right-hand controls never shrank) | The header grows with its content, the breadcrumb trail hides on phones, and the wide search box, "Live" chip and user name collapse to icons below 1280 px |
| 14 | Minor | On phones and tablets the closed navigation drawer's links were still reachable with Tab (invisible links) | The closed drawer is out of the Tab order; opening it still moves focus in, and Escape returns it |

Before shipping, the fixes went through an adversarial review (four independent reviewers, each finding
re-checked by a separate skeptic). It confirmed three more issues in the fixes themselves, all fixed:
the new duplicate finder had capped its candidate-key pass at 50, which could silently drop whole
duplicate groups once there are more than 50 (the cap now applies only to the pair list, as before,
in a stable order); the Modify date message could read "(today)" when both date rules failed; and the
profile link lost its accessible name below 1280 px. A last axe pass also named the merge list's action
column for screen readers.

Doc corrections the second pass prompted: the "New" badge is a managed flag, not a 24-hour timer
(CLAUDE.md, DATABASE-AND-BEHAVIOR.md, ADM-12); the same-day handover rule is a soft gate with a
reminder, never a block (CLAUDE.md, ADM-11); AUTH-05 and AUTH-07 reworded to what the app does.

### What still needs a person on the live site

Everything above was proven either by automation on a copy or read-only on production. What remains
genuinely needs a signed-in human with real devices:

- **PRE-02 / AUTH-04, 08–11, 19–20, 34** — a real authenticator app on a real phone (enrol, sign in with
  it, use one recovery code, step-up).
- **PRE-03 / AUTH-13, 17, 25–27, CTL-09** — a real mailbox, which also proves the outgoing mail relay
  delivers verification codes and reset links.
- **PRE-07 / NF-01, NF-02, NF-11** — a real phone, a real tablet and a real printer.
- **SMK-01 … 06 and one short pass per role** on the real data, by the people who will use it.
- **The Go / No-Go table** — only the owner and clinicians can sign it.

Test accounts for this are created by the testers themselves through the sign-up page (that is part of
the test) and activated by an admin; deactivate them afterwards.

## Not covered (after both passes)

- Arabic / RTL layout (NF-04): the app has no language switch to test.
- Everything in "What still needs a person on the live site" above.
