# Handover — clinical-audit & compliance reference

**Audience:** clinical governance / audit reviewers, and the engineers who support them.
**Scope:** how a patient handover is created, edited, transferred, acknowledged, and reminded
about in the DMC Internal Medicine hub — and **exactly what is retained** so an auditor can
reconstruct "who wrote what, when, and who took over care."

Every mutation described here is **attributed to the signed-in user from the server session**,
never to a value supplied by the browser. Attribution cannot be spoofed by the client.

---

## 1. What a "handover" is here

A handover is the current cross-cover note for one **admission** (one hospital episode of one
patient). It has:

- a free-text **body** (the clinical narrative), and
- a set of **checkpoints** — six structured flags captured alongside the note:
  `vte_completed`, `ready_for_discharge`, `high_risk`, `needs_workup`, `workup_pending`
  (booleans), and `code_status` (`full` / `dnr` / `dni`, or unset).

There is always **at most one current handover row per admission** (`handovers`), plus an
**append-only history** of every version ever saved (`handover_revisions`).

---

## 2. Lifecycle

### 2.1 Create / edit a note
A permitted user (the admission's primary consultant, a user with the Manage capability, an
admin, or — after a gated transfer — the still-pending outgoing consultant) opens the handover
editor, edits the body and/or checkpoints, and saves.

On each save (`POST /admissions/{admission}/handover` → `HandoverController::save`):
1. The `handovers` row is upserted: `body`, `checkpoints`, `updated_by` (= session user),
   `updated_at` (= now, always stamped so an unchanged note still counts as "refreshed today").
2. A **new immutable `handover_revisions` row is appended**, snapshotting the `body`, the
   `checkpoints`, the `author_id` (= session user), and `created_at`. Revisions are never
   updated or deleted — they are the changelog.
3. An `audit_logs` entry `handover.update` is written (actor = session user, target = the
   admission, detail = the new revision id).
4. Any open incomplete-handover reminders for this admission are **resolved** (see §4).

A save that changes only checkpoints (body unchanged) still writes a full revision — so the
checkpoint history is captured at the same fidelity as the text.

### 2.2 Reassignment (bulk transfer of a consultant's patients)
An authorised user (admin, or Assign/Manage capability) moves the active patients of one
consultant to another (`POST /admissions/reassign` → `PatientActionController::bulkReassign`):

- Each moved admission's `consultant_id` is updated to the new consultant.
- A **`handover_signatures`** row is created per moved admission recording the outgoing and
  incoming consultants and the revision in effect — the incoming consultant is expected to
  acknowledge it (§2.3).
- A `handover.transfer` notification is sent to the **receiving** consultant.

**Soft handover gate (governance-relevant change).** The transfer is **no longer blocked** when
a moved patient's handover was not refreshed today. Instead the move proceeds and a **persistent
reminder** is raised (§4) so the note gets completed after the fact. This was a deliberate,
owner-approved policy change from the previous hard block. The event is auditable:
`handover.reassign_incomplete` records the affected admission ids and who was reminded.

### 2.3 Receiving-consultant sign-off
The incoming consultant (or an admin) acknowledges the handover from their inbox
(`/handovers` → `HandoverController::sign` / `signMany`). Signing:
- stamps `signed_at` and `signed_by` on the `handover_signatures` row, and
- **re-binds the signature to the CURRENT latest revision** — i.e. the record captures the exact
  version of the note the signer actually read, not an older one.
- writes an `audit_logs` `handover.sign` entry.

A signature can be superseded/`voided_at` if the situation changes (e.g. a re-transfer); voided
requests are retained, not deleted.

### 2.4 Reading (break-glass)
Handover notes are readable by all clinical roles by design (cross-cover). When an admin turns on
**record-open logging** (`settings.log_record_opens`), every open of a handover writes an
`audit_logs` `handover.read` entry (actor + patient MRN) — a break-glass trail for sensitive
episodes.

---

## 3. Checkpoints (structured clinical flags)

| Flag | Meaning |
|---|---|
| `vte_completed` | VTE prophylaxis addressed |
| `ready_for_discharge` | Clinically ready for discharge |
| `high_risk` | High-risk patient — extra attention on cross-cover |
| `needs_workup` | Needs further work-up |
| `workup_pending` | Work-up ordered, results pending |
| `code_status` | Resuscitation status: Full / DNR / DNI (or unset) |

Checkpoints are **captured and versioned** but do **not** gate completion — a saved note is what
marks a handover "done." The set is stored as JSON on both `handovers` (current) and every
`handover_revisions` row (history), so an auditor can see the code-status / risk flags as they
stood at any past version. The column is intentionally JSON so additional flags can be added
later without a schema change.

---

## 4. Persistent "incomplete handover" reminders

When a reassignment moves a patient whose handover was not current that day, a reminder is raised
so it does not fall through the cracks:

- **Recipients:** the acting user **and** the outgoing (from-)consultant. If they are the same
  person, a single reminder is created (de-duplicated).
