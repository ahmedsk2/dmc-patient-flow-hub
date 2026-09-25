# DMC Internal Medicine Patient-Flow Hub — Second Role-by-Role Walkthrough

*2026-09-25. Local test copies with synthetic data only; nobody signed in to the live site or typed a
password or authenticator code. Nine testers: Admin (clinical side), Admin (control panel), Registrar,
three Consultants, two Residents, Observer, a first-time user (sign-in and account screens), a UI/UX
and accessibility reviewer, and a security tester. The owner asked to "act like a user for each user
type and use the website from A to Z" and then to "fix all". The first walkthrough is
[`ROLE-UX-REVIEW-2026-09-24.md`](ROLE-UX-REVIEW-2026-09-24.md); all of its fixes held.*

## Summary

- **Permissions held everywhere.** Roughly a hundred attempts to write data or open other people's
  records (Observer writes from the browser console, over-posted fields such as `admitted_by` or
  `role`, other consultants' patients, admin pages) were all refused by the server.
- **Statistics reflected every action.** Through about twenty admissions, transfers, discharges and
  undos, the dashboard, board, Recent Activity, Statistics and Reports always agreed.
- **Two security issues and a handful of real defects** were found and fixed; the rest were clarity,
  mobile and accessibility improvements.

## Findings and resolution

| # | Severity | Area | Finding | Resolution |
|---|---|---|---|---|
| S1 | High | Sign-out | After signing out, the browser's Back button showed the last patient page again with no server request (Inertia keeps page data in the browser history). | Inertia history encryption on for every page; the login page and every sign-out path clear it; a whole-page restore from the back/forward cache reloads from the server. |
| S2 | Medium | Sign-up | The first sign-up step answered differently for a registered staff email ("already taken") than for an unknown one, so a stranger could discover staff addresses. | Identical response in every case; the owner of a registered address receives a notice instead of a code; a probe cannot verify, and cannot block someone else's sign-up in progress. |
| S3 | — | Long-term flag | Any clinical role can mark any patient long-term, including one not on their own list. | **Owner decision: keep it open to all clinical roles** (it is audited). No change. |
| E1 | High | Error pages | 403/419/429/500/503 pages rendered unstyled: their inline style lacked the CSP nonce after style-src became nonce-only (2026-09-22). | The error shell's style carries the nonce. |
| E2 | High | Handovers | After a specialty transfer, saving the text from Handovers → My outgoing (the old episode) did not clear the "handover not complete" reminder, which sits on the new episode; the patient stayed under Needs handover. | New column `admissions.predecessor_admission_id` links the two episodes; saving on either clears the reminder. A same-request double transfer is now refused. |
| E3 | Medium | Patient merge | The merge screen promised the source "stays recoverable from Recently Deleted"; there is no such restore. | **Owner decision: fix the wording.** The screen and DATABASE-AND-BEHAVIOR.md now say a merge cannot be undone in the app and reversing one needs the maintainer and a restore from backup. |
| E4 | Medium | Email verification | The page showed a blank where the (masked) email should be. | Prop name fixed. |
| E5 | Medium | Dialogs | Keyboard focus escaped the Transfer dialog; the Ward/ICU and indication choices were unreachable by keyboard. | Focus trap skips hidden elements; the choices are keyboard-operable with a visible focus highlight. |
| E6 | Medium | New admission | Red "required" messages stayed after the field was filled. | Each message clears when its field changes. |
| E7 | Low | Error pages | GET /logout showed Laravel's stock 405 page. | Branded 405 page. |
| U1 | Medium | Consultations | The ledger opens on "New"; when that tab was empty but others were not, it said "No consultations match your filters". | The empty state names what exists on the other tabs with a one-click switch; users without a specialty see a line explaining their scope. The default tab is unchanged. |
| U2 | Medium | Control → Users | Unticking Active silently signed the user out everywhere. | Confirmation, an "!" mark, and a message saying they were signed out. |
| U3 | Low | Control → Users | Delete said "cannot be undone" though an admin can restore the account. | Wording corrected. |
| U4 | Low | Import | The button counted invalid rows. | It shows the rows that will be written and how many are skipped. |
| U5 | Low | Hand-off | The hand-off dialog was titled "Assign consultant" and its warning named the same person twice. | Hand-off wording throughout. |
| U6 | Low | Patients board | "My patients only" did nothing for a consultant (already scoped). | Hidden for that role. |
| U7 | Low | Discharge | A blank delay reason only showed the browser's own popup. | The app's own inline message. |
| U8 | Low | New consultation | "Referring service" was not prefilled from the picked patient. | Prefilled from the current consultant's specialty (still editable). |
| U9 | Medium | Phones | Dashboard tile titles were cut off; tour step 2 pointed at the hidden menu; the password-expired reason was easy to miss. | Titles wrap; the tour targets the menu button; a persistent notice at the top of the profile page. |
| U10 | Low | Sign-in screens | Authenticator set-up "Cancel" looped back; forgot-password said "The email field is required" under "Username or email"; sign-up marked only one of four required fields. | "Sign out" with a line saying set-up is required; the message matches the label; all required fields marked. |
| U11 | Low | Arrival | Nothing told a registrar that patients await assignment. | A count badge on "New Admissions" (not shown to Observers). |
| U12 | Low | Wording | "KINDLY REVIEW ADMISSION DETAILS"; the Active Consultations "!" was wrong for Observers. | Sentence case; role-aware tip. |
| A1 | Low | Accessibility | Unlinked date labels and an unnamed select on Statistics; faint disabled pagination (about 2:1); skipped heading levels (including dialog titles); the command palette dropped focus on Escape; lookup suggestions not announced. | All fixed (labels, names, ink-500 pagination, h2 section and dialog headings, focus returns to the trigger, listbox/option roles). |

**New "!" marks:** the per-consultant "Active" column (one wording on the dashboard, board and Active
List), the board's "Census", Control → Users "Active" and "Role", sign-up "Role", Registry "3-day
readmissions", the consult indication picker ("pick one or more"), the dashboard's Mortality and Avg
LOS tiles, and the Handovers "Needs handover" tab.

## "Assign to me" (decided: consultants only)

Any clinical role could take an unassigned patient from the New Admissions queue. The one "consultant"
slot then holds that person, which (a) lets them transfer and discharge that patient without the
Manage permission, (b) lists them as "Dr. …" on the board, Active List and dashboard, and (c) splits
per-consultant figures inconsistently (some pages count them, others list active consultants only).
A read-only count on production (numbers only) showed all 118 active patients and all 9,262
admissions of the last twelve months held by consultants; only 3 of 37,645 ever by a registrar. The
recommendation was to offer "Assign to me" to consultants only, and the owner chose it ("B", the
same day): only consultants see the button and the server refuses everyone else; an Admin or a
Registrar with Can-assign names a consultant with "Assign to primary", and anyone who can do neither
sees that the patient is awaiting a consultant. The three historical episodes held by a registrar stay
as they are and stay editable. The review of that change found a second door: the New Admission
form accepted any user as the consultant, so anyone with Can-add could name themselves; it now
accepts only an active consultant, like Assign, bulk reassign and specialty transfer.

## Operations the same day

- SSH (port 22) reopened to any address by owner decision because the workstation address changes;
  key-only login, root login off, fail2ban active (DEPLOY-LARAVEL.md, CLAUDE.md §14).
