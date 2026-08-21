# Per-Specialty Consultation Ledger — Design

**Date:** 2026-08-21
**Status:** Approved in brainstorming; awaiting spec review before planning.
**Goal:** Turn the consultation section from a flat, unscoped, write-once list into a **bookkeeping ledger owned by each IM subspecialty team** — the team logs the consults it was asked to see, works them daily, hands them over, and signs them off.

**Explicitly NOT a HIS replacement.** The authoritative clinical note stays in the hospital information system. Everything captured here is coordination, bookkeeping and handover material.

---

## 1. What exists today (verified)

One controller (166 lines, six actions), one shared FormRequest, one Vue page, one flat table.

- **Create** collects ten hand-typed fields; there is **no patient lookup** — the MRN is retyped even for a patient already on the board, and if it matches nothing `patient_id` is silently `NULL`. `entered_by` is stamped from the session and is *not* spoofable ([ConsultationsController.php:95](../../../app/Http/Controllers/ConsultationsController.php)).
- **State is binary**, derived from `signoff_date IS NULL` ([Consultation.php:37](../../../app/Models/Consultation.php)).
- **Sign-off writes a date and nothing else** — no recommendation, no time, no `signed_off_by` ([ConsultationsController.php:112](../../../app/Http/Controllers/ConsultationsController.php)). "What did Cardiology recommend?" is unanswerable in the app.
- **No scoping.** Every clinical user sees every consultation. `to_service` is free text and is never grouped or aggregated anywhere.
- **Both clinical dates are `DATE`, not `DATETIME`** ([create_consultations_table.php:19,26](../../../database/migrations/2026_06_08_120005_create_consultations_table.php)) — so **no turnaround metric is computable at all**.
- **No `admission_id`.** A consult attaches to a patient only; re-admissions and ward/ICU transfers each create a new admission row, so a consult cannot be tied to the stay it belongs to.
- **Nothing notifies anyone.** No bell entry or email on create, despite a working notification inbox used by handovers.
- **Physicians cannot see consultation analytics** — Statistics, Registry and Reports are admin-only.
- Two live defects: the dashboard "Signed off (24h)" donut actually spans up to 48 hours (`signoff_date >= yesterday`, [DashboardController.php:44](../../../app/Http/Controllers/DashboardController.php)), and the delete dialog says "permanently delete… cannot be undone" when it is a **soft** delete that admins can restore ([Index.vue:113](../../../resources/js/Pages/Consultations/Index.vue)).

**What is genuinely good and must be preserved:** session-sourced `entered_by`; every write audited with a field-level diff on modify; the Observer read-only guarantee enforced *before* capability flags; authorization centralised in `User::canManageConsultation()`; soft delete with a working transactional restore; the search term travelling in a POST body so PHI never enters a URL; ~37 backend authorization test methods.

---

## 2. Product definition

Each **IM subspecialty team** (Cardiology, Nephrology, GI, … — the subspecialists already modelled in `specialties` and `users.specialty_id`) keeps its own book:

1. A consult arrives (entered by the team, or booked in by a coordinator).
2. It sits as **New / not seen** until someone reviews it.
3. It becomes **Active (daily F/U)** — the team rounds on it daily and ticks it off.
4. Or **Ongoing (no daily F/U)** — still on the books, not a daily commitment.
5. Finally **Signed off** — with a recorded response.

The daily tick-off produces the team's working list ("seen 8 of 12 today") and the raw material for handover.

---

## 3. Scope

**In scope:** specialty scoping + coordinator capability; the four-state model; daily follow-up log; response capture; real timestamps; admission linkage; patient lookup on create; physician-visible dashboards; the `legacy:import` safety gate; the two defect fixes above.

**Out of scope:** clinical documentation of any depth (stays in the HIS); hospital-wide services beyond the IM unit; a requester-facing referral workflow; any change to admissions, handover or the patients board beyond what linkage requires.

