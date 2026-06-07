# DMC — As-Implemented Permission Matrix

> **Purpose.** This documents exactly what the `renovation` branch **enforces server-side today**,
> extracted from the code (not from `permissions.docx`, which the maintainer flagged as dated).
> The renovation deliberately made the server-side rules **mirror the existing UI gates** (decision
> **Q7**). Use this as the review sheet to confirm the rules against the *intended* policy: where a row
> doesn't match intent, that's the change to make in `guard.php` / the endpoint.
>
> Companion to [`PROJECT-TRACKER.md`](PROJECT-TRACKER.md), [`REVIEW-FINDINGS.md`](REVIEW-FINDINGS.md)
> (finding **S1**), and [`CLAUDE.md`](CLAUDE.md) §5.

---

## 1. Roles & capability flags

**Roles** (`members.position`):

| position | role | in `permissions.docx`? |
|---|---|---|
| `0` | **Admin** | implicit (not a row in the `position` table) |
| `2` | Registrar | yes |
| `3` | Consultant | yes |
| `4` | Resident | yes |
| `5` | Observer | yes |

**Per-user capability flags** (`members.*`, `1` = granted; **Admin is implicitly granted all**):

| flag | meaning |
|---|---|
| `add_new_patient` | may admit / add patients (incl. from ICU) |
| `assign_access` | may run assignment (shuffle / assign-to-primary) |
| `manage_patient` | may act on **any** patient (not just their own) — discharge/transfer/etc. |
| `modify_patient` | may open/modify the patient record (modify modal) |

**Role groups** (defined in `sidebar.php`, used for page-level gates):

| group | positions | who |
|---|---|---|
| `$access_PICU_patients` | `[0,2,3,4]` | Admin, Registrar, Consultant, Resident (**excludes Observer**) |
| `$access_PICU_endorsement` | `[0,2,4]` | Admin, Registrar, Resident (**excludes Consultant & Observer**) — drives "All" vs "My" views |
| `$access_PICU_control` | `[0]` | **Admin only** |

---

## 2. Server-side guard primitives (`guard.php`)

| call | passes if… | denies with |
|---|---|---|
| `require_login()` | a member is authenticated | 401 → login |
| `require_role([…])` | `position` ∈ the list | 403 |
| `require_capability('flag')` | Admin **OR** the user's `flag = 1` | 403 |
| `require_patient_access($id)` | Admin **OR** `manage_patient=1` **OR** the user **is the patient's `consultant_id`** | 403 |
| `require_consultation_access($id)` | Admin **OR** `manage_patient=1` **OR** the user **is the consultation's `consultant_id`** | 403 |

Object-level checks (`require_patient_access` / `require_consultation_access`) are the **IDOR fix (S1)**:
they enforce "primary consultant or Can-Manage or Admin" against the actual DB row, so a logged-in user
cannot act on someone else's patient by changing an id.

---

## 3. Page-level gates (the full HTML pages)

| Page | Gate | Effective access |
|---|---|---|
| `dmc-patients.php`, `dmc-new-admissions.php`, `dmc-new-consultation.php`, `tb-patients.php` | `$access_PICU_patients` | Admin/Registrar/Consultant/Resident (no Observer) |
| `dmc-new-consultation.php` "All vs My", `dmc-patients.php` endorsement view | `$access_PICU_endorsement` | Admin/Registrar/Resident (Consultant sees "My" only) |
| `search.php`, `statistics.php`, `allstat.php`, `control.php`, `extract_data.php`, `dmc-old-patients.php`, `registry-tb-patients.php`, `48discharge.php`, `48consultation.php` | `$access_PICU_control` | **Admin only** |

Page POST handlers that run before render are also gated: `dmc-patients.php` (reverse-discharge / transfer →
`$access_PICU_patients`) and `dmc-old-patients.php` (confirm/add → `$access_PICU_control`) check the role
**before** the action (SEC-20 fix), and then the action endpoints re-check (defense in depth).

---

## 4. Endpoint enforcement (the action/AJAX layer — security-critical)

### Admissions & assignment
| Endpoint | Enforced rule | Effective who |
|---|---|---|
| `newpatients/dmc-patients-add.php` | `require_capability('add_new_patient')` | Admin or Can-Add |
| `newpatients/dmc-patients-icu-transfer.php` | `require_capability('add_new_patient')` | Admin or Can-Add |
| `newpatients/dmc-patients-shuffle.php` | `require_capability('assign_access')` | Admin or Can-Assign |
| `newpatients/dmc-assign-to-primary.php` | `require_capability('assign_access')` | Admin or Can-Assign |
| `newpatients/dmc-new-patients-update.php` (inline edit) | `require_role([0,2,3,4])` | clinical roles (no Observer) |