- **Type / payload:** `notifications.type = 'handover.incomplete'`, payload
  `{admission_id, patient_name, mrn, from_name, to_name}`.
- **Persistence:** the reminder stays "unresolved" (`resolved_at IS NULL`) and keeps the bell's
  unread badge lit **even after the user opens the bell** — opening the bell (read-all) dismisses
  ordinary notifications but not these. It appears in a pinned **"Needs attention"** group.
- **Resolution:** the reminder auto-resolves for **every** recipient the moment **any** handover
  note is saved for that admission — `resolved_at` is stamped. There is no manual dismiss; the
  clinical action (writing the note) is what clears it.

This gives a closed-loop, timestamped trail: raised (with recipients) → resolved (when the note
was written).

---

## 5. What is retained for audit (the record)

| Store | What it proves | Key columns | Mutability |
|---|---|---|---|
| `handover_revisions` | Every edit of the note: who, when, full text, checkpoint snapshot | `admission_id`, `body`, `checkpoints`, `author_id`, `created_at` | **Append-only** — never updated or deleted |
| `handover_signatures` | Which consultant acknowledged which exact revision, and when; who was the outgoing consultant | `admission_id`, `from_consultant_id`, `to_consultant_id`, `revision_id`, `required_at`, `signed_at`, `signed_by`, `voided_at` | Stamped on sign/void; rows retained |
| `audit_logs` | The action trail | events: `handover.update`, `handover.sign`, `handover.read` (break-glass), `handover.reassign_incomplete` + the reassign move itself; columns `actor_id`, `actor_name`, `action`, `entity_type`, `entity_id`, `details` (JSON), `ip`, `created_at` | **Append-only + tamper-evident** (each row is chained by a `row_hash`; run `php artisan audit:verify` to detect any edited/removed row) |
| `notifications` | The reminder trail: raised → resolved, with recipients + timestamps | `user_id`, `type='handover.incomplete'`, `payload`, `created_at`, `resolved_at` | `resolved_at` stamped on completion |
| `handovers` | The current state only (latest body + checkpoints) | `admission_id`, `body`, `checkpoints`, `updated_by`, `updated_at` | Overwritten each save — history lives in `handover_revisions` |

**Immutability note.** `handover_revisions` and `audit_logs` are append-only: the app never edits
a past revision or an audit row in place. To reconstruct history, read the revisions in order —
the current `handovers` row is only ever the newest snapshot.

---

## 6. How to answer common audit questions

> **"Who wrote the handover for this patient, and what did it say at each point in time?"**
```sql
SELECT r.id, r.created_at, u.full_name AS author, r.body, r.checkpoints
FROM handover_revisions r
JOIN users u ON u.id = r.author_id
WHERE r.admission_id = :admission_id
ORDER BY r.id;          -- oldest → newest; each row is one saved version
```

> **"Was the incoming consultant's acknowledgement recorded, and against which version?"**
```sql
SELECT s.from_consultant_id, s.to_consultant_id, s.revision_id,
       s.required_at, s.signed_at, s.signed_by, s.voided_at
FROM handover_signatures s
WHERE s.admission_id = :admission_id
ORDER BY s.required_at;   -- signed_at NULL = never acknowledged; revision_id = exact version read
```

> **"Which reassignments proceeded without a completed handover, and were they later completed?"**
```sql
SELECT n.created_at AS raised_at, n.resolved_at,
       n.payload->>'$.patient_name' AS patient,
       n.payload->>'$.mrn'          AS mrn,
       n.payload->>'$.from_name'    AS outgoing_consultant,
       n.payload->>'$.to_name'      AS incoming_consultant,
       n.user_id                    AS reminded_user
FROM notifications n
WHERE n.type = 'handover.incomplete'
ORDER BY n.created_at;
-- resolved_at IS NULL  → still outstanding
-- resolved_at NOT NULL → completed at that timestamp
```
The same soft-gate events are also in `audit_logs` under `handover.reassign_incomplete` (with the
admission ids and the reminded recipients), and the underlying move is in the reassign audit entry.

> **"Who opened this patient's handover (break-glass)?"**
```sql
SELECT created_at, actor_id, actor_name, details
FROM audit_logs
WHERE action = 'handover.read' AND entity_type = 'admission' AND entity_id = :admission_id
ORDER BY created_at;
-- only populated for periods when settings.log_record_opens was ON
```

---

## 7. Trust boundary (why the record is reliable)

- Author / editor / signer / reminder-recipient identity is taken from the **server session**,
  not the request body. A user cannot attribute an action to someone else.
- Revisions and audit logs are **append-only**; there is no edit-in-place path in the app. The
  `audit_logs` trail is additionally **tamper-evident** — rows are linked by a `row_hash` chain, so
  a deleted or altered row is detectable with `php artisan audit:verify`.
- Checkpoints are snapshotted **per revision**, so code-status / risk flags are auditable at every
  historical version, not just the current one.
- Reminders record both the **raise** and the **resolution** with timestamps and recipients,
  evidencing that a soft-gated transfer was followed up.
