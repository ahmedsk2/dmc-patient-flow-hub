# Transfer-driven handover + notification & list UX — design

**Date:** 2026-07-18
**Status:** Approved (design) — ready for an implementation plan
**App:** DMC Internal Medicine (Laravel 13 + Inertia 2 + Vue 3 + Tailwind v4, `laravel/`)

## Goal

Six owner-requested changes. The headline one redefines what "needs a handover" means, because the
current definition lights up the entire census every morning.

1. **Reset the handover backlog** and only label from coming transfers.
2. Confirm who is alarmed on a transfer (no change — see §2).
3. **Fix the notification dropdown** — it grows past the viewport and the page scrolls instead of it.
4. **Add "Clear notifications"** that keeps still-outstanding handover alarms.
5. **Hide consultants with no patients** from the board, Active List, and the dashboard table.
6. **Searchable select** for long option lists (substring/"like", not exact).

## Decisions locked with the owner

1. **"Needs handover" becomes transfer-driven** — an admission with an unresolved
   `handover.incomplete` reminder. Not "no note today".
2. **Alarm recipients stay as they are:** the user who initiated the transfer/reassign **and the
   outgoing consultant**. The receiving consultant keeps only the ordinary "handed over to you"
   notice, never the persistent alarm. (The owner's original wording suggested the new consultant was
   being alarmed; verified in code that they never are.)
3. **No fabricated clinical records.** The backlog is not reset by writing handover rows — the new
   definition resets it inherently.
4. **"Clear" hides, never deletes.** `notifications` is a documented part of the retained audit trail
   (`docs/HANDOVER-COMPLIANCE.md`), so deletion would break a record intended for clinical audit.
5. **Zero-patient consultants are hidden** in all three places. No "on service, no patients" footer
   line for now (YAGNI — easy to add later).
6. **Searchable select self-adapts** — plain native `<select>` below a threshold, searchable
   combobox above it.

## Current state (verified in code)

- `Admission::scopeNeedsHandoverToday()` = `active() AND NOT EXISTS (handover updated today)`.
  Consumers: `DashboardController` (×2), `HandoverController::index`, `PatientsController` (count +
  filter). Because no handover notes exist yet, **every active patient matches** — hence the wall of
  alerts.
- Bell dropdown (`AppLayout.vue`): the panel is `absolute … w-80 overflow-hidden` with **no
  max-height**, and the pinned "Needs attention" group sits **outside** the only scrollable element
  (`ul.max-h-80.overflow-auto`). A long actionable list therefore grows past the viewport and the
  **page** scrolls. `toggleBell` also auto-calls read-all whenever `unread > 0`.
- Zero-patient consultants appear via three deliberate mechanisms: the board's "zero-census fill"
  (`PatientsController::boardGroups`, unfiltered board only), **Active List** (which calls the same
  `boardGroups()`), and the dashboard's `consultantBoard` **left join** from `users`
  (`perConsultant`, the top-8 load chart, already excludes them via an inner join).
- `IcdTypeahead.vue` is an existing accessible combobox (`role="combobox"`/`listbox`/`option`,
  `aria-expanded`, `aria-autocomplete="list"`, arrow-key highlight, Esc/blur close) but is
  **server-backed** (`/api/icd10?q=`) because ICD-10 has ~72k rows.

---

## §1 — "Needs handover" = transfer-driven

**New definition:** an active admission that has at least one **unresolved `handover.incomplete`
notification** — held by **any** recipient. The admission is the unit of "pending", not the person:
if either the initiator or the outgoing consultant still has an open reminder, the patient is
pending. Rename the scope `needsHandoverToday()` → **`handoverPending()`**, since "today" is no longer
part of the meaning, and update its five call sites.

**Per-viewer scoping is unchanged** — the surfaces keep the existing `User::seesOwnPatientsOnly()`
predicate (consultants see only their own patients; every other role sees unit-wide), so this change
alters *what counts as pending*, not *who sees what*.

**Why this resets the backlog with no data change:** the count is derived from reminders, and
reminders are only ever raised by a transfer that left a handover incomplete. With none raised, every
surface reads zero the moment this ships. Nothing is written, deleted, or backdated.

All four surfaces (pinned banner, dashboard tile, board filter chip, inbox tab) read this one scope,
so they cannot disagree with each other.

**The board card's amber handover icon is unchanged** — it still reflects "note not refreshed today"
as a quiet per-patient cue. It simply no longer drives any alarm. Daily currency remains visible;
it stops being an obligation.

**Consequence worth noting:** after a transfer A→B, consultant **B** sees the patient in their own
"needs handover" surfaces (the patient is theirs and carries an unresolved reminder), while **A and
the initiator** are chased in the bell. Both ends of the handoff are covered without duplicate alarms.

### Data change (the one schema change — additive, reversible)

Add **`notifications.admission_id`** (nullable unsigned big int, indexed), backfilled from
`payload->admission_id`.

Rationale: the scope must correlate admissions to reminders on every board/dashboard load. Doing that
through the JSON payload is unindexable — a full scan over a table that grows with every
notification, against thousands of admissions. An indexed column makes it a plain lookup. It also
removes an existing fragility: the resolve query currently depends on a `(string)` cast to match a
JSON number (a previously-fixed bug), which the column eliminates.

Writers set `admission_id` alongside the payload (payload keeps the field for the audit trail and for
existing rows). The resolve query in `HandoverController::save` switches to the column.

