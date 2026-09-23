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

## Not covered

- Sign-in, MFA challenge and enrolment, email verification, registration, forgot/reset/change password
  (need typed credentials — covered by the automated suite).
- Section 14 layout checks (mobile, tablet, dark mode, Arabic/RTL, keyboard-only) and most of Section 15
  (odd input, concurrent edits, network loss).
- Committing a bulk import; subspecialty shuffle pools; next-day boundaries (e.g. reverse sign-off
  refused the following day).