---

## 4. Data model

### 4.1 Extend `consultations` (additive — all 1,283 historical rows retained)

| Column | Type | Notes |
|---|---|---|
| `owning_specialty_id` | FK → `specialties`, nullable, indexed | **The scoping key.** Replaces free-text `to_service` as authoritative. Nullable so unmatched legacy rows land in an "Unassigned" bucket rather than being invented into a team. |
| `status` | string(16), indexed, default `new` | `new` · `active` · `ongoing` · `signed_off` |
| `signed_off_by` | FK → `users`, nullable, `nullOnDelete` | Currently unrecorded outside the audit log. |
| `admission_id` | FK → `admissions`, nullable, `nullOnDelete`, indexed | Ties a consult to the actual stay. Nullable — historical rows cannot be resolved reliably. |
| `requested_at` | datetime, nullable, indexed | Real request time. **NULL for all historical rows** (see §4.4). |
| `signed_off_at` | datetime, nullable | Real sign-off time. NULL for historical rows. |
| `response_disposition` | string(32), nullable | Structured outcome, e.g. `advice_given` · `taking_over` · `follow_up_arranged` · `no_further_input`. Exact vocabulary is [NEEDS CLINICAL REVIEW]. |
| `response_followup_needed` | boolean, nullable | |
| `response_note` | text, nullable | Free-text working note — **not** clinical documentation. |

`to_service` and `consultation_date` are **retained unchanged** for display and historical continuity. `owning_specialty_id` and `requested_at` are authoritative going forward.

### 4.2 New table `consultation_followups`

Copies the proven append-only shape of `handover_revisions` ([migration:28-34](../../../database/migrations/2026_06_11_000001_create_handover_tables.php)):

```php
Schema::create('consultation_followups', function (Blueprint $table) {
    $table->id();
    $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
    $table->date('followup_date');
    $table->text('note')->nullable();                    // optional one-liner
    $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('created_at')->useCurrent();       // append-only — no updated_at
    $table->unique(['consultation_id', 'followup_date']); // one tick per consult per day
});
```

The unique constraint is the correctness guarantee: a consult cannot be double-ticked for the same day, so "seen 8 of 12 today" is always exact.

### 4.3 Integrity rules
- A consult with `status = signed_off` must have `signoff_date` set; clearing sign-off returns it to `ongoing`.
- `response_disposition` is **required at sign-off** (see §6.3); `response_note` stays optional.
- A follow-up may only be recorded on a consult in `new`, `active` or `ongoing` — never on a signed-off one.

### 4.4 Backfill of existing rows (a deliberate decision, not a default)

| Existing row | Backfilled `status` |
|---|---|
| `signoff_date` NOT NULL | `signed_off` |
| `signoff_date` NULL | **`ongoing`** — *not* `active` |

**Why `ongoing`:** mapping open legacy consults to `active` would silently create a daily follow-up obligation for every one of them, so on launch day every team would open a huge fabricated "must be seen today" worklist. This project has already been burned by exactly that failure mode with the handover backlog. `ongoing` states the truth — on the books, no daily commitment asserted — and teams promote to `active` deliberately.

- `owning_specialty_id`: derived by case-insensitive match of `to_service` against `specialties.name`, following the precedent of migration `2026_06_11_000002_resolve_numeric_consultation_to_service`. No match (including external services such as "Some Outside Clinic") ⇒ NULL ⇒ **Unassigned** bucket, visible to admins and coordinators only.
- `requested_at` / `signed_off_at`: **left NULL for historical rows.** Fabricating a time from a date-only column would manufacture precision that never existed. Turnaround metrics therefore cover *cutover onward* and must exclude NULLs explicitly.

---

## 5. Permissions