## §2 — Alarm recipients: no change

Verified: `raiseIncompleteHandoverReminders` notifies `Auth::id()` + the outgoing consultant, never
the receiver; the receiver gets a separate `handover.transfer` notice. This already matches the
owner's requirement. **No code change.** Covered by a regression test asserting the receiving
consultant holds no `handover.incomplete` row after a reassign.

## §3 — Notification dropdown scrolling

- Cap the panel: `max-h-[70vh]` (viewport-relative, so it works on phones and short laptops).
- Move **both** the pinned "Needs attention" group and the ordinary feed **inside a single scroll
  container**, so the whole list scrolls as one and the end is always reachable. Remove the inner
  `ul.max-h-80` cap, which currently makes only the feed scroll.
- Add `overscroll-contain` to that container so reaching its end does **not** chain the scroll to the
  page — the reported "page scrolls instead of the popup".
- Keep the panel header (and the Clear action from §4) pinned outside the scroll area so they stay
  reachable in a long list.

## §4 — "Clear notifications"

A **Clear** button in the dropdown header that empties the visible feed while leaving anything still
outstanding.

- **Feed query changes** from "latest 15 regardless of state" to **unread ordinary notifications +
  unresolved actionable ones**. Clearing therefore genuinely empties the list.
- **Clear** marks ordinary (non-`handover.incomplete`) notifications read. The existing
  `POST /notifications/read-all` already excludes actionable rows, so it is reused as-is.
- **Remove the auto-read-all on open.** `toggleBell` currently marks everything read whenever
  `unread > 0`; with a read-filtered feed that would silently empty the list the instant it is opened.
  Clearing becomes explicit and user-driven.
- Handover alarms are never cleared by this action — they persist until the note is saved.
- **Per-user only.** Clearing affects the acting user's own notification rows; it never touches
  another user's bell.
- **Nothing is deleted**, preserving the audit trail per §Decisions.4.

## §5 — Hide consultants with no patients

Three edits, one per mechanism:

1. **Board** (`PatientsController::boardGroups`) — drop the zero-census fill block.
2. **Active List** — inherits the fix automatically (same method); verify no separate roster merge.
3. **Dashboard "Patient count per consultant"** (`consultantBoard`) — require at least one active
   admission, so the users-driven left join stops emitting empty rows.

`perConsultant` (the top-8 load chart) already inner-joins and needs no change.

**Trade-off accepted:** this behaviour existed to mirror the legacy members-list table so the on-call
roster stayed visible; an on-service consultant with zero patients now disappears from these views.
Assignment dropdowns are unaffected — they list consultants independently, so no one becomes
unassignable.

## §6 — Searchable select

New **`resources/js/Components/SearchableSelect.vue`**, modelled on `IcdTypeahead.vue`'s keyboard and
ARIA behaviour (`role="combobox"` + `listbox`/`option`, `aria-expanded`, `aria-autocomplete="list"`,
Up/Down highlight, Enter select, Esc/blur close) but **filtering a client-side `options` array** —
consultant lists are small and already shipped as props, so no server round-trip.

- **Matching:** case-insensitive **substring** on the option label ("like", not prefix or exact), so
  `ali` matches "Dr. Khalid Alizadeh". Works for Arabic labels (plain substring).
- **API:** `v-model` on the option **id**, plus `options` (`[{id, name, …}]`), `placeholder`,
  `disabled`, and `searchableFrom` (default **8**).
- **Self-adapting:** at or below `searchableFrom` options it renders a plain native `<select>`
  (better on mobile, where the OS picker is genuinely good for short lists); above it, the searchable
  combobox. One component, right behaviour by default, no per-site judgement needed.
- **Applied to the long lists only:** consultant pickers (assign, reassign from/to, specialty
  transfer, patient form, board filter) and the specialty / Control reference pickers. Short
  enumerations (gender, code status, location, discharge destination, mode) stay native.
- Must work inside `BaseModal`'s focus trap — `IcdTypeahead` already does, so mirror its structure.

## Testing

- **PHPUnit:** `handoverPending` reads zero with no reminders (the backlog reset); a transfer with an
  incomplete handover flags exactly that admission; saving the note clears it from all four surfaces;
  the receiving consultant holds no `handover.incomplete` (§2 regression); `notifications.admission_id`
  is set by writers and backfilled by the migration; zero-patient consultants absent from board,
  Active List and `consultantBoard`.
- **Vitest:** dropdown renders both groups inside one scroll container with a capped height and
  `overscroll-contain`; Clear empties ordinary items while pinned alarms remain; opening the bell no
  longer auto-clears; `SearchableSelect` filters by substring, falls back to a native select at/below
  the threshold, and emits the id on selection.
- **Gates:** Vitest + two-pass PHPUnit + build + source allow-list + contrast.

## Out of scope

- The "on service, no patients" footer line (§Decisions.5).
- Any daily-currency obligation (the amber card icon stays informational only).
- Converting short enumeration selects to the searchable component.
- Server-side search for `SearchableSelect` (client-side only; `IcdTypeahead` remains the
  server-backed component for large reference data).

## Build order

§1 (scope + migration + call sites) → §2 (regression test only) → §5 (three list edits) →
§3 (dropdown scrolling) → §4 (Clear + feed query) → §6 (SearchableSelect + apply to long lists).