### Patient flow (discharge / transfer / reverse)
| Endpoint | Enforced rule | Effective who |
|---|---|---|
| `patients/dmc-patients-discharge.php` / `-discharge-submit.php` | `require_patient_access(id)` | Admin / Can-Manage / **own consultant** |
| `patients/dmc-patients-completedischarge.php` / `-complete-discharge-submit.php` | `require_patient_access(id)` | Admin / Can-Manage / own consultant |
| `patients/dmc-patients-icudischarge.php` / `-icu-discharge-submit.php` | `require_patient_access(id)` | Admin / Can-Manage / own consultant |
| `patients/dmc-patients-ptransferdiv.php` (transfer) | `require_patient_access(id)` | Admin / Can-Manage / own consultant |
| `dmc-patients.php` reverse-discharge & transfer handlers | `require_patient_access(id)` + page gate `$access_PICU_patients` | Admin / Can-Manage / own consultant |
| `patients/dmc-patients-modify.php`, `patients/dmc-patients-details.php` | `require_capability('modify_patient')` | Admin or Can-Modify |
| `patients/dmc-patients-update.php` (inline edit) | `require_role([0,2,3,4])` | clinical roles (no Observer) |
| `patients/dmc-patients-changeconsultant.php` / `-submit-changeconsultant.php` | `require_role([0,2,3,4])` | clinical roles (no Observer) |
| `patients/dmc-specialty-transfer-dropdown.php` | `require_role([0,2,3,4])` | clinical roles (no Observer) |
| `patients/dmc-patient-delete.php` | `require_role([0])` | **Admin only** |

### Consultations
| Endpoint | Enforced rule | Effective who |
|---|---|---|
| `consultations/dmc-consultation-add.php` | `require_role([0,2,3,4])` + W3 input validation + CSRF | clinical roles (no Observer) |
| `consultations/dmc-consultation-modify.php`, `-details.php`, `-specialty-dropdown.php` | `require_role([0,2,3,4])` | clinical roles (no Observer) |
| `dmc-new-consultation.php` sign-off handler | `require_consultation_access(id)` | Admin / Can-Manage / **own consultant** |
| `consultations/dmc-consultation-delete.php` | `require_role([0])` | **Admin only** |

### Registry / statistics / export (Admin tooling)
| Endpoint | Enforced rule | Effective who |
|---|---|---|
| `registry/search-results*.php`, `dmc-search-patient-details.php`, `dmc-search-patients-modify.php` | `require_role([0])` | **Admin only** |
| `registry/export-results-exel.php`, `export_patients.php` | `require_role([0])` | **Admin only** |
| `statistics/kpis.php`, `charts.php`, `charts1.php`, `time1.php`, `a4.php`, `a4-monthly.php` | `require_role([0])` | **Admin only** |
| `dashboard/1.php`, `dashboard/3.php` | `require_login()` | any authenticated member |
| `fetchicd10.php` (ICD-10 typeahead) | `require_login()` | any authenticated member |

### User management
| Endpoint | Enforced rule | Effective who |
|---|---|---|
| `dmc-users-update.php`, `dmc-users-delete.php` | `require_role([0])` | **Admin only** |
| `send-reset-pass-by-admin.php` | page gate `$access_PICU_control` (`[0]`) | **Admin only** |

---

## 5. Review checklist — **CONFIRMED with the maintainer 2026-06-06** (no code changes required)

- [x] **Consultant (position 3) in `$access_PICU_endorsement`** — **CONFIRMED:** Consultants see **their own**
      patients only ("My"); Registrar/Resident/Admin see "All". Intended — keep `[0,2,4]`.
- [x] **Inline list edits** (`*-update.php`) require only `require_role([0,2,3,4])`, **not** `modify_patient` —
      **CONFIRMED:** inline bed/field edits stay open to all clinical roles (Q8). `modify_patient` gates only the
      full Modify modal.
- [x] **Discharge/transfer/sign-off** = own consultant **or** `manage_patient` **or** Admin — **CONFIRMED** as the
      intended ownership rule.
- [x] **Delete patient / delete consultation = Admin only** — **CONFIRMED.**
- [ ] **Capability flags** (`add_new_patient`/`assign_access`/`manage_patient`/`modify_patient`) are per-user in
      `members`. _Operational_ — confirm the per-user grants in production match intent (a data check, not code).
- [x] **Registry, statistics, control, old-patient import, Excel export = Admin only** — **CONFIRMED.**

> If any row above is wrong for your policy, the fix is localized: change the `require_*` call in the named
> endpoint (or the `$access_PICU_*` arrays in `sidebar.php`). The **Critical** protections (every write requires
> auth; no unauthenticated/IDOR access) hold regardless of how these fine-grained rows are decided.
