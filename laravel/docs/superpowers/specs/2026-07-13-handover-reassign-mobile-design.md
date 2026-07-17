# Handover + reassign + mobile — design

**Date:** 2026-07-13
**Status:** Approved (design) — ready for an implementation plan
**App:** DMC Internal Medicine (Laravel 13 + Inertia 2 + Vue 3 + Tailwind v4, `laravel/`)

## Goal

Five related improvements, mostly around the handover/reassign workflow:
1. Fix horizontal overflow on phones across the app (incl. dashboard) — vertical scroll only.
2. Widen the Reassign modal to ~75% (near-full on phones).
3. Relax the reassign hard-gate: allow the move even with an incomplete handover, but raise a
   **persistent reminder** for the reassigning user AND the from-consultant until it's completed.
4. Add per-patient handover **checkpoints**: VTE completed, Ready for discharge, High-risk patient,
   Needs more workup, Workup pending results, and **Code status** (Full/DNR/DNI).
5. After building, write a clinical-audit/compliance summary of how handover works + what is logged.

## Decisions locked with the owner
- **Reassign gate → soft.** The move proceeds even with incomplete handovers; a persistent reminder
  chases each one. (Was: a hard block until every moved patient had a same-day handover.)
- **Checkpoints = the five above + Code status** (a dropdown). No other flags for now.
- **"Complete" = a handover note saved for that patient** after the reminder is raised (a note resolves
  it). Checkpoints are captured but do NOT gate completion.

## Existing system (verified)
- `handovers` (current free-text `body`, `updated_by`, timestamps; "today" = `updated_at->isToday()`)
  + `handover_revisions` (append-only: `body`, `author_id`, `created_at` — the changelog) +
  `handover_signatures` (per-transfer: from/to consultant, `revision_id`, `required_at`, `signed_at`,
  `signed_by`, `voided_at` — receiving consultant acknowledges) + `notifications` (bell feed).
- `Notification` (`user_id`, `type`, `payload` json, `read_at`, `created_at`; no `updated_at`).
  Created in `PatientActionController` on transfer/assign as `type: 'handover.transfer'`.
- Reassign gate is **client-side only** (`ReassignModal.vue` `preflightReady`); server
  `PatientActionController::bulkReassign` moves the subset, creates a signature per moved admission +
  one `handover.transfer` notification to the receiving consultant — it does NOT reject stale handovers.
- `BaseModal` sizes: `md/lg/xl/2xl` all cap at ≤ `max-w-2xl` — no wide option exists.
- Audit: every handover mutation writes `audit_logs` (`handover.update` / `handover.sign` /
  `handover.read` when break-glass logging is on).

---

## Section A — Mobile horizontal-overflow fix

**Outcome:** on a phone, the page body scrolls only vertically; wide content scrolls *within its own
card*, never widening the page.

- **Global guard:** ensure the app's main content container cannot be pushed wider than the viewport
  (an `overflow-x` guard on the content wrapper / `min-w-0` on the flex column so children can shrink).
- **Wide tables:** every data table lives inside its own `overflow-x-auto` wrapper (board roster
  already does; audit + fix registry, control/users, active-list, statistics, handovers, audit log).
- **Long strings:** MRNs, emails, diagnosis text, usernames get `break-words`/`truncate` and their flex
  parents get `min-w-0`, so they wrap/clip instead of forcing width.
- **Dashboard:** KPI tiles + charts stack to one column at phone width; charts are width-constrained to
  their card (ApexCharts `width:'100%'`).
- **Method:** audit each page live at a 375px viewport (Dashboard, Patients board, Registry, Control,
  Handovers, Admissions, Statistics, Audit); fix overflow; re-verify at 375px. No behavior change.

## Section B — Reassign modal width

Add a `wide` size to `BaseModal` (`sizeClass.wide = 'max-w-4xl'` ≈ 75% on a clinical laptop; `w-full`
already makes it near-full on phones). `ReassignModal` switches `size="md"` → `size="wide"`. The
per-patient handover editors get the room they need. No other modal changes.

## Section C — Relaxed gate + persistent incomplete-handover reminders

### C1. Gate → warning (client, `ReassignModal.vue`)
- `preflightReady` no longer requires `staleRows.length === 0`. Confirm is enabled once a from- and
  to-consultant are chosen and ≥1 patient is selected.
- When selected patients have stale handovers, show an **amber warning**: "N of M selected patients
  will move with an incomplete handover — a reminder will be sent to you and Dr. {from} until each is
  completed." The per-patient editors + **Save all handovers** stay (completing in-modal is still the
  easy path) but are optional. "Uncheck all stale" stays (leave those patients behind entirely).

### C2. Persistent reminders (server, `PatientActionController::bulkReassign`)
- After the move, for each moved admission whose handover is NOT current-today, create a
  `handover.incomplete` notification for **both**: the acting user (`Auth::id()`) and the
  `from_consultant_id`. Payload: `admission_id`, `patient_name`, `mrn`, `from_name`, `to_name`.
  De-dup if the reassigner IS the from-consultant (one row, not two).
