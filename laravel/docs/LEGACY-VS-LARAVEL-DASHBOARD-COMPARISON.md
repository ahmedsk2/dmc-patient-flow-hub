# Legacy ↔ Laravel dashboard comparison (dump `dbqeqbacgfvmhk (4)`, 2026-07-12)

**Task:** load the latest legacy dump into *both* the old PHP app and the new Laravel app, compare
the dashboard numbers, and find any discrepancy.

**Headline result:** there was a **real and serious discrepancy** — the Laravel dashboard's **active
patient census was ~3× too high (390 vs the correct 115)**. Root cause found, fixed, and both
dashboards now agree to the patient. Details below.

---

## How the comparison was run

- Loaded `dbqeqbacgfvmhk (4).sql` into `dmc_prod` (the DB the **legacy PHP app** reads directly, and
  the source the Laravel importer reads).
- Ran `php artisan legacy:import` to transform it into the Laravel schema (`dmc_laravel`).
- Computed every headline dashboard metric on **both** databases using each app's *exact* query
  logic (legacy: `dashboard/1.php` + `dashboard/3.php`; Laravel: `DashboardController`), with a
  single shared `CURDATE()` so only **definitional/data** differences surface (not clock differences).
- Row-level reconciliation confirms the two DBs hold the same episodes: **admissions 36,641 ==
  picupatients 36,641**, consultations 1,280, users 328.

---

## THE BUG — active census 3× inflated (FIXED)

| Metric | Legacy | Laravel (before) | Laravel (after fix) |
|---|---|---|---|
| **Active census** (discharge date NULL) | **115** | **390** ❌ | **115** ✓ |
| Active ICU | 17 | 33 ❌ | 17 ✓ |
| Active ward (non-ICU) | 98 | 357 ❌ | 98 ✓ |

### Root cause
The two apps hold the same 36,641 episodes, yet 390 vs 115 read as "still admitted." Joining the two
databases on the original row id showed **exactly 275 rows that the legacy app shows as *discharged*
but Laravel showed as *active*** — broken down as:

- **82 rows** where the recorded discharge date is **before** the admit date, and
- **193 rows** where the discharge date is **before** the medical-discharge date.

These are legacy **data-entry errors** (an impossible discharge date). The Laravel schema adds CHECK
constraints forbidding an inverted discharge date, and the importer's self-heal satisfied them by
**NULL-ing the bad `discharge_date`**. But a NULL discharge date means "currently admitted" — so 275
long-since-discharged patients were **resurrected as active**, inflating the census from 115 to 390.
The legacy app keeps the (bad) date, so those patients correctly stay *discharged*.

### The fix
`app/Console/Commands/LegacyImport.php` — instead of NULL-ing an inverted discharge date, **clamp it
up** to the latest valid anchor (the admit date, or the medical-discharge date). The patient stays
*discharged* (matching legacy), length-of-stay becomes ≥ 0, and all three `chk_*` date constraints
still hold. Covered by an updated `LegacyImportTest` case (inverted discharge → clamped, not nulled).

After the fix + re-import, **census, ICU, and ward match the legacy app exactly** (115 / 17 / 98).

---

## Everything else — after the fix

| Metric | Legacy | Laravel | Verdict |
|---|---|---|---|
| YTD admissions (non-ICU) | 4,564 | 4,564 | ✓ exact |
| YTD discharges (non-ICU) | 4,552 | 4,551 | ≈ (−1, see note A) |
| Avg LOS this month | 4.56 | 4.63 | ≈ (Laravel more correct, note B) |
| Assigned non-ICU census (donut) | 92 | 92 | ✓ exact |
| Active consultations | 0 | 0 | ✓ exact |
| Total consultations / sign-offs this year | 0 | 0 | ✓ exact (note C) |

