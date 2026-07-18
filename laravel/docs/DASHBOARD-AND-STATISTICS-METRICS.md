# DMC Internal Medicine — Dashboard & Statistics metrics

> The exact definition, formula, source columns, and caveats for **every number** on the Dashboard,
> Statistics, and Reports pages. Companion: **[DATABASE-AND-BEHAVIOR.md](DATABASE-AND-BEHAVIOR.md)**.
>
> Sources: `app/Http/Controllers/DashboardController.php`, `StatisticsController.php`, `ReportsController.php`.
> All queries are **sargable** — date filters use range predicates on the indexed `admit_date` /
> `discharge_date` columns (no `MONTH()`/`YEAR()` wrapping that would defeat the index).

---

## 0. Shared conventions

| Term | Exact definition |
|---|---|
| **Non-ICU ("ward")** | `current_location <> 'ICU' OR current_location IS NULL` |
| **Active** | `discharge_date IS NULL` |
| **Length of stay (LOS)** | `DATEDIFF(discharge_date, admit_date)` in **days**, only counting rows where the result is `>= 0`. Whole days (a same-day discharge = 0). |
| **Avg LOS** | `AVG(DATEDIFF(discharge_date, admit_date))` over **discharges in the period**, **non-ICU**. ICU LOS is the same over ICU rows. |
| **Mortality %** | `deaths / discharges × 100` (deaths counted by `outcome='Dead'`). |
| **Readmission** | A new admission whose `admit_date` is within **`readmission_window_days`** (settings; default 3 = the "72-hour" rule, **clinically confirmed 2026-06-09**, admin-tunable in Control) of the **same patient's** prior **real** discharge (`prev.transfer_type IN ('discharge from ward','discharge from ICU') OR prev.transfer_type IS NULL`). **NULL-typed historical discharges count as anchors** — legacy parity (round-5 J1-4, deliberate): old rows were closed without a transfer type, and the legacy app accepted them everywhere, so the recon-pinned 317 readmissions may legitimately **rise**. Ward↔ICU and specialty transfers (explicit types) are continuations of care and stay **excluded**, so same-day transfer rows don't inflate it. UI labels (stats KPI, board badge, registry filter) display the configured window. |
| **Admissions counted by** | `admit_date` in range. **Discharges / deaths by** `discharge_date` in range. **Consultations by** `consultation_date`; **sign-offs by** `signoff_date`. |

Operational inputs come from the single `settings` row (editable in **Control → Settings**, every
change recorded in `setting_changes`): `short_los` (5), `long_los` (11), **`ward_beds`**, `icu_beds`,
`readmission_window_days` (3), `min/max_hospitalist`, `min/max_subs`.

✅ **Clinically confirmed (2026-06-09):** `short_los`=5 / `long_los`=11, the readmission window
default of 3 days (now admin-tunable), and the shuffle min/max values.
⚠️ **Still placeholder:** `ward_beds` / `icu_beds` — set the real licensed counts in Control
(drives Bed Occupancy — see §1).

---

## 1. Dashboard  (`/`)

### KPI cards
| Card | Formula |
|---|---|
| **Active Census** | `COUNT(admissions WHERE discharge_date IS NULL)` (all locations). |
| **Ward** | census − ICU. |
| **ICU** | active `AND current_location='ICU'`. |
| **Admissions today** | `admit_date = today AND non-ICU`. |
| **Discharges today** | `discharge_date = today AND non-ICU`. |
| **Active consults** | `COUNT(consultations WHERE signoff_date IS NULL)`. |
| **Deaths (month)** | `outcome='Dead' AND discharge_date BETWEEN month_start AND today`. |
| **Bed Occupancy** | **`active ward (non-ICU) ÷ ward_beds × 100`**, rounded to 0.1. The **true %** is shown on the card and in the gauge label (it can exceed 100 = over-census); the radial gauge **arc** is capped at 100. `ward_beds` comes from `settings` and **must be set to your real licensed ward-bed count in Control** (default 50 is a placeholder). ICU patients are excluded from both sides. |

> Note: Bed Occupancy was previously a *staffing* proxy (`active ÷ (hospitalists × max_hospitalist)`). It is now **real physical occupancy** against `ward_beds`. `max_hospitalist` still drives the auto-assign shuffle, just not this number.

