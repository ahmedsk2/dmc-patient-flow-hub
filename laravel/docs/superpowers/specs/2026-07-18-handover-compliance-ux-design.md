# Handover compliance UX — design

**Date:** 2026-07-18
**Status:** Approved (design) — ready for an implementation plan
**App:** DMC Internal Medicine (Laravel 13 + Inertia 2 + Vue 3 + Tailwind v4, `laravel/`)

## Goal

Make a complete handover the path of least resistance at every consultant-to-consultant
handoff, and make whatever still slips through impossible to miss — targeting a 100%
completion rate without ever blocking clinical care.

Triggered by two observations from the owner: checkpoint chips are absent from the reassign
flow, and the single-patient reassign shows no handover at all.

## Decisions locked with the owner

1. **Never block.** Compliance comes from making the right thing effortless plus relentless
   follow-up — not from a wall. (This also resolves an inconsistency: bulk reassign was
   softened this week, but the single-patient paths still hard-blocked.)
2. **Scope = consultant-changing moves only:** single *Assign consultant* to a different
   consultant, *Transfer to another specialty*, and bulk *Reassign*. First assignment,
   assign-to-me, shuffle, ICU pull, ward↔ICU and discharge stay unprompted.
3. **Capture density:** compact tappable chips in bulk reassign rows; the full editor
   (labelled checkboxes + code-status + note + history) in the single-patient modal.
4. **Visibility:** the full package — dashboard tile, board filter, inbox tab, and a board
   banner **permanently pinned whenever the count is above zero**.
5. **No visible bypass button.** Proceeding without a complete handover is possible, but only
   through an explicit acknowledgement dialog.
6. **Admins get unit-wide views** (all consultants) in addition to their own.

## Current state (verified in code)

| Path | Gate today | When the requirement is visible | Editor |
|---|---|---|---|
| Bulk reassign | **Soft** (warns, proceeds, reminds) | Before confirming — preflight lists stale patients | Free-text only, pre-filled. No checkpoints. |
| Single *Assign consultant* (different consultant) | **Hard block** (`assertHandoverToday`) | Only **after** a failed submit | Bare textarea, starts **blank** — the existing note is not shown. No checkpoints. |
| Single *Transfer to specialty* | **Hard block** (`assertHandoverToday`) | Only after a failed submit | Same as above |
| First assign · assign-to-me · shuffle · ICU pull · ward↔ICU · discharge | None | — | — |

Three problems: inconsistent policy across paths, a reactive "surprise wall" on single
transfers, and checkpoints captured nowhere except the standalone handover modal.

---

## §A — One policy on every consultant-changing path

Remove the two remaining `assertHandoverToday()` calls (in `assign` and `transferSpecialty`).
All three paths then behave identically:

> The move always succeeds. If the handover was not saved today, raise a persistent
> `handover.incomplete` reminder to the acting user **and** the outgoing consultant, and write a
> `handover.reassign_incomplete` audit entry. The reminder clears when a note is saved.

Extract the reminder-raising logic currently inline in `bulkReassign` into one private helper
(`raiseIncompleteHandoverReminders`) used by all three call sites, so the rule cannot drift
apart again. Existing de-duplication (same person; repeat reassign of a still-stale admission)
carries over unchanged.

## §B — Capture: one component, two densities

New `resources/js/Components/Patients/HandoverCapture.vue`, prop `density: 'compact' | 'full'`.
It replaces the bulk modal's bare per-row textarea **and** the ActionModal's reactive
`gateBody` textarea.

- **`compact`** — bulk Reassign rows. Tappable checkpoint chips (toggle on click), an inline
  code-status control, and the pre-filled note. Stays scannable when several patients are stale.
- **`full`** — single-patient Assign / Specialty-transfer modal. Labelled checkbox rows,
  code-status dropdown, full-height note, collapsible revision history.

Both render a header showing `last updated {relative}` plus a **stale** pill when not current
today, and both pre-fill body **and** checkpoints from the existing handover.

**The key behavioural change:** in the single-patient modal the panel appears **as soon as a
different consultant is selected** — not after a failed submit. The requirement is never a
surprise.