- The existing `handover.transfer` notification to the receiving consultant is unchanged.

### C3. "Action needed" notifications that persist until resolved
- Migration: add nullable `resolved_at` to `notifications`.
- **Actionable types** = `['handover.incomplete']` (a constant). Behavior:
  - The bell's **unread badge** counts unread non-actionable + **unresolved** actionable
    (`resolved_at IS NULL`) — so an open reminder keeps the bell lit even after the user opened it.
  - `readAll` (opening the bell) sets `read_at` on non-actionable only; actionable rows are NOT
    dismissed by it (they clear only when resolved).
  - The bell dropdown shows unresolved actionable items in a pinned **"Needs attention"** group at the
    top, styled distinctly, each linking to that patient's handover (`/patients` deep-link or the
    handover editor). Non-actionable items render as today.
- **Resolution (server, `HandoverController::save`):** after saving a handover for an admission, set
  `resolved_at = now()` on all `handover.incomplete` notifications for that admission where
  `resolved_at IS NULL` (clears it for every recipient at once).

### C4. Audit
`bulkReassign` already audits the move; add an audit note when reminders are raised
(`handover.reassign_incomplete`, detail: admission ids + recipients) so the compliance trail shows the
gate was soft-passed and who was reminded.

## Section D — Handover checkpoints

### D1. Data (migration, additive)
Add a nullable JSON `checkpoints` column to **both** `handovers` and `handover_revisions` (so every
revision snapshots the checkpoint state for audit). Shape:
```json
{ "vte_completed": false, "ready_for_discharge": false, "high_risk": false,
  "needs_workup": false, "workup_pending": false, "code_status": null }
```
`code_status` ∈ {`full`,`dnr`,`dni`,null}; the five flags are booleans. `Handover` + `HandoverRevision`
cast `checkpoints => 'array'`.

### D2. Save (server, `HandoverController::save`)
Accept an optional `checkpoints` object; validate the five booleans + `code_status` in the enum. Persist
it on the `handovers` row AND the new `handover_revisions` row (same value → snapshotted in history).
Body stays required (unchanged). A save with only checkpoints changed still writes a revision.

### D3. UI (`HandoverModal.vue` + `ReassignModal.vue` editors)
Render, above the free-text note: five checkboxes + a Code-status `<select>`. `useHandover.saveHandover`
gains a `checkpoints` argument (both callers pass it). `fetchHandover`/`show()` return current +
per-revision checkpoints.

### D4. At-a-glance chips
Where a handover is shown (board `PatientCard` handover affordance + the Handovers inbox), show small
chips for the set flags (e.g. "VTE", "DNR", "High-risk", "D/C ready") so cross-cover sees them without
opening the note. Uses existing AA-safe token pairs; no new colours.

## Section E — Compliance summary (deliverable, after build)

A plain-English document (`laravel/docs/HANDOVER-COMPLIANCE.md`) covering: the handover lifecycle
(create/edit → revision; reassign → signature + soft-gate reminder; receiving-consultant sign-off;
discharge/void), and **exactly what is retained for clinical audit**:
- `handover_revisions` — every edit: author, timestamp, full text, **checkpoint snapshot**.
- `handover_signatures` — which consultant acknowledged which exact revision, and when.
- `audit_logs` — `handover.update` / `handover.sign` / `handover.read` (break-glass) /
  `handover.reassign_incomplete`.
- `notifications` — the reminder trail (raised → resolved), with recipients + timestamps.
Plus how to query each for an audit.

---

## Data-model changes (all additive / reversible)
1. `notifications.resolved_at` (nullable timestamp).
2. `handovers.checkpoints` (nullable json) + `handover_revisions.checkpoints` (nullable json).

## Testing
- **PHPUnit:** `bulkReassign` allows a move with a stale handover (no longer 422/blocked) and creates
  `handover.incomplete` for reassigner + from-consultant (de-duped when same person); `HandoverController::save`
  resolves them; save persists + snapshots `checkpoints` on the revision; checkpoint validation rejects a
  bad `code_status`; the bell unread count includes unresolved actionable items; `readAll` does not dismiss
  actionable ones.
- **Vitest:** ReassignModal Confirm is enabled with a stale handover + shows the amber warning;
  HandoverModal renders the checkpoints + code-status and passes them to `saveHandover`; the bell renders
  the "Needs attention" group for `handover.incomplete`; checkpoint chips render for set flags.
- **Live (375px):** each audited page has no horizontal page scroll; the Reassign modal is ~75% wide on
  desktop and near-full on mobile.
- Gates: Vitest + two-pass PHPUnit + build + allowlist + contrast.

## Build order
A (mobile) → B (modal width) → D (checkpoints: data + save + UI) → C (relaxed gate + reminders, builds on
D's editors) → E (compliance write-up). All TDD + per-task review + final adversarial review, then ship.

## Out of scope (now)
- The other suggested checkpoints (isolation, lines/drains, fall risk, goals-of-care, escalation) — the
  JSON column makes adding them later a one-line change.
- Making checkpoints mandatory / gating completion on them.
- Email/push for the reminders (in-app bell only).