### Charts
| Chart | Definition |
|---|---|
| **30-day admissions vs discharges** | For each of the last 30 days: admissions by `admit_date`, discharges by `discharge_date`, both non-ICU. |
| **Consultations (6 months)** | Per calendar month: new = `COUNT(consultation_date in month)`, signed = `COUNT(signoff_date in month)`. |
| **Length of stay (this year)** | Discharges this year, non-ICU, LOS bucketed into `0–2 / 3–5 / 6–10 / 11–20 / 21+` days. |
| **Census by service** (donut) | Active non-ICU **assigned** rows (unassigned/`consultant_id IS NULL` excluded — legacy INNER JOIN `members`; H2/B10) split into: hospitalist (`specialty_id=1`, not long-term), subspecialty (other specialties, not long-term), long-term (`is_longterm=1`). The card headline "Current patients: N (incl. M TB)" counts **this same population** (`donutTotal` = sum of the slices; `donutTb` = its TB subset — legacy `dashboard/1.php:151-154`; M1/5), NOT the all-active census on the KPI tile. |
| **Active load by consultant** | `COUNT(active non-ICU admissions)` grouped by consultant, top 8. |
| **Top diagnoses (week N, all years)** | Admissions whose `WEEK(admit_date)` equals `WEEK(today)` — the SAME calendar week-number across ALL years (legacy `dashboard/3.php` seasonal view; H2/B8 — was "last 7 days"); count of each ICD-10 code (joined to `icd10.name`), top 5. |
| **Patient count per consultant** (table) | USERS-driven (H2/B9), but lists only consultants who actually hold at least one active admission — a zero-census consultant no longer appears (the old "every on-service consultant, zeros included" behaviour was noise). Per row, over their **active** admissions: **Old** (assigned >24h ago/never), **New** (assigned within 24h), **Ward** (non-ICU), **ICU**, **TB** (any diagnosis ∈ `tb_diagnoses`), **Active** (non-ICU AND not medically-discharged AND not long-term AND not TB), **Total**. Grouped: on-service hospitalists (specialty 1) → on-service subspecialists → off-service. |

---

## 2. Statistics  (`/statistics`)  — date range + interval

Date range defaults to year-to-date; **interval** = Daily / Monthly / Quarterly (re-buckets the time series and the KPI grid over the selected range). Bucket key SQL: day = `DATE(col)`, month = `DATE_FORMAT(col,'%Y-%m')`, quarter = `CONCAT(YEAR(col),'-Q',QUARTER(col))`.

### Headline KPIs (over the selected range)
| KPI | Formula |
|---|---|
| **Admissions** | `admit_date` in range, non-ICU. |
| **Discharges** | `discharge_date` in range, non-ICU. |
| **ICU admissions** | `admit_date` in range, `current_location='ICU'`. |
| **Mortality** | `outcome='Dead'`, `discharge_date` in range; **Mortality %** = deaths ÷ discharges × 100. |
| **Avg LOS** | `AVG(DATEDIFF(discharge_date, admit_date))`, discharges in range, non-ICU, `≥0`. |
| **Consultations** | `consultation_date` in range. **Sign-offs** = `signoff_date` in range. |
| **72h readmits** | distinct admissions in range that match the 72-hour-readmission rule (§0). |

### Charts / tables
| Element | Definition |
|---|---|
| **Time series** (area) | Admissions / discharges / mortality per bucket over the range. |
| **Monthly KPI grid** (table) | Per bucket: admissions, discharges, ICU, mortality, consultations, sign-offs, avg LOS. |
| **LOS distribution** | Discharges in range, non-ICU, bucketed as on the dashboard. |
| **Admission source** (donut) | `COUNT` grouped by `admitted_from` (top 6). |
| **Top diagnoses** | ICD-10 codes on admissions in range (top 8). |
| **Consultation indications** | Decode each consultation's `indication` JSON → `consultation_reasons.name`, tally (top 8). |
| **Admissions by consultant** | `COUNT(admissions in range)` by consultant (top 10). **Per-consultant admissions and discharges are NON-ICU** like the headline KPIs and the legacy per-physician stats (round-5 J1-12); the physician drill-down admission/discharge series follow the same rule (consultations/sign-offs are never location-split). |
| **Per-consultant readmissions** | Credited to the **PRIOR discharge's consultant** (`prev.consultant_id`) — legacy semantics: the metric reflects whose discharge bounced back, not who received the readmission (round-5 J1-13). The headline/grid readmission counts are unaffected. |
| **Discharge destinations** (donut) | `COUNT` grouped by `discharge_to` (blank → "Unspecified"), discharges in range, top 8 — **overall** or, via the selector, **per a chosen consultant** (top 12 consultants pivoted by destination). |