Data: reuses `GET /admissions/{admission}/handover` (already returns body, checkpoints and
revisions) — no new endpoint. Saves via `useHandover.saveHandover(id, body, checkpoints)`, which
already accepts checkpoints. `HandoverController::preflight` gains `checkpoints` per row so the
compact panel can render them.

### The acknowledgement dialog (replaces a bypass button)

There is a single primary action — *Save handover & assign* / *Confirm reassign*. If it is
triggered while any affected patient's handover is not current, a confirm dialog intercepts:

> **Handover not complete**
> {Patient} has no handover saved today. A reminder will be sent to you and Dr. {from} until it
> is completed.
> **[ Complete handover now ]** · **[ I'm aware — proceed ]**

- *Complete handover now* dismisses the dialog and returns focus to the capture panel; the move
  does not happen.
- *I'm aware — proceed* performs the move and raises the reminder.

For bulk, **one** dialog covers the whole batch using the count form ("N of M selected patients
have no handover saved today…") — not one dialog per patient; *Complete handover now* dismisses
it and focuses the first stale row's panel. The existing inline amber warning stays as ambient
context; the dialog is the moment of acknowledgement. Implemented with the app's existing
`useConfirm()` composable.

**Audit:** the `handover.reassign_incomplete` entry records that the actor explicitly
acknowledged (`acknowledged: true`), so the trail distinguishes a conscious clinical decision
from an accident.

## §C — Visibility

Definition used consistently: **an active admission (no discharge date — the app's canonical
`Admission::active()` scope) whose handover has no save dated today.** "Personal" scope means
admissions where `consultant_id` is the viewing user; "unit-wide" means all consultants.

1. **Dashboard tile** — "Handover due · N" in *My unit today*, linking to the filtered board.
   Admins additionally get a unit-wide figure in the admin band.
2. **Board filter chip** — "Needs handover (N)" beside the existing board filters.
3. **Handovers inbox third tab** — "Needs handover": the canonical list with inline *Write*
   buttons (alongside *Awaiting my signature* and *My outgoing*).
4. **Board banner** — **permanently pinned whenever N > 0** (owner's decision), naming the count
   with a "Show them" action. Not dismissible; disappears only when the count reaches zero.
   The banner always uses the **personal** count, including for admins — a permanently pinned
   unit-wide number would be non-actionable noise for an admin who owns no patients. Admins see
   the unit-wide figure in the dashboard admin band and the inbox tab instead.

Personal scope by default everywhere; the dashboard admin band and the inbox tab additionally
expose unit-wide figures for admins.

## §D — Data & API

**No schema change** — everything derives from `handovers.updated_at` vs today.

- `PatientsController::index` — a `needs_handover` filter plus the count.
- `DashboardController` — `handoverDue` (personal) and a unit-wide figure for the admin band.
- `HandoverController::index` — the "Needs handover" tab dataset (personal + unit-wide for admin).
- `HandoverController::preflight` — include `checkpoints` per row.
- `PatientActionController` — drop the two `assertHandoverToday()` calls; shared
  `raiseIncompleteHandoverReminders` helper; `acknowledged` flag on the audit detail.

## §E — Testing

- **PHPUnit:** single *assign* and *transferSpecialty* to a different consultant proceed with a
  stale handover (no 422) and raise reminders to actor + outgoing consultant, de-duped; the
  reminder resolves on save; `needs_handover` count/filter correctness (personal and unit-wide);
  the inbox tab dataset; `acknowledged` recorded in the audit detail.
- **Vitest:** `HandoverCapture` in both densities (pre-fills body + checkpoints, chip toggles
  post correctly, stale header renders); ActionModal shows the panel on selecting a different
  consultant *before* submit; the acknowledgement dialog appears on incomplete submit and both
  branches behave correctly; banner/tile/filter render and counts.
- **Gates:** Vitest + two-pass PHPUnit + build + source allow-list + contrast.

## Out of scope

- Prompting on discharge, ward↔ICU, ICU pull, first assignment, assign-to-me, shuffle.
- Email/push delivery of reminders (in-app bell only).
- Making checkpoints mandatory, or gating completion on them.
- Any schema change.

## Build order

§A (one policy + shared helper) → §B (`HandoverCapture` + both hosts + acknowledgement dialog)
→ §C (visibility surfaces) → §D falls out of A–C → §E throughout (TDD).
