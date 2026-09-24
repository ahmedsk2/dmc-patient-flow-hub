# DMC Internal Medicine Patient-Flow Hub — Role-by-Role Usability & Function Test

*Local test copies, synthetic/fake data. Reviewed and spot-checked against the code after the run (corrections marked). 10 testers covering all 5 roles (Admin, Registrar, Consultant, Resident, Observer) plus dedicated statistics-accuracy and UI/self-explanation passes. 2026-09-24.*

## Resolution (same day, 2026-09-24)

The owner asked to "fix all and add the ! marks, remove the login stats". Done on branch
`feat/ux-review-fixes-and-info-marks`, each change reviewed adversarially and tested:

- **"!" marks:** a new `Components/InfoTip.vue` (real button; hover, keyboard focus, tap to pin, Escape;
  readable in light and dark mode; hidden in print) and **64 marks** across the dashboard, board,
  admission form and queue, discharge/transfer dialogs, handovers, consultations, statistics, registry,
  reports, recent activity, audit, control panel and admin pages, plus full-text hover labels on the
  checkpoint and status chips. Every text was checked against the code and the behaviour/metrics docs.
- **Login page:** the "22 hospitals / 3,400 beds / 24/7 live" figures are removed.
- **Problems in the table below:** #1–#12, #14–#26, #28–#41 fixed. #13: the transfer message and "!" were
  made accurate, and then (owner decision the same day: "yes consultant can do that") a consultant can
  hand their own active patient to a colleague from the card, keeping the episode open, with the usual
  signature, notification and same-day reminder. #27's differing defaults were kept on purpose (first assignment vs reassignment)
  and each is now explained. #8 and #9 were labelled and explained, not redefined (the statistics stay
  as reconciled). #42 is corrected in CLAUDE.md. #5 now renders the drawn logo without probing for the
  missing file.
- **Found on the way and fixed:** the "!" component's Escape reopened it; the Dashboard's info
  buttons had been nested inside links/buttons (invalid HTML); protected rows (specialty 1, the
  Hospitalist pool, and indication 0, "Other") could have been deleted once unused.

## 1. Summary

