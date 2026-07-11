# DMC Internal Medicine — Patient-Flow Hub · Hands-On Test Plan (UAT)

> **Purpose:** a complete, tick-through checklist for a hands-on testing team to validate every
> operational function of the web application before (and after) go-live. Work top to bottom; a
> section can be split across testers. Record a result for **every** row.
>
> **Scope:** the live web app (login → clinical workflows → admin → reports). Out of scope:
> infrastructure/ops (TLS setup, backups, server config) and the old legacy PHP screens.

---

## How to use this document

**Result column** — mark each row:

| Mark | Meaning |
|---|---|
| **P** | Pass — behaved exactly as the Expected column says |
| **F** | Fail — did not behave as expected (raise a bug, put its ID in Notes) |
| **B** | Blocked — couldn't test (say why in Notes) |
| **N** | Not applicable / not enabled in this environment |

**When something fails, raise a bug with:**
- **Title** · **Steps to reproduce** (numbered) · **What happened** · **What you expected**
- **Severity:** **S1** app unusable / data loss / security hole · **S2** major function broken, no workaround · **S3** minor function broken, workaround exists · **S4** cosmetic/text
- **Evidence:** screenshot or short screen recording · browser + version · account/role used · date/time · the URL (but **never paste a patient's name or MRN into a bug tracker** — use the row `ID` instead)

**Golden rules for testers**
- This system holds **real patient data (PHI)**. Do not export, screenshot patient identifiers into external tools, or share records outside the test.
- Prefer a **staging/test copy** with test patients. If testing on production, use clearly-fake test patients (e.g. name `ZZ-TEST`) and **delete/clean them afterwards**.
- Test in **at least two browsers** (e.g. Chrome + Edge/Safari) and note the browser on any failure.

---

## Prerequisites & environment setup (do this first)

| ID | Item | Ready? (P/F/N) | Notes |
|---|---|---|---|
| PRE-01 | App reachable over **HTTPS** (padlock shown); typing the plain `http://` address redirects to `https://` | | |
| PRE-02 | An **authenticator app** installed on a phone (Google Authenticator, Microsoft Authenticator, Authy, etc.) — **required**, MFA is mandatory for every user | | |
| PRE-03 | A way to receive **email verification codes**: either a real mailbox for each test account, or (on staging) access to the app log where codes are written | | |
| PRE-04 | Test data present: a realistic set of patients/admissions/consultations to exercise lists, search, and reports | | |
| PRE-05 | **Test accounts provisioned — one per role, MFA enrolled** (see table below). An admin creates and activates them | | |
| PRE-06 | Correct **bed counts** set (Control → Settings) so occupancy shows sensible numbers | | |
| PRE-07 | Device mix available: a **desktop**, and a **phone or tablet** (for responsive checks) | | |

### Test accounts to prepare (fill in credentials; do NOT commit real passwords)

| Role | Suggested username | Capabilities to set | Purpose |
|---|---|---|---|
| **Admin** | `test_admin` | all | full access, admin-only areas |
| **Registrar** (role 2) | `test_registrar` | Can Add, Can Assign, Can Modify | admissions + assignment |
| **Consultant** (role 3) | `test_consultant` | Can Manage | owns patients, sign-off, "My" views |
| **Consultant #2** (role 3) | `test_consultant2` | (none) | to test "not the primary consultant" denials |
| **Resident** (role 4) | `test_resident` | Can Modify | limited clinical |
| **Observer** (role 5) | `test_observer` | (none) | read-only board only |

> Each account must complete **email verification + authenticator enrolment** at first login (that's part of the test — see AUTH section). New self-registered accounts start **inactive** until an admin activates them.

---

## Section 0 — Smoke test (5 minutes, run first)

If any of these fail, stop and report — deeper testing is pointless until they pass.

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| SMK-01 | Open the site, sign in as **Admin** (complete MFA) | Lands on the Dashboard | | |
| SMK-02 | Dashboard shows census/KPI numbers and charts render | Believable numbers, no blank/broken charts, no error banner | | |
| SMK-03 | Open **Active Patients** | Patient board loads with rows | | |
| SMK-04 | Open **Registry**, search for any patient | Results return | | |
| SMK-05 | Open **Reports → download a PDF** | A PDF downloads and opens | | |
| SMK-06 | Log out | Returns to the sign-in page | | |

---

## Section 1 — Authentication & account lifecycle

### 1a. Login & MFA

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUTH-01 | Sign in with a **valid** username + password | Proceeds to the MFA step (or dashboard if already in session) | | |
| AUTH-02 | Sign in with a **wrong** password | Rejected with a generic error; **not** told whether the username exists | | |
| AUTH-03 | Enter the wrong password repeatedly (~6+ times quickly) | After a few tries, further attempts are **rate-limited** ("too many attempts") | | |
| AUTH-04 | At the MFA challenge, enter the **correct** 6-digit authenticator code | Signs in, lands on Dashboard | | |
| AUTH-05 | At the MFA challenge, enter a **wrong** code several times | Rejected; after ~8 tries it stops accepting and sends you back to sign in | | |
| AUTH-06 | Confirm there is **no "Remember me"** option on the login page | Absent (persistent login is intentionally disabled) | | |
| AUTH-07 | Sign in, close the tab **without** logging out, reopen the site | You are asked to sign in again (no persistent auto-login) | | |

### 1b. First-time MFA enrolment (existing user without MFA)

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUTH-08 | Sign in as an account that has **not** set up MFA yet | Forced to an **MFA setup** page before reaching any app page | | |
| AUTH-09 | Scan the QR with the authenticator app, enter the shown code | Enrolment confirmed; **recovery codes** are displayed to save | | |
| AUTH-10 | Note the recovery codes, finish setup | Reaches the Dashboard; next login now asks for the authenticator code | | |
| AUTH-11 | Log out and back in, at the challenge use a **recovery code** instead of the app code | Accepted once; the **same recovery code is rejected if reused** | | |

### 1c. Email verification (existing user with an unverified email)

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUTH-12 | Sign in as an account whose email is on file but **unverified** | Forced to an **email-verification** page | | |
| AUTH-13 | Click "send code", retrieve the emailed 6-digit code, enter it | Verified; proceeds into the app | | |
| AUTH-14 | Request a second code immediately | Told to wait (a resend cool-down applies) | | |
| AUTH-15 | Enter a wrong email code several times | Rejected; after ~5 tries you must request a fresh code | | |

### 1d. New self-registration (the phased sign-up)

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUTH-16 | From the login page, open **Create account** | Registration form appears | | |
| AUTH-17 | Enter an email, click **Send code** | A code is emailed; a code-entry field appears | | |
| AUTH-18 | Enter the emailed code, confirm | Email marked verified; the email field **locks**; the rest of the form unlocks | | |
| AUTH-19 | Complete username, full name, role, password; reach the **authenticator setup** step | QR + recovery codes shown; a confirm-code field appears | | |
| AUTH-20 | Enter a valid authenticator code to confirm | Confirmed; the **Create account** button becomes enabled | | |
| AUTH-21 | Verify **Create account is disabled** until *both* email and authenticator are confirmed | Cannot submit early | | |
| AUTH-22 | Submit | Account created; message says an **administrator must activate** it before sign-in | | |
| AUTH-23 | Try to sign in with the new (inactive) account | Blocked until an admin activates it | | |
| AUTH-24 | Confirm you **cannot** self-register as an Admin (role choices exclude Admin) | Admin is not selectable | | |

### 1e. Forgot / reset password

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUTH-25 | "Forgot password", enter a **username** (not email) | Generic "if that account exists, a link was sent" — same message whether it exists or not | | |
| AUTH-26 | Retrieve the reset link, set a new password | Success; can sign in with the new password | | |
| AUTH-27 | Try the **same** reset link again | Rejected (single-use) | | |
| AUTH-28 | **(Security)** Sign in on Browser A, keep the session open. From Browser B, reset that user's password. Refresh Browser A | Browser A's session is **logged out** (a stolen session must not survive a reset) | | |

### 1f. Change password / profile / session / step-up / logout

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUTH-29 | Profile → change password with a wrong "current password" | Rejected | | |
| AUTH-30 | Change password to a valid new one | Success; other open sessions for this user are logged out (see AUTH-28 pattern) | | |
| AUTH-31 | Try to change to the **same** password | Rejected ("choose a different password") | | |
| AUTH-32 | Profile → edit full name / username / email, save | Saved and reflected across the app (name in header) | | |
| AUTH-33 | Sit idle on a page for the idle-timeout window (~30 min) | A **session-timeout warning** appears; continuing eventually forces re-login | | |
| AUTH-34 | Trigger a **sensitive action** (e.g. delete an admission, reverse a discharge) as Admin | Prompted for **step-up re-auth** (password + authenticator) before it proceeds | | |
| AUTH-35 | At step-up, enter a wrong password many times | Rejected and rate-limited/capped | | |
| AUTH-36 | If a password is older than the expiry window (3 months) | On login the user is forced to **change password** first | | |
| AUTH-37 | Click **Log out** | Session ends; back button does not re-enter the app | | |

---

## Section 2 — Roles & access control (authorization matrix)

> The renovation's headline fix was **server-side authorization**. For each cell, sign in **as that role** and confirm the action is **allowed (✓)** or **denied (✗)**. A denial must be a real block (error / not-visible / 403), **not** just a hidden button. Confirm the mapping against the unit's own policy and flag any mismatch.

| Area / action | Admin | Registrar | Consultant | Resident | Observer | Result | Notes |
|---|:--:|:--:|:--:|:--:|:--:|---|---|
| See the Active Patients board | ✓ | ✓ | ✓ | ✓ | ✓ (read-only) | | |
| Add a new admission | ✓ | ✓ (if Can Add) | ✓ (if Can Add) | ✓ (if Can Add) | ✗ | | |
| Assign a patient to a consultant | ✓ | ✓ (if Can Assign) | ✓ (if Can Assign) | ✗ | ✗ | | |
| "Assign to me" | ✓ | — | ✓ | — | ✗ | | |
| Modify a patient's details | ✓ | ✓ (if Can Modify) | ✓ (if Can Modify) | ✓ (if Can Modify) | ✗ | | |
| Transfer / discharge a patient | ✓ | ✗ | ✓ (own patient **or** Can Manage) | ✗ | ✗ | | |
| Reverse a discharge (same-day undo) | ✓ (step-up) | ✗ | ✗ | ✗ | ✗ | | |
| Delete an admission | ✓ (step-up) | ✗ | ✗ | ✗ | ✗ | | |
| Add a consultation | ✓ | ✓ | ✓ | ✓ | ✗ | | |
| Sign off a consultation | ✓ | ✗ | ✓ (own **or** Can Manage) | ✗ | ✗ | | |
| Delete a consultation | ✓ | ✗ | ✗ | ✗ | ✗ | | |
| Registry (search/export) | ✓ | ✗ | ✗ | ✗ | ✗ | | |
| Statistics / Reports | ✓ | ✗ | ✗ | ✗ | ✗ | | |
| Control panel (settings/users) | ✓ | ✗ | ✗ | ✗ | ✗ | | |
| Bulk import | ✓ | ✗ | ✗ | ✗ | ✗ | | |
| Patient-merge / Data-quality / Audit | ✓ | ✗ | ✗ | ✗ | ✗ | | |

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUTHZ-01 | As **Observer**, confirm every edit/action button is absent or disabled across the board | Read-only everywhere | | |
| AUTHZ-02 | As a **non-admin**, directly type an admin URL (e.g. `/control`, `/statistics`, `/registry`) into the address bar | Blocked (403 / redirect), **not** shown | | |
| AUTHZ-03 | As **Consultant #2** (not the primary), try to sign off / discharge **another** consultant's patient without Can Manage | Denied | | |
| AUTHZ-04 | As a non-admin, confirm you cannot elevate your own role or capabilities anywhere in Profile | No such option; no way to self-promote | | |

---

## Section 3 — New admissions & assignment

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| ADM-01 | New Admission → fill bed, MRN, name, age, gender, nationality, admit date, admit-from, diagnosis | Saved; appears in the **unassigned/New Admissions** queue | | |
| ADM-02 | Diagnosis field: type part of an ICD-10 term | A **typeahead** suggests matching ICD-10 codes; can pick one or more | | |
| ADM-03 | Submit with a required field blank | Validation error; not saved | | |
| ADM-04 | Enter an MRN that already has an **active** admission | Rejected as a duplicate active admission | | |
| ADM-05 | Enter an invalid MRN (letters / far too long) | Rejected with a clear message | | |
| ADM-06 | Add a patient **coming from ICU** via the queue's ICU option | Recorded with the ICU origin | | |
| ADM-07 | On a queued (unassigned) patient, use **Modify** to correct a field | Edit saves | | |
| ADM-08 | **Assign** a queued patient to a consultant (with Can Assign) | Moves off the queue onto that consultant's active list | | |
| ADM-09 | **Assign to me** as a consultant | Patient assigned to you | | |
| ADM-10 | Run the **auto-assign / shuffle** (balancing) | Unassigned patients are distributed across consultants; result looks balanced; no error/debug text on screen | | |
| ADM-11 | **Bulk change-consultant**: reassign several patients from consultant A to B | All move; a **handover preflight** warns about un-refreshed handovers first | | |
| ADM-12 | Newly-assigned patient shows the **"New"** badge for ~24h | Badge present, then clears | | |

---

## Section 4 — Active patients board & flow

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| FLOW-01 | Open the Active board; switch between Ward / ICU / ER views (as applicable) | Correct patients under each | | |
| FLOW-02 | Open **active-list** (read-only board, opens in a new tab) | Loads independently | | |
| FLOW-03 | **Modify** a patient (demographics + diagnoses) | Changes save and display | | |
| FLOW-04 | **Transfer** ward → ICU | Patient shows in ICU; a new episode/row is created; audit trail intact | | |
| FLOW-05 | **Transfer** to another specialty | Recorded correctly; not lost from the board unexpectedly | | |
| FLOW-06 | **Medical discharge** (phase 1) | Patient marked medically-discharged but still present/pending complete | | |
| FLOW-07 | **Complete discharge** (phase 2), set outcome (Alive/Dead/LAMA…) | Patient leaves the active board; discharge recorded with outcome | | |
| FLOW-08 | **ICU discharge** (single step) on an ICU patient | Handled correctly | | |
| FLOW-09 | **Reverse a discharge** (Admin, step-up) on a same-day discharge | Patient returns to active; dates cleared; audited | | |
| FLOW-10 | Label a patient **Long-term**; open the **Long-term** view | Appears in that filtered list; toggle works | | |
| FLOW-11 | Open **TB patients** (active) — a TB-classified diagnosis | Only TB patients listed | | |
| FLOW-12 | A patient re-admitted within 72h shows a **72h-readmission** badge | Badge present on the right patients | | |
| FLOW-13 | Patient flags / diagnosis chips render correctly and are readable | Correct, legible | | |
| FLOW-14 | Confirm the URL **does not contain the patient's MRN** while viewing a record | MRN not in the address bar | | |

---

## Section 5 — Handover & notifications

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| HND-01 | Open a patient's **handover**; read current text + revision history | Loads; shows last updates + author | | |
| HND-02 | As the primary consultant / Can Manage, **edit** the handover, save | Saves; a new revision is appended | | |
| HND-03 | As **Observer**, confirm handover is **read-only** | Cannot edit | | |
| HND-04 | Do a gated transfer that requires a **handover signature** | A signature request appears in the receiving consultant's inbox | | |
| HND-05 | As the receiving consultant, open **Handovers inbox**, **sign** a pending handover | Marked signed; timestamped | | |
| HND-06 | **Sign many** at once | All selected signed | | |
| HND-07 | The **notification bell** shows unread items; open it | Latest notifications listed with an unread count | | |
| HND-08 | Click "mark all read" | Unread count clears | | |

---

## Section 6 — Consultations

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| CON-01 | **New consultation**: pick from/to service, indication(s), other-indication text | Saved; appears in the active consultations list | | |
| CON-02 | Filter **My consultations** vs all | Filter narrows correctly | | |
| CON-03 | **Modify** a consultation | Changes save | | |
| CON-04 | **Sign off** a consultation (as primary consultant / Can Manage) | Marked signed-off with today's date | | |
| CON-05 | As a role **without** rights, confirm sign-off/delete are denied | Denied | | |
| CON-06 | **Delete** a consultation (Admin) | Removed; audited | | |
| CON-07 | **48-hour consultation registry** (Admin): undo a same-day sign-off | Sign-off reversed | | |

---

## Section 7 — Registry & export

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| REG-01 | Registry → search **admissions** by name / MRN / date range | Correct results | | |
| REG-02 | Registry → search by **diagnosis** | Correct results | | |
| REG-03 | Registry → search **consultations** | Correct results | | |
| REG-04 | Apply the full filter set (dates, location, consultant, etc.) | Filters combine correctly | | |
| REG-05 | Click a **sortable column header** | Table re-sorts (ascending/descending) server-side; paging still correct | | |
| REG-06 | Open a **patient detail** from results, then **edit** a record | Detail shows; edit saves | | |
| REG-07 | **Export to Excel (.xlsx)** | A valid spreadsheet downloads and opens; columns/values correct | | |
| REG-08 | Open the **48h discharge registry** (Admin) and undo a same-day discharge | Discharge reversed | | |
| REG-09 | Open the **TB discharge registry**; export it | Correct TB set; export works | | |
| REG-10 | Large result set → paging | Pages load quickly, no timeout, counts correct | | |

---

## Section 8 — Dashboard

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| DSH-01 | KPI cards show current census, admissions/discharges today, etc. | Numbers are believable and internally consistent | | |
| DSH-02 | **Bed occupancy** reflects the configured ward/ICU bed counts | Sensible % (not obviously wrong) once beds are set | | |
| DSH-03 | Charts render (admissions/discharges trend, per-consultant, etc.) | All render; no blank/broken chart | | |
| DSH-04 | **Top diagnoses this week** card | Shows a plausible list | | |
| DSH-05 | Per-consultant activity table | Correct per consultant | | |
| DSH-06 | Leave the dashboard open a few minutes | It **auto-refreshes** (numbers update) without a manual reload | | |
| DSH-07 | Click a KPI/deep-link that jumps to a filtered list | Lands on the right filtered view | | |
| DSH-08 | Cross-check one KPI number against the matching Registry search count | They agree | | |

---

## Section 9 — Statistics, Reports & PDF

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| STA-01 | Open **Statistics**; all KPI tiles/charts populate | No errors; numbers plausible | | |
| STA-02 | Toggle the **interval** (e.g. monthly/quarterly/yearly) | Data updates for the selected period; no cross-year bleed | | |
| STA-03 | Per-physician / per-consultant breakdowns | Correct attribution | | |
| STA-04 | Metrics to eyeball: admissions, discharges, transfers-to-ICU, mortality, consultations, sign-offs, LOS, 72h-readmissions | All present and sane | | |
| STA-05 | **Reports** page: monthly view with richer columns (incl. Long-Stay-%) | Renders correctly | | |
| STA-06 | **Download the A4 PDF** (yearly) | PDF downloads, opens, is A4, includes charts, numbers readable | | |
| STA-07 | **Download the monthly PDF** | Same | | |
| STA-08 | Compare a headline stat (e.g. discharges in a month) with an independent Registry count | They match | | |
| STA-09 | Reports/stats load in a **reasonable time** on the full dataset (no timeout/500) | Acceptable | | |

---

## Section 10 — Control panel (Admin)

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| CTL-01 | Control → **Settings**: set ward & ICU **bed counts**, save | Saved; dashboard occupancy updates | | |
| CTL-02 | Change LOS thresholds / min-max staffing / readmission window / alert thresholds | Saved; reflected in stats/alerts | | |
| CTL-03 | Confirm the **MFA enforcement** control notes it is **mandatory for all** (setting is inert) | Shown as mandatory-for-everyone | | |
| CTL-04 | **Settings history**: after changing bed counts, view the change log | The change is recorded (who/when/old→new) | | |
| CTL-05 | **Reference data**: add/edit/remove a specialty and a consultation reason | CRUD works; dropdowns update | | |
| CTL-06 | **Users**: create a new user with a role + capabilities | Created; can then be activated | | |
| CTL-07 | **Users**: activate / deactivate an account | Deactivated user can no longer sign in | | |
| CTL-08 | **Users**: edit capabilities (Can Add/Assign/Manage/Modify) | Takes effect for that user immediately | | |
| CTL-09 | **Users**: admin-trigger a password reset / MFA reset for a user | User is forced to reset/re-enrol at next login | | |
| CTL-10 | **Report recipients**: add an email; adding a **duplicate** is rejected | Add works; dup blocked | | |
| CTL-11 | Turn on **record-open logging** (log_record_opens); open a patient detail; check the Audit log | A break-glass "record open" entry is written | | |

---

## Section 11 — Bulk import (Admin)

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| IMP-01 | Bulk import → paste/upload a CSV of old patients | Accepted for preview | | |
| IMP-02 | **Preview** shows valid vs invalid row counts before committing | Counts shown; nothing written yet | | |
| IMP-03 | Include a bad row (inverted dates / duplicate active MRN / bad transfer type) | Flagged invalid with a reason; not imported | | |
| IMP-04 | **Confirm** the import | Only the valid rows are committed; count matches the preview | | |
| IMP-05 | Duplicate diagnosis codes in a row | De-duplicated on import | | |
| IMP-06 | Re-open the imported patients in the board/registry | Present and correct | | |

---

## Section 12 — Patient merge & data quality (Admin)

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| DQ-01 | Open the **Data-quality dashboard** | Lists data issues (bad ages, dates, orphan diagnoses, etc.) | | |
| DQ-02 | **Patient-merge / MRN-dedup**: merge two records for the same patient (step-up required) | Merged; history preserved; audited | | |
| DQ-03 | **Orphan diagnoses** view | Lists diagnoses not tied to a valid code; actionable | | |

---

## Section 13 — Audit & security (Admin)

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| AUD-01 | Open the **Audit log** | Chronological, shows actor + action + entity + time | | |
| AUD-02 | Perform an action (e.g. discharge), then find it in the Audit log | Recorded, attributed to **you** (the logged-in user), correct time | | |
| AUD-03 | Confirm audit rows can't be edited/deleted from the UI | No such option (append-only) | | |
| AUD-04 | **Security panel**: review login anomalies / failed-login signals | Populates on repeated bad logins | | |
| AUD-05 | **Trashed** (soft-deleted) view: a deleted record appears here, and is **excluded** from normal lists/stats | Correct separation | | |

---

## Section 14 — Non-functional & cross-cutting

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| NF-01 | Resize to **mobile** width (or use a phone) | Layout adapts; nav collapses; nothing overflows/overlaps; no horizontal scroll | | |
| NF-02 | Resize to **tablet** width | Adapts cleanly | | |
| NF-03 | Toggle **dark mode** | All text remains readable; no invisible/low-contrast text | | |
| NF-04 | Switch to **Arabic / RTL** (if offered) | Layout mirrors correctly; text readable | | |
| NF-05 | **Keyboard only** (no mouse): tab through a key page and a modal | Focus is visible and logical; modals trap focus; Esc closes | | |
| NF-06 | **Command palette** (Ctrl/Cmd-K) | Opens; searching jumps to pages/patients | | |
| NF-07 | Start editing a form, then navigate away without saving | An **unsaved-changes** guard warns you | | |
| NF-08 | Visit a non-existent URL | A friendly **404** page (not a raw error) | | |
| NF-09 | Let a form sit until the CSRF/session expires, then submit | Handled gracefully (re-auth / friendly message), **not** a raw 500 | | |
| NF-10 | Dates display in the **hospital's timezone**; a discharge done just after midnight counts on the correct day | Correct day attribution | | |
| NF-11 | **Print** a report/board | Prints cleanly (A4, no clipped content) | | |
| NF-12 | Throughout testing, watch for slow pages / spinners that never finish | All pages load in a reasonable time under real data volume | | |
| NF-13 | Double-click a submit button quickly | The action is **not** duplicated (no double admission/discharge) | | |

---

## Section 15 — Negative & resilience spot-checks

| ID | Steps | Expected | Result | Notes |
|---|---|---|---|---|
| NEG-01 | Enter odd characters (quotes, `<script>`, emoji, very long text) in name/notes fields | Stored/escaped safely; no broken layout, no script pop-up, no server error | | |
| NEG-02 | Two testers edit the **same** patient at the same time | No data corruption; last-write or a sensible conflict message | | |
| NEG-03 | Submit a discharge date **before** the admit date | Rejected (impossible dates blocked) | | |
| NEG-04 | Enter an implausible age (e.g. 999) | Rejected or normalized, never stored as-is | | |
| NEG-05 | Lose network mid-action (toggle wifi), then retry | No half-saved/corrupt state; a clear error and clean retry | | |
| NEG-06 | Back button after a submit | Does not re-submit or show stale data as current | | |

---

## Overall sign-off

| Area | Owner | Date | Result (P/F) | Notes |
|---|---|---|---|---|
| Authentication & accounts | | | | |
| Roles & access control | | | | |
| Admissions & assignment | | | | |
| Active board & flow | | | | |
| Handover & notifications | | | | |
| Consultations | | | | |
| Registry & export | | | | |
| Dashboard | | | | |
| Statistics / Reports / PDF | | | | |
| Control panel (admin) | | | | |
| Bulk import | | | | |
| Merge / data quality | | | | |
| Audit & security | | | | |
| Non-functional | | | | |

**Go / No-Go recommendation:** ______________________  **Signed:** ______________  **Date:** __________

> **Reminder:** any **S1/S2** failure (security, data loss, or a broken core clinical workflow) is a **go-live blocker**. Clean up any test patients created on production before closing.