| Action | Who |
|---|---|
| See + create within **own** specialty | any clinical role with a `specialty_id` |
| "Mine" filter within that list | all of the above |
| Create into **any** specialty, assign owning specialty + consultant, **see all**, **modify all** | **Coordinator capability** (new flag, default off) |
| Record a daily follow-up | anyone who can see the consult (own specialty, coordinator, admin) |
| Sign off | owning consultant · `can_manage` · admin |
| Reverse sign-off (same-day) | admin only |
| Delete (soft) | admin only |
| Anything | **Observers: never** — read-only enforced before capability flags |

- The coordinator flag is a new `users` column (naming: `can_coordinate_consultations`), granted per user in **Control → Users** alongside the existing `can_assign` / `can_add` / `can_manage` / `can_modify`.
- **Ownership is `owning_specialty_id` + `consultant_id`, assigned at entry. It is independent of `entered_by`.** `entered_by` remains immutable, session-sourced, and is now *displayed* (list, detail, audit) rather than hidden.
- Coordinators deliberately **cannot** sign off (signing off asserts the clinical work is done), delete, or reverse a sign-off. [NEEDS CLINICAL REVIEW]
- Enforcement is server-side in a single predicate per action, mirroring `User::canManageConsultation()` ([User.php:112-119](../../../app/Models/User.php)); the UI hides what the server refuses, never the reverse.

---

## 6. Workflow

### 6.1 States
`new` → `active` ⇄ `ongoing` → `signed_off`, with `signed_off` reversible to `ongoing` by an admin on the same day.

### 6.2 Transitions
All transitions are **explicit user actions**. The single exception: recording the first follow-up on a `new` consult moves it to `active`, because "not seen" has become untrue. No other automatic movement — the system never silently reclassifies a patient. [NEEDS CLINICAL REVIEW]

### 6.3 Sign-off
Sign-off opens a small form rather than being a bare one-click action: `response_disposition` (required), `response_followup_needed`, `response_note` (optional). It writes `signoff_date`, `signed_off_at`, `signed_off_by`, sets `status = signed_off`, and audits.

### 6.4 Notification
When a **coordinator** creates or reassigns a consult into a specialty, the owning consultant receives one in-app notification via the existing inbox. Self-entered consults raise none (the team already knows). This closes the "placing a consultation notifies nobody" gap without adding noise.

---

## 7. The workspace (`/consultations`)

- **Scoped by default** to the viewer's specialty, with the existing `scope=mine` toggle retained and widened to all clinical roles.
- **Four status tabs with live counts**, replacing Active/Signed off/All.
- **Ageing column** — "open 6 days" — derived from `requested_at` (falling back to `consultation_date` for historical rows).
- **Today's follow-up worklist**: the `active` set with a one-tap check-off and optional note, showing "seen 8 of 12 today".
- **Patient lookup on create** using the existing `POST /api/patients/quick-search` ([web.php:110](../../../routes/web.php)) instead of retyping the MRN; unmatched MRNs warn instead of silently storing `patient_id = NULL`.
- **`entered_by` shown distinctly** from the owning consultant.
- **Sortable columns** (currently fixed `ORDER BY id DESC`) and a date-range filter.
- **Handover view**: the service's `active` + `ongoing` list with each consult's latest follow-up note — the printable/reviewable shift artefact.
- Existing quality retained: unsaved-changes guard, ErrorSummary, double-submit guard, PHI-free URLs.

---

## 8. Dashboards for physicians

Currently blocked by routing, not just missing metrics. We add a **physician-scoped consultation dashboard**, scoped to the viewer's specialty (admins/coordinators may pick a specialty):

| Metric | Computable from |
|---|---|
| Open by status (new / active / ongoing) | existing + `status` |
| Ageing buckets (0-2d, 3-7d, >7d) | `requested_at` (historical fall back to `consultation_date`) |
| Today's follow-up completeness | `consultation_followups` |
| Median time to first review, and to sign-off | `requested_at` → first follow-up / `signed_off_at` — **cutover onward only** |
| Volume trend + top indications | existing columns |
| Per-consultant load within the specialty | `consultant_id` |