- **Every role's server-side permission model held.** Across ~350 function checks and 35+ dedicated write-probe attempts as Observer, no case was found of a button being shown but the action refused, or hidden but actually allowed — client-side gating matches the server everywhere tested.
- **The one item worth fixing first is a data-integrity gap, not a crash:** readmitting a patient under an MRN that already exists silently overwrites that patient's name/age/gender/nationality — retroactively, across their entire admission history — with zero warning or lookup on the form (Admin/clinical finding).
- **Two admin-panel validation gaps can quietly corrupt shared data:** duplicate specialty/consultation-reason names are accepted and can never be edited or deleted, and Short LOS can be saved greater than Long LOS, silently breaking the LOS-colour system and the Long-Stay % statistic.
- **Statistics and reports are numerically accurate everywhere independently cross-checked** (Dashboard vs. Statistics vs. Reports vs. Registry all reconciled exactly, including non-obvious rules like readmission-credit-to-the-prior-consultant) — but "discharge" and "transfer" are quietly defined two different ways on two different pages, which will look like a bug to anyone cross-checking screens even though each page's own number is correct.
- **The cluster logo request 404s on every page** — but by design: `EhcLogo.vue` tries the official file first and draws its own vector logo when it is missing, so users always see a logo; the only effect is console noise until the official file is added (reviewer correction, see #5).
- **Self-explanation is inconsistent rather than absent.** Several areas (the Consultation ledger, the Discharge dialog, Control → System) are genuinely well-captioned already; others (checkpoint-chip abbreviations, the "Old/New" columns, several Dashboard tiles, a handful of capability checkboxes) have no on-page explanation at all — exactly the gaps the requested "!" info marks would close.
- **All four defects logged in an earlier walkthrough were independently re-verified fixed and holding this session**: Registry's consultation-status mislabeling, the missing "MFA is mandatory" disclosure, the missing `log_record_opens` UI control, and the Patient Merge picker that previously failed to render.
- **Coverage was broad but not exhaustive** — the internal Style Guide page was explicitly out of scope, and some findings (a demo-fixture missing a capability flag) reflect test-setup, not the live product; see the Coverage table.

---

## 2. Confirmed problems

Deduplicated across testers (roles that independently saw the same issue are listed together), ranked by severity then how many roles/pages it touches.

| # | Severity | Area / page | Problem | What happens vs. what should happen | Suggested fix |
|---|---|---|---|---|---|
| 1 | High | Admissions — MRN identity | Readmitting a known MRN silently overwrites the patient's identity | The overwrite is deliberate in code (`AdmissionsController::createAdmission` — "refresh demographics on the canonical record", latest details win), but the form never shows the stored details first: a mistyped name/age/gender/nationality on a readmission renames the patient across **every** past and future episode, with no lookup, prefill or warning. Should prefill the known demographics and ask before changing them. | Call the existing quick-search endpoint on MRN blur/submit; warn or lock before overwrite. |
| 2 | High | Control → Reference data | Duplicate specialty / consultation-reason names accepted, never correctable | Two identical rows can be created; no edit/delete route exists at all, so a typo is a permanent, app-wide duplicate in every specialty dropdown. Should reject duplicates (as Report Recipients already do) or allow a fix afterward. | Add case-insensitive uniqueness validation + PUT/DELETE routes for both resources. |
| 3 | High | Control → Settings | Short LOS can be saved greater than Long LOS | An inverted pair (e.g. Short=20, Long=11) saves with no error, silently corrupting the LOS colour-coding and the Long-Stay % statistic. | Add `lt:long_los` / `gt:short_los` validation. |
| 4 | High | Consultations — "To service" | Booking a consult into another team fails only after the whole form is filled | The own-specialty rule is deliberate and its message is clear ("You may only book consultations for your own specialty…" / "Your account is not attached to a specialty…", `ConsultationRequest::ownSpecialtyRule`), but the datalist offers every specialty and the refusal only appears on submit. How many real users hit it depends on how many registrar/resident accounts have no specialty set (the walkthrough accounts have none) — worth a read-only count in production. | Filter the datalist to the user's own specialty (or none), and warn before submit. |
| 5 | Low (reviewer-corrected from High) | Site-wide | Logo file request 404s on every page | By design `EhcLogo.vue` first requests the official `/images/ehc-logo.svg` and falls back to a drawn vector logo when it is absent — the file was never added, so every page logs a 404 in the console, but a logo is shown. | Add the official asset (see `public/images/BRAND_README.md`) or stop probing for it. |
| 6 | Medium | Patients / transfers | "Long-term" flag silently resets on any transfer | Marking a patient long-term, then transferring them (ward↔ICU or specialty), resets `is_longterm` to false on the new episode with no message. | Carry the flag forward on transfer, or prompt to reapply it. |
| 7 | Medium | Dashboard | "Active Consultations" tile is a dead end for Resident and Observer | The tile shows a unit-wide count and links to `/consultations`; a Resident's click lands on 0 of the visible rows (scoped ledger), an Observer's click gets a hard 403. | Drop the link for roles it can't help, or default the linked ledger to an unscoped view for roles that see everything. |
| 8 | Medium | Dashboard/Statistics vs. Recent Activity | "Discharge" means two different patient sets on two pages for the same day | Dashboard/Statistics count by current ward location (includes a ward→ICU transfer-close, excludes an ICU discharge); Recent Activity counts by `transfer_type` (the opposite pattern). Totals can match by coincidence while the actual patients differ. | Align the two definitions, or label each page's scope explicitly. |
| 9 | Medium | Registry / Statistics | "Out-dept transfer" label shown for an internal Ward→ICU move | Both a true external transfer and an in-unit Ward→ICU move write the same `transfer_type`, so an ICU move reads as if the patient left the department. | Give the Ward→ICU close its own transfer_type/label. |
| 10 | Medium | Reports → Monthly | Future days in the current month's report show as flat zero, same as a real zero-activity day | A reader can't tell "nothing happened" from "hasn't happened yet." | Grey out/omit rows after today, or add a "data through {date}" note. |
| 11 | Medium | Admin → Patient Merge | Final merge confirmation uses the browser's native `confirm()` | Every other destructive action (delete user, remove recipient, reset MFA, discharge) uses the app's own themed, accessible dialog; merge still doesn't, on two independent passes. | Swap in the app's existing confirm-dialog composable. |
| 12 | Medium | Handovers | A handover can be signed without ever being read | The single-row "Sign" button has no confirm/read-gate (unlike "Sign all," which does confirm); a blind sign succeeds identically to a normal one — a real soft spot in a safety-critical feature. | Add the same confirm step "Sign all" already uses, or require the row to be expanded once first. |
| 13 | Medium | Patients — Transfer | No dedicated hand-off action for a plain consultant, even within their own team | The only capability-free route to hand a patient to a named colleague is Transfer → Internal specialty, which closes/reopens the episode and shows a misleading "Patient transferred to X" even for a same-team move. | Open a scoped reassign action to the primary consultant on their own patient, or relabel the same-specialty transfer path. |
| 14 | Medium | Dashboard (admin) | "Pending Handovers" and "Handover Due (unit)" tiles are near-indistinguishable by wording | Two adjacent tiles count different things (unsigned signatures vs. no note saved today) with no caption on either. | Add a one-line caption naming what each tile counts. |
| 15 | Medium | Dashboard | Bed Occupancy can read well over 100% with no context | Observed 118–124%; the bed-count denominator is a documented placeholder, but nothing on the tile says so. | Add a one-line note on the tile. |
| 16 | Medium | Login page | Brand-panel stats don't match the app's actual scope | "22 hospitals / 3,400 beds / 24/7 live" is hard-coded in `Pages/Auth/Login.vue` for a tool that serves one Internal Medicine unit; the figures are not a confirmed fact anywhere in the project records (CLAUDE.md: truthful claims only) — confirm them for the cluster or remove them. | Remove, correct, or explicitly attribute to the wider cluster. |
| 17 | Medium | Control → Users | The five real permission checkboxes have zero inline explanation | Can assign/add/manage/modify/coordinate consults render as bare jargon, unlike every field on the Settings tab. | Add a one-line caption per checkbox. |
| 18 | Medium | Control → Users | Pending self-registrations look identical to deactivated accounts | Both show an identical "Disabled" badge; no registered-date or pending-signup counter, so a new sign-up can be missed. | Add a "Registered" date and/or a distinct "New sign-up" badge/counter. |
| 19 | Medium | Import | Committing a bulk historical import has no confirmation step | Can create a large batch of real admission rows in one click, unlike comparable high-impact admin actions elsewhere. | Add a lightweight confirm before the final POST. |
| 20 | Medium | Patients board | Cancelling an untouched Assign/Reassign dialog triggers a false "Discard changes?" warning | The guard compares against "any value present," not the dialog's own pre-filled defaults. | Compare against the dialog's initial values, not just non-emptiness. |
| 21 | Medium | Dashboard vs. board | Ward census figures visibly disagree (60 vs. 56) | The board header excludes patients still awaiting assignment; the Dashboard figure includes them. Nothing explains the gap. | Add a note on the board header, or reconcile the two numbers. |
| 22 | Low | Admissions / patient card | "Assign to me" gives no warning it grants full clinical-management rights | Intended, documented behaviour (self-assign = primary consultant) but no on-page hint before the click. | One-line note on the button/confirmation. |
| 23 | Low | Board cards / Handover editor / Handovers inbox | Checkpoint/code-status abbreviations (VTE, "D/C ready," DNR, DNI, TB) never expand | The full wording already exists in code but isn't wired to the compact chip's tooltip. | Add the existing full label as each chip's hover/tap title. |
| 24 | Low | Patient card | Card can show "no handover" while "Sign pending" is true | The note lives on the prior, now-discharged episode; only the pill signals it. | Info mark on the pill (see §5). |
| 25 | Low | Discharge modal | "System" (delay reason) and "LAMA" (destination) are unexplained | Every sibling option is plain English; these two aren't. | Spell out or add a tooltip to each. |
| 26 | Low | Board / filters | "Long-term" badge sits next to the LOS-day pill with nothing distinguishing manual flag from computed count | Easy to misread as automatic. | Info mark clarifying it's manual and resets on transfer. |
| 27 | Low | Admissions queue vs. board | "Mark as new patient" checkbox defaults opposite ways in the two assign dialogs | Same concept, unexplained opposite defaults. | Pick one consistent default, or explain each. |
| 28 | Low | Dashboard/board/Active List | "Old"/"New" columns are unexplained, and the metrics doc itself is wrong | Doc says "assigned within 24h"; code actually uses a managed flag cleared only on discharge/reassign. | Add a column tooltip; correct `DASHBOARD-AND-STATISTICS-METRICS.md` line 61. |
| 29 | Low | Dashboard | "Security Anomalies" tile is a bare, alarming red number | No caption or link; in this data every flagged item was a routine never-logged-in account. | Add a scope caption and a link to Security. |
| 30 | Low | Control → Settings | Several threshold fields have no caption while siblings do | Min/Max hospitalist/subspecialty census, Short/Long LOS, Licensed ICU beds. | Add matching captions. |
| 31 | Low | Control → Users | No hint how a new staff member gets an account | No "Add user" button and no explanatory note. | One-line note near the tab heading. |
| 32 | Low | Handovers | "Write" button shown to Observer with nothing to write | The destination board gives Observer zero controls; cosmetic only. | Gate or relabel for read-only roles. |
| 33 | Low | Top bar | "Live" pill has no tooltip | Ambiguous — auto-refresh vs. connection status. | One-line tooltip. |
| 34 | Low | Board banner | "N of your patients have no handover today" shown to roles owning zero patients | Reads as a personal alert that isn't (Observer, no-capability Resident). | Reword to unit-wide phrasing when the viewer owns none of them. |
| 35 | Low | Board / Active List (mobile) | Summary-table columns cut off at phone width with only a faint scrollbar | Active/Ward/ICU/TB columns easy to miss are scrollable. | Add a fade/chevron edge affordance. |
| 36 | Low | Patients board | "Boarding" filter chip has no definition, unlike the Dashboard's equivalent tile | Same concept, one page explains it, the other doesn't. | Match the Dashboard's caption. |
| 37 | Low | Discharge modal | Same field labelled "Discharge to" then "Destination" seconds later | Inconsistent wording within one flow. | Use one term throughout. |
| 38 | Low | Profile | "MFA" and "Two-factor authentication" both used for the same setting | Same page, two terms. | Pick one term. |
| 39 | Low | Statistics | All charts share one identical, generic aria-label | A screen-reader user can't tell one chart from another. | Wrap each chart in the app's existing per-chart accessible wrapper. |
| 40 | Low | Board → Assign | Generic Laravel error when assigning a Resident as consultant | "The selected consultant id is invalid" doesn't name the real rule. | Add a custom validation message. |
| 41 | Low | Statistics | Inverted date range silently auto-corrected, no on-screen notice | Data returned is correct, but the user isn't told their range was swapped. | Small inline note when the applied range differs from what was typed. |
| 42 | Low (docs only) | CLAUDE.md §7 | Written Observer page-scope is narrower than the app's actual, deliberate grant | Dashboard and Recent Activity are also open, read-only, by design; the doc doesn't say so. | Update the doc sentence so it isn't mistaken for a future regression. |

**Checked and not a problem** (verified as not-reproduced or by-design — listed for completeness):

- **Registrar can self-assign then manage a patient (transfer/discharge/handover) with no `can_manage` flag.** By-design: CLAUDE.md §7 and the code both explicitly grant the primary consultant this right regardless of role; only the generic UAT test-plan matrix (not the ground-truth doc) suggested otherwise.
- **Registrar/Resident test accounts can't book a consult into a named specialty.** The refusal itself is the intended own-specialty rule (the walkthrough accounts have no specialty set); the usability problem — the refusal comes only after submitting — is kept as #4.
- **Any clinical role can edit (not sign off or delete) any other user's filed consultation.** By-design, documented legacy-parity decision; sign-off/delete remain correctly gated.
- **A one-time Dashboard cache/board mismatch seen at the very start of one test session.** Not reproduced with confidence — every write in the running app correctly refreshes the cache; looks specific to how that one isolated test database was seeded, not a path reachable in normal use.

---

## 3. Statistics reflection

| Action | Where checked | Expected | Observed | Pass/Fail |
|---|---|---|---|---|
| Admit patients (ward/ICU/ER) | Queue + Dashboard/board stats | Queue and census rise by the exact amount admitted; admissions-today excludes ICU | Matched exactly, repeated by multiple testers | Pass |
| Assign / assign-to-me / shuffle | `/admissions` queue re-fetch | Queue → 0, shuffle only to on-service consultants | Matched | Pass |
| Ward↔ICU transfer | Dashboard ward/icu counts | ward/icu move by exactly 1 each | Matched | Pass |
| Two-phase discharge (medical → complete) | Dashboard, Statistics, Registry | Discharges +1; LOS = whole days | Matched (e.g. LOS 4 exact) | Pass |
| One-step ICU discharge, outcome=Dead | Dashboard (icu/census/deathsMonth) | icu −1, census −1, deathsMonth +1, destination forced Mortuary | Exact, repeated by 3 testers | Pass |
| Reverse a discharge / undo sign-off | Dashboard / Recent Activity | All KPIs return to prior values | Matched | Pass |
| Same-day readmission (window=3) | Statistics readmit KPI + per-consultant table | Flagged, credited to the **prior discharging** consultant even though the new episode is unassigned | Matched exactly (non-obvious rule confirmed correct) | Pass |
| Ward→ICU transfer's new episode | Board | Must NOT be flagged a readmission | Correctly false | Pass |
| Bulk reassign without same-day handover | Notifications bell | `handover.incomplete` reminder raised, move still proceeds (soft gate) | Confirmed 3 separate times | Pass |
| Delete + restore (admission/consultation/user) | Trash, then origin page | Disappears then reappears identically; audit logs step-up | Matched every time | Pass |
| Full consultation lifecycle | Ledger, Recent Activity, Consultation Dashboard | Each transition visible; sign-off requires the *receiving* consultant, not just a coordinator | Matched | Pass |
| Handover signature lifecycle (create→read→sign/blind-sign→supersede→void-on-discharge) | Notifications, Handovers inbox, board pill | All steps behave per design | All matched — but signing without reading is currently *allowed* (see Confirmed Problems #12) | Pass mechanically / flagged separately |
| Statistics vs. annual Reports, same range | `/statistics` vs `/reports` | Admissions/discharges/ICU/mortality identical | 393/340/60/26 (7.6%) identical, twice | Pass |
| Monthly report vs. Statistics, same month | `/reports/monthly` vs `/statistics` | Totals and the specific day row match | 141/111/19/13 identical; day-row matched | Pass |
| Registry search counts vs. known fixtures | `/registry` | Result count = actual rows created | Exact match | Pass |
| Dashboard/Statistics "Discharges today" vs. Recent Activity "Discharges" | Same day, both pages | Same population counted | Same **total** but a **different set** of patients (transfer-closes counted by one, excluded by the other) | **Fail** — Problem #8 |
| "Out-dept transfer" label on an internal Ward→ICU move | `/registry` | Label implies the patient left the department | Same label shown for an in-unit move | **Fail** — Problem #9 |
| Add the same specialty name twice | Control → Reference | Rejected or later correctable | Two permanent duplicate rows, no error | **Fail** — Problem #2 |
| Save Short LOS (20) > Long LOS (11) | Control → Settings | Rejected as an invalid band pair | Saved, persisted, no error | **Fail** — Problem #3 |
| Inverted date range (From after To) | `/statistics` | Handled without crashing | Silently swapped server-side; data correct but no on-screen notice | Data pass / notice missing (Problem #41) |
| Future / far-future date ranges | `/statistics` | Clean all-zero KPIs, no crash | Matched | Pass |
| Dashboard ward count vs. board's own header count | Dashboard vs. `/patients` | Same figure | 60 vs. 56 — board excludes the awaiting-assignment queue | **Fail** — Problem #21 |
| Console/network check, every page, every account | Browser console + network | No 404s/JS errors on normal navigation | `ehc-logo.svg` 404s on every load, every account | **Fail** — Problem #5 |
| Re-check 4 previously-reported defects (Registry status mislabel, MFA-mandatory disclosure, `log_record_opens` UI, Patient Merge picker render) | Registry, Control, Security, Patient Merge | All 4 still fixed | Confirmed fixed and holding | Pass |
| Re-check Patient Merge confirm step | `PatientMerge.vue` | Should now use the app's own dialog | Still `window.confirm()` | **Fail** — Problem #11 |
| 35 write-route probes as Observer | Re-read admission afterward | No field changes; genuine 403s | Confirmed, no data changed | Pass |
| Deactivate an already-signed-in user | That live session's next request | Session cut immediately | Redirected to `/login` — confirmed fixed (was broken previously) | Pass |

---

## 4. Per role

**Admin.** Worked: essentially everything across both clinical and administration surfaces — admissions, transfers, both discharge phases, consultations, handovers, reports, registry, exports, settings, users, import, patient merge, trash and audit all function correctly with accurate reflection everywhere checked; four previously-flagged defects were re-verified fixed and holding. Did not work: duplicate specialty/reason names can't be blocked or fixed; Short LOS > Long LOS saves unvalidated; the Patient Merge confirm step still uses a native browser popup. Confusing: several Settings fields and the "Security Anomalies" tile have no explanation; the MRN-overwrite issue (#1) is the single most important item to fix.

**Registrar.** Worked: admission, validation, assignment, shuffle, bulk reassign, modify, and all 12 admin-page refusals were clean and server-enforced; the primary-consultant self-assign exception works exactly as documented. Did not work: one capability (booking a named-specialty consult) was blocked, traced to a test-fixture gap rather than a live defect. Confusing: "Assign to me" gives no warning it hands the clicker full clinical-management rights over that patient.

**Consultant.** Worked: the entire handover signature lifecycle (create/read/sign/supersede/blind-sign/void), consultation creation/routing/scoping by specialty and coordinator status, and every admin-page refusal all behaved exactly as documented, with accurate reflection everywhere. Did not work: nothing failed outright — this role had the cleanest functional pass. Confusing: Sign can be clicked without ever reading the note; there's no dedicated hand-off action for a same-team move, so Transfer is used with a misleading "transferred" message.

**Resident.** Worked: every capability-gated write was correctly refused server-side with no UI/backend mismatch; both authority models (can_manage = "any patient," self-assign = "only your own") worked including their edge cases. Did not work: booking a consultation into any real specialty is flatly refused after the whole form is filled, with no warning up front — a genuine wall for the role most likely to be requesting a consult bedside. Confusing: Long-term toggle, Assign-to-me's consequence, and several labels (Active Consultations link, "System" delay reason) have no on-page explanation.

**Observer.** Worked: read-only enforcement is the most solidly verified part of the app — all 35 write probes across every module came back as genuine 403s with zero data changed, and the UI independently hides every action control to match. Did not work: the Dashboard's "Active Consultations" tile is clickable but leads to a hard 403. Confusing: several status badges an Observer specifically relies on (Long-term, "Disch. still in," DNR/DNI) carry no tooltip; a "Write" button appears where nothing can actually be written.

---

## 5. Suggested "!" info marks

Deduplicated across testers, grouped by page, pages ordered by how often a typical user sees them (Dashboard and Patients board first, admin-only pages last).

### Dashboard
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| Dashboard | "Active Consultations" tile | Unit-wide total — the consultations you can open on the ledger may be fewer, based on your specialty. | Confirmed dead end for Resident/Observer (Problem #7). |
| Dashboard | "Security Anomalies" tile | Failed logins, new-device sign-ins and MFA gaps — most flags are unused accounts, not active threats. Open Security for detail. | Alarming red number with zero context today. |
| Dashboard | "Pending Handovers" card | Patients recently handed to a new consultant whose signature is still outstanding. | Distinguishes it from the near-identical card beside it. |
| Dashboard | "Handover Due (unit)" card | Active patients unit-wide with no handover note saved today, whether or not recently moved. | Same as above, other direction. |
| Dashboard | Bed Occupancy tile | Calculated against the bed count set in Control → Settings — a low or unset count can read over 100% at a safe census. | 118–124% observed with no context. |
| Dashboard/board/Active List | "New" column | Set when assigned, handed over, or shuffled; cleared on discharge or reassignment — not a 24-hour timer. | Common misreading; the app's own metrics doc is currently wrong on this point too. |
| Dashboard/board/Active List | "Old" column | Active patients not currently flagged "New" — the opposite of New, not an age/record-age count. | No caption anywhere it appears. |
| Dashboard | "X below min" caption | Below the minimum caseload set for this consultant's pool in Control → Settings; Shuffle tries to balance within min–max. | References an admin-only setting. |
| Dashboard | Green "Live" pill | This page refreshes itself automatically every 5 minutes while the tab is visible. | Ambiguous meaning today. |
| Dashboard | "Census by service" total | Excludes ICU patients and anyone not yet assigned — see New Admissions for those. | Smaller than the Active Census KPI by design. |

### Patients board
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| Patients board | "Long-term" badge/toggle | Manually set by staff — not calculated from length of stay. Resets after a transfer. | Confused with the automatic LOS-day pill next to it; also silently resets. |
| Patients board | "Disch. still in" / "Boarding" badge or chip | Medically cleared to leave but still occupying the bed, awaiting a destination or bed. | Abbreviation-of-an-abbreviation; Dashboard explains the same concept, board doesn't. |
| Patients board | "TB" filter chip | Tuberculosis — the admission has a diagnosis on the TB reference list. | Spells out the abbreviation for non-clinical staff. |
| Patients board | "Sign pending" pill | A handover note was written before this patient was transferred to you. Open Handovers to read and sign it. | Card's own handover field can wrongly look empty (Problem #24). |
| Patients board | "N patients have no handover today" banner | Reword to "N patients on the unit have no handover today" when the viewer owns none of them. | Reads as a personal alert to Observer/no-capability roles who own zero patients. |
| Patients board | "Ward (non-ICU)" header stat | Excludes patients still awaiting consultant assignment, shown separately above. | Can visibly disagree with the Dashboard's ward figure. |
| Patients board card | Dotted-underline bed value | Click to edit the bed number. | Click-to-edit convention never explained on first encounter. |

### New Admissions queue
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| New Admissions / Admissions create | MRN input field | If this MRN already belongs to a patient, submitting different name/age/gender will change their record for every visit, past and future. | Directly mitigates Problem #1 — nothing today warns of this. |
| New Admissions queue | "Assign to me" button | You become this patient's primary consultant — you can then transfer, discharge and edit their handover, even without extra permissions. | Confirmed live: zero-capability accounts gain full manage rights with no warning. |
| New Admissions / board | "Mark as new patient" checkbox | Note the default explicitly, or align it between the two dialogs. | Defaults opposite ways in two places with no explanation. |
| New Admissions queue | "Shuffle / auto-assign" button | Only assigns to consultants currently marked On-Service. | Uneven results with no explanation of who's eligible. |

### Handovers / handover editor
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| Handover editor | "D/C ready" checkpoint chip | Ready for discharge — clinically ready to leave, pending logistics ("D/C" means discharge here, not discontinue). | Recognised ambiguous abbreviation, no tooltip anywhere it appears. |
| Handover editor | "VTE" checkpoint chip | VTE prophylaxis given. | Shorthand with no expansion. |
| Handover editor | Code status select (Full/DNR/DNI) | Full = full resuscitation, DNR = do-not-resuscitate, DNI = do-not-intubate. | Safety-critical field, reachable by non-physician clinical roles, never spelled out. |
| Handovers inbox | "Sign" button | Signing confirms you've read this handover and accept care. Open the row above to review the note first. | Nothing today requires the note be read first (Problem #12). |

### Consultations
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| Consultations | Status tabs (New/Active/Ongoing/Signed off) | New = not yet reviewed by the receiving team; Active = review started; Ongoing = under continued follow-up; Signed off = closed. | Core four-state lifecycle, never explained on the page itself. |
| Consultations — new/edit | "To service" field | You can only file a consult into your own specialty. Ask an admin or a consultation coordinator to book it for another team. | Silently rejects after the whole form is filled (Problem #4). |
| Consultation Dashboard | "Today: due / seen" tile | Due = active consults not yet checked today; Seen = active consults with a follow-up note logged today. | No on-page legend explaining what the two numbers count. |
| Consultation sign-off | Response note placeholder | Working note only — the full clinical note stays in the hospital's main record system (HIS), not here. | "HIS" used unexplained. |

### Discharge modal
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| Discharge (medical only) | "Delay reason" — "System" option | An administrative/bed-management delay (paperwork, transport wait) — not a physical bed shortage. | Only unexplained option on an otherwise plain-English list. |
| Discharge (complete) | "Discharge to" — "LAMA" option | LAMA — patient left against medical advice. | Only unexplained abbreviation on the list. |

### Registry / Statistics / Reports (admin)
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| Registry | "Long-term" filter checkbox | A flag staff set manually — not calculated from length of stay. | Sits next to LOS-based badges, reads as automatic. |
| Registry | "Clinical discharge" / "Physical discharge" labels | Clinical = medically cleared (phase 1). Physical = bed/file actually closed (phase 2). | Same concept called "medically discharged" elsewhere — inconsistent wording. |
| Registry / Statistics | "Out-dept transfer" badge | Also shown for an internal ward→ICU move, not only a transfer out of the department. | Confirmed to cover both cases (Problem #9). |
| Statistics | "Admissions" KPI card | Ward (non-ICU) admissions in this range only — ICU is the separate card to the right. | Bare count could be misread as including ICU. |
| Statistics | "Avg LOS" KPI card | Average days from admission to discharge, ward-only discharges in this range. | Easy to misread as current patients' live stay length. |
| Statistics | "Mortality" KPI % | Deaths ÷ discharges in this range × 100. | Denominator not stated. |
| Statistics | "≤Xd readmits" KPI card | A new admission for the same patient within the readmission window of a real discharge — transfers don't count. | Rule materially changes the count, invisible on the card. |
| Statistics | "ICU adm" / "→ICU" columns | ICU adm = admitted directly to ICU. →ICU = a ward discharge whose destination was ICU (a transfer out). | Easily conflated; can diverge in the same month. |
| Statistics | From/To date pickers | If the start date is after the end date, they're swapped automatically. | Server silently swaps with no visible notice. |
| Reports → Monthly | Rows after today's date | Rows after today show zero because the day hasn't happened yet, not because nothing occurred. | Confirmed indistinguishable from a real zero day. |
| Reports → Annual | "Long-stay %" tile | Share of ward discharges over the Long LOS threshold (set in Control → Settings). | Never defined on the printed/PDF report. |
| Audit log | "Hash chain intact through {date}" badge | Every entry is cryptographically linked to the one before it; this date is the latest point verified unbroken. | Page's core trust signal, no explanation of the mechanism. |

### Control panel
| Page | Element | Tooltip text | Why |
|---|---|---|---|
| Control → Settings | Min/Max hospitalist census | Shuffle's target patient-count range for each on-service Hospitalist consultant. | No caption exists at all. |
| Control → Settings | Min/Max subspecialty | Same range, for on-service Subspecialty consultants. | Mirrors the field above, also uncaptioned. |
| Control → Settings | Short LOS (days) | Episodes at or under this many days show "short" on the board/registry. Must be less than Long LOS. | Also not enforced server-side (Problem #3). |
| Control → Settings | Long LOS (days) | Episodes over this many days show "long" and count toward Long-Stay %. | Drives a published KPI, uncaptioned. |
| Control → Settings | Licensed ICU beds | Denominator for the ICU occupancy card — set to your real ICU bed count. | Sibling "ward beds" field has this caption, ICU beds doesn't. |
| Control → Users | Capability checkboxes (Can assign/add/manage/modify/coordinate) | Can add = admit patients. Can assign = assign/shuffle/bulk-reassign. Can manage = transfer/discharge any patient. Can modify = edit patient details. Can coordinate consults = run the ledger. | The app's real permission model, shown as bare jargon. |
| Control → Users | "On service" checkbox | Included in the automatic Shuffle assignment pool for new unassigned patients. | Label alone doesn't convey the effect. |
| Control → Users | Users tab heading | New staff self-register (email + authenticator); activate their account here once it appears. | No "Add user" button and nothing explains why. |
| Control → Reference data | Add-specialty / add-reason forms | Check spelling first — this list can't be edited or removed later, and duplicates aren't blocked. | Mitigates Problem #2 until fixed. |

---

## 6. Quick wins

1. Add the official cluster logo file, or stop probing for it (removes a console 404 on every page).
2. Wire the checkpoint chips' already-written full labels (VTE, D/C ready, DNR, DNI) as hover/tap tooltips — a one-line change.
3. Spell out or caption "System" (delay reason) and "LAMA" (discharge destination) in the discharge modal.
4. Add captions to the un-captioned Control → Settings fields (Min/Max census, Short/Long LOS, ICU beds) to match their sibling fields.
5. Fix the false "Discard changes?" warning when cancelling an untouched Assign/Reassign dialog.
6. Align the "Mark as new patient" checkbox default between the New Admissions and board assign dialogs (or explain the two defaults).
7. Add a one-line note to Control → Users explaining the self-registration + activation flow.
8. Add an upfront hint or filtered dropdown on the Consultations "To service" field, instead of failing only after full-form submit.
9. Replace the native browser `confirm()` in Patient Merge with the app's own dialog, and add a confirm step to the bulk-import commit.
10. Add a "Registered" date or distinct badge in Control → Users so a pending self-registration isn't mistaken for a deactivated account.

---

## 7. Coverage

| Role | Pass | Fail | Blocked (by design) | Not tested | Notable gaps |
|---|---|---|---|---|---|
| Admin (clinical + administration) | 79 | 1 | 1 | 1 | Patient Merge confirm-dialog inconsistency; no admin UI to create a user (self-registration only, intentional); internal Style Guide page out of scope. |
| Registrar | 31 | 4* | 1 | 0 | *3 of the 4 "fails" are the documented self-assign management exception (verified by-design, not a defect); consultation-into-named-specialty was blocked by a test-fixture gap, not a live defect. |
| Consultant | 22 | 0 | 0 | 0 | No functional failures; open items are UX only (sign-without-reading, no direct hand-off action). |
| Resident | 23 | 1 | 12 | 0 | Consultation booking into any internal specialty fails with no upfront warning (Problem #4). |
| Observer | 14 | 1 | 9 | 0 | Dashboard "Active Consultations" tile is a dead end; read-only enforcement itself is airtight (35/35 write probes correctly refused, zero data changed). |
| Cross-role: Statistics/Reporting accuracy | 19 | 0† | — | — | †Two definitional mismatches surfaced as findings, not coverage failures: "discharge" and "Out-dept transfer" mean different things on different pages (Problems #8, #9). |
| Cross-role: UI/UX & self-explanation (all pages, all roles) | 46 | 7 | — | 1 | Fails: broken logo, false discard-warning, Dashboard-vs-board ward count, and 4 pages judged "not fully self-explanatory" (Dashboard, Patients board, Active List, Handover editor, Profile) despite being functionally correct; Style Guide out of scope. |