**Note A — discharges −1:** legacy counts by `YEAR(DISDATE)`, using the *recorded* (sometimes
inverted) discharge date; after clamping, one clamped row's discharge year shifts. This is an
inherent effect of the bad source data, not a code defect. (Before the census fix this gap was 57;
it's now 1.)

**Note B — average LOS:** legacy averages `DATEDIFF(discharge, admit)` with **no `>= 0` guard**, so
its data-entry errors contribute *negative* stays that drag the mean down (4.56). Laravel guards
`DATEDIFF >= 0`, so 4.63 is the **more correct** figure. This is a deliberate, documented improvement,
not a discrepancy to "fix."

**Note C — consultations are stale in this dump:** the newest `consultation_date` is **2025-03-23**
and the newest `signoff_date` is **2025-07-01**, so every "this year (2026)" consultation/sign-off
metric is legitimately **0 in both apps**. (Admissions, by contrast, run right up to today.)

---

## Definitional notes (same-named metric, different meaning — by design)

These are *not* bugs; the Laravel replatform deliberately tightened some definitions. Worth knowing
when eyeballing the two dashboards side by side:

- **"Currently in ICU":** the legacy *Year-Overview* card counts only ICU patients assigned to a
  `position = 3` consultant (16), while the Laravel KPI tile counts **all** active ICU patients (17).
  The one-patient gap is an ICU patient not assigned to a consultant. Laravel's all-inclusive count
  is the clinically correct census.
- **"Total (non-ICU)":** the legacy dashboard shows *different* non-ICU totals on different cards
  (92 assigned-to-a-consultant on the Year-Overview table vs. the full 98 elsewhere), because each
  card applies a different consultant filter. Laravel's KPI ward tile is the full 98; its per-service
  donut is the assigned 92 — both are shown, clearly labelled.
- **Consultations donut / "since last day"** blocks match the legacy `signoff_date + 1 day >=
  CURDATE()` window (yesterday + today).

---

## Deliverables from this task

1. **Importer fix** (`LegacyImport.php`) — clamp inverted discharge dates instead of nulling, so the
   active census is faithful. Shipped with a regression test.
2. **Regenerated DB export** — `~/Downloads/dmc_laravel_export.sql(.gz)` now carries the corrected
   data (115 active, not 390). Re-upload this if you already imported the earlier one.
3. **This report.**

> ⚠️ If you already imported the earlier `dmc_laravel_export.sql` to the demo, **re-import the
> regenerated one** (or re-run `legacy:import` after pulling this fix) — otherwise the demo dashboard
> will still show the inflated ~390 census.

---

## Round 2 — side-by-side PDF comparison (dump 5, 2026-07-13)

Compared the two printed dashboards directly. **Every headline number matches or is explained:**

| Metric | Legacy dashboard | Laravel "Command Center" | Verdict |
|---|---|---|---|
| Total admissions (year) | 4,566 | 4,566 | ✓ exact |
| Total discharges (year) | 4,552 | 4,551 | ≈ (−1, bad-date edge) |
| Avg LOS (month) | 4.56 | 4.63 | ≈ (Laravel guards negative LOS) |
| Consultations / Sign-offs (year) | 0 / 0 | 0 / 0 | ✓ (stale consult data) |
| Total non-ICU (assigned) | 92 | 92 | ✓ exact |
| Currently in ICU | 15 | 16 | ≈ (legacy tile counts only consultant-assigned ICU; Laravel counts all active ICU — the extra 1 is an unassigned ICU patient) |
| TB (current) | — | 9 | (Laravel donut) |

### BUG FOUND & FIXED — the "New" column was blank
The Laravel *"Patient count per consultant"* table showed **OLD/ACTIVE/WARD populated but NEW empty**.
Cause: the dashboard (and the active-board "New" badge) defined **"New" as a rolling last-24h window**
(`assigned_at >= now − 24h`). On an imported snapshot the newest assignment is backfilled to
`assigned_on` **midnight**, so once you're a day past that midnight it falls outside 24h → **0 for
everyone**. The legacy dashboard's "New" is the sticky **`newassign` flag** (no date filter) = **27**
patients — a managed operational signal (set on assign/handover/shuffle, cleared on discharge/reassign).

**Fix:** "New" now keys on the `is_new_assignment` **flag** everywhere it's counted — the dashboard
consultant table, the "My unit" lens, and the active-board badge/counts — matching the legacy app and
staying consistent across the two screens. Verified: the column now shows **27** (matching legacy),
and a regression test pins that a flag-set assignment with a >24h-old `assigned_at` still counts as New.
Files: `DashboardController`, `PatientsController`; test in `Phase1DashboardValueTest`. This is a CODE
fix — deploy it with `git pull` (the data/export is unaffected).