**Defect fixes shipped alongside:** correct the "Signed off (24h)" window to a true 24 hours (or relabel it honestly), and correct the delete dialog copy to say the consult is recoverable from Trash.

Metrics that depend on `requested_at` must render an explicit "from cutover" note rather than silently mixing NULL historical rows into an average.

---

## 9. Cutover safety — task zero

`php artisan legacy:import` **truncates `consultations`** and rebuilds from the legacy database ([LegacyImport.php:43-47](../../../app/Console/Commands/LegacyImport.php)). Every column in this design is absent from the legacy schema.

**Therefore: if a single new field ships before this is fixed, the next data reload silently destroys every consult the doctors entered.** This is a hard gate and must land first.

**Required behaviour:** a `settings.consultations_source_of_truth` boolean (default **false**, flipped to true at cutover from Control → System). When true, `legacy:import` **skips the `consultations` table entirely** — it neither truncates nor re-imports it — and prints an explicit line saying consultations were preserved and why. Admissions, patients and reference tables continue to import unchanged, so the command stays useful for the rest of the data.

Chosen over splitting the command into a separate one-time historical migration because a flag is reversible, is visible in the same Control panel the owner already uses, and leaves one import path to maintain rather than two.

The command must **fail loudly** rather than proceed silently if the flag is true but a caller forces a consultations wipe. Covered by a test (§10).

---

## 10. Testing

- **Scoping:** a Cardiology user sees only Cardiology consults; cannot create into Nephrology; a coordinator can do both; an admin sees all; an Observer is refused everywhere.
- **Ownership vs entry:** a coordinator-created consult is owned by the assigned consultant and specialty while `entered_by` records the coordinator; `entered_by` cannot be altered by request payload.
- **State machine:** every legal transition; illegal ones refused; follow-up on a signed-off consult refused; first follow-up on `new` promotes to `active`.
- **Follow-up uniqueness:** a second tick for the same consult on the same day is rejected, so completeness counts stay exact.
- **Sign-off:** requires disposition; writes all three sign-off fields; only permitted actors succeed; coordinators refused.
- **Import safety:** `legacy:import` does not destroy new-system consultations once the source-of-truth flag is set.
- **Backfill:** open legacy rows land in `ongoing`, never `active`; unmatched `to_service` lands Unassigned; `requested_at` stays NULL.
- **Metrics:** turnaround excludes NULL `requested_at`; the 24h window is truly 24h.
- The ~37 existing authorization test methods must continue to pass or be deliberately updated — never deleted.

---

## 11. Delivery waves

| Wave | Content |
|---|---|
| **W0 — safety gate** | `legacy:import` protection + the two defect fixes. Nothing else ships first. |
| **W1 — foundation** | Schema additions, backfill migration, coordinator capability, specialty scoping (server + UI). |
| **W2 — workflow** | Four states, sign-off form with response capture, daily follow-up log and worklist, patient lookup on create, coordinator notification. |
| **W3 — handover** | The service handover view with latest follow-up notes. |
| **W4 — dashboards** | Physician-scoped consultation dashboard. **Deliberately last** — ageing and turnaround charts need weeks of real transitions before they show anything meaningful. |

---

## 12. Open questions [NEEDS CLINICAL REVIEW]

1. Should coordinators be able to **sign off**? Design says no.
2. The `response_disposition` vocabulary — the proposed values are a starting point, not a standard.
3. Should the first follow-up on a `new` consult **auto-promote** to `active`, or should status always be moved by hand?
4. Should `ongoing` consults appear on any periodic review list, or is it acceptable for one to sit untouched indefinitely?
5. Is a daily tick expected from **any** team member, or specifically the owning consultant?
6. Do any IM subspecialty teams genuinely need to be hidden from one another, or is specialty scoping purely a convenience filter?