---

## 3. Reports

### Annual report  (`/reports?year=YYYY`)  + PDF
| Element | Definition |
|---|---|
| **KPI strip** | Admissions, Discharges, ICU, Mortality %, **Ward LOS** (avg non-ICU LOS), **ICU LOS** (avg ICU LOS), **Long-stay %**. |
| **Monthly breakdown** (table) | Per month: admissions (`admit_date`), discharges (`discharge_date`), ICU (admissions with `current_location='ICU'`), mortality (`outcome='Dead'`), **Long-stay %**. |
| **Long-stay % (LSP)** | Per month and overall: `discharges with DATEDIFF(discharge_date, admit_date) > long_los  ÷  discharges × 100`, non-ICU. `long_los` (default 11) is from settings. |
| **Top diagnoses** | ICD-10 codes on the year's admissions (top 10). |
| **Admissions by consultant** | `COUNT(year admissions)` by consultant (top 15). |
| **Discharge destinations** | `COUNT` by `discharge_to` for the year (top 12). |
| **Avg ward LOS by consultant** | `AVG(DATEDIFF)` over the consultant's non-ICU discharges that year (`n` = how many), top 15. |

### Monthly report  (`/reports/monthly?year=YYYY&month=M`)  + PDF
Per-day table for the chosen month: admissions, discharges, ICU (admissions), mortality, with weekend rows shaded and a totals row.

---

## 4. Caveats & honesty notes

- **Bed Occupancy is only as correct as `ward_beds`.** Until an admin sets the real licensed count in Control, it uses the placeholder (50) and will read far over 100% for a busy unit.
- **Thresholds are clinically confirmed (2026-06-09):** `short_los`/`long_los` (LOS band colours + LSP) and `min/max_hospitalist`/`subs` (shuffle balance) were reviewed and signed off — see §0. They remain admin-editable in Control (every change recorded in `setting_changes`).
- **The readmission window is admin-tunable** (Control → Settings, `readmission_window_days`, default 3 = the confirmed "72-hour" rule — see §0) and counts only real discharges (transfers excluded; NULL-typed historical closes count as anchors).
- **ICU vs ward:** most "ward" metrics exclude ICU by the non-ICU predicate above; ICU has its own admission/LOS figures. Mortality and consultations are **not** split by location.
- **`medical_discharge_date` is stamped on EVERY close** (round-5 J1-5, legacy med_DISDATE parity): transfers (ward↔ICU, specialty, external, ICU-pull) restamp it with the close date, and the single-step ICU discharge mirrors the discharge date. Clinical-LOS aggregates therefore include transfer-closed episodes, as legacy did.
- **Monthly LOS series carry a non-negative guard (legacy reconciles at the year grain).** Every per-month LOS average (ward/clinical/ICU and per-(month, consultant)) uses `DATEDIFF(discharge_date, admit_date) >= 0`, which **drops** rows whose stored discharge precedes admission (corrupt legacy data). Legacy `a4.php` instead used `abs()` on those rows, folding the magnitude back in. As a result a single MONTH's value can differ from legacy by ~0.01–0.03 days when that month contains a corrupt-LOS row; the **yearly aggregate reconciles exactly** (the dropped rows are a vanishing share of the annual denominator). This is a deliberate accuracy trade — a negative interval is meaningless, and `abs()` would inflate the mean with a fabricated positive stay.
- This is a **training/operational reporting tool**, not a validated clinical-decision device; numbers are descriptive aggregates of the recorded data.
