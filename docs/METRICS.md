# DMC — Statistics & Charts: metrics dictionary

> The **exact definition** of every number shown on the dashboard, the statistics pages, and the A4
> reports — so clinicians can trust them and future changes don't reintroduce drift. Every definition
> here is enforced by the value-validation harness [`tools/e2e/test_stats_values.php`](../tools/e2e/test_stats_values.php),
> which compares each displayed number to an independent recomputation from these rules.
>
> Timezone is **Asia/Riyadh** (no DST, so day-diffs are exact). Date ranges in the engine are
> **half-open** `[start, end)`.

## Common definitions (used everywhere unless stated)

| Term | Definition |
|---|---|
| **Non-ICU** | `current_location != 'ICU' OR current_location IS NULL`. The default scope for "admissions/discharges" on the dashboard, KPI table, time trend, per-physician and A4 — ICU activity is reported separately. |
| **Admission** | a `picupatients` row, dated by **`ADMDATE`**. (One row per admission *episode*; a re-admission or ward↔ICU transfer is a new row.) |
| **Discharge** | dated by **`DISDATE`** (the file-close date). A "medically discharged, still in" patient has `med_DISDATE` set but `DISDATE` NULL and is **not** yet a discharge. |
| **Length of stay (LOS)** | `DATEDIFF(DISDATE, ADMDATE)` in whole days (or `DATEDIFF(med_DISDATE, ADMDATE)` where a metric is defined on the medical-discharge date). |
| **Mortality** | `MORTALITY = 'Dead'`. **ICU mortality** = Dead AND `current_location = 'ICU'`; **ward mortality** = Dead AND non-ICU. |
| **Transfer to ICU** | `DISTO = 'Intensive Care (ICU)'` (dated by `DISDATE`). |
| **Consultation (new)** | a `consultations` row, dated by **`consultation_date`**. |
| **Sign-off** | a `consultations` row dated by **`signoff_date`** (independent of when the consultation was entered). |
| **72-hour readmission** | an admission whose **same patient (MRN)** had an **earlier** episode (lower `ID`) that was discharged (`trans_discharge IN ('discharge from ward','discharge from ICU')`, `DISDATE` not null) within **3 days** before this admission's `ADMDATE`: `ADMDATE <= DATE_ADD(prior.DISDATE, INTERVAL 3 DAY)`. Counted on the readmission episode, by its `ADMDATE`. |
| **Consultant attribution** | consultations and patients are attributed to **`consultant_id`** (the consultant the work is for), not `entered_by_id`/`admitted_by`. |
| **Active patient** | `DISDATE IS NULL`. Some surfaces further restrict to non-ICU and/or exclude long-term/TB — stated per metric. (Per-page variants are intentional; see notes.) |

---

## Dashboard (`dashboard.php` → `dashboard/1.php` + `dashboard/3.php`)

**30-Day Overview (line)** — for each of the last 31 calendar days: **Admissions** (`ADMDATE = day`, non-ICU) and **Discharges** (`DISDATE = day`, non-ICU).

**Current Patients (doughnut)** — among **active, non-ICU** patients (`DISDATE IS NULL`):
- **Hospitalist** = consultant's `specialty_id = 1` and not long-term.
- **Sub Speciality** = consultant's `specialty_id != 1` and not long-term.
- **Long Term** = `longterm = 'longterm'`.
- Title also shows **TB patients** = distinct active non-ICU patients with ≥1 diagnosis in `tb_list`.

**Admission/Discharge per Consultant since last day (bar)** — for each **active, position-3** consultant: **admitted** = `ADMDATE + 1 day >= CURDATE()` (non-ICU); **discharged** = `DISDATE + 1 day >= CURDATE()` (non-ICU).

**Consultations since last day (doughnut)** — **Signed Off** = `signoff_date + 1 day >= CURDATE()`; **Active Consultations** = `signoff_date IS NULL` (all open consultations).

**This Week Top 5 Diagnoses** — the 5 most frequent ICD-10 codes across `admissiondiagnosis` for admissions in the current ISO week (`WEEK(ADMDATE)`).

**Year Overview (table, `dashboard/3.php`)** — current year `Y`:
- **Average LOS for <month>** = `AVG(DATEDIFF(DISDATE, ADMDATE))` over non-ICU discharges in the current month.
- **Total Admissions for Y** = non-ICU admissions with `YEAR(ADMDATE) = Y`.
- **Total Discharges** = non-ICU discharges with `YEAR(DISDATE) = Y`.
- **Total Consultations for Y** = `YEAR(consultation_date) = Y`.
- **Total Sign Offs** = `YEAR(signoff_date) = Y` — *counted independently of the consultation's entry year* (a consultation entered last year but signed off this year **counts** this year). *(Corrected 2026-06-08; previously coupled to `consultation_date` year, which undercounted.)*
- **Currently in ICU** = active patients with `current_location = 'ICU'`.
- **Hospitalist capacity utilization** = `active non-ICU patients / (hospitalist_count × settings.max_hospitalist) × 100`, where hospitalist_count = active, on-service, specialty-1 consultants.

---

## Statistics page (`statistics.php` → `kpis.php`, `charts1.php`, `charts.php`)

### KPI table (`kpis.php`) — per period (daily = days of month / monthly = 12 months / quarterly = 4 quarters of the selected year)
| Row | Definition |
|---|---|
| Admissions | non-ICU, by `ADMDATE` in the period |
| Discharges | non-ICU, by `DISDATE` |
| Trans To ICU | `DISTO = 'Intensive Care (ICU)'`, by `DISDATE` |
| ICU Mortality | `current_location = 'ICU' AND MORTALITY = 'Dead'`, by `DISDATE` |
| Out ICU Mortality | non-ICU `AND MORTALITY = 'Dead'`, by `DISDATE` |
| Consultations | by `consultation_date` |
| Sign Offs | by `signoff_date` |
| 72hr Readmissions | the 72-hour readmission rule above, by readmission `ADMDATE` |

### KPI admission chart (`charts1.php`, the "Admissions / Consultations" panel)
Bars are **per active position-3 consultant** (`members.position = 3 AND active = 1`) for the **single selected period** (day / month / quarter of the chosen date). Per consultant (`consultant_id`): **Admissions**, **Discharges** (non-ICU), **New Consultations** (`consultation_date`), **Signed Off** (`signoff_date`).
> **Scope note:** admissions/consultations attributed to a consultant who is **not** active-position-3 (e.g. a deactivated member) are not shown here — this panel is a current-consultant view, not a grand total. The KPI table above is the all-inclusive total.

### Per-physician charts (`charts.php`) — one selected consultant
- **Daily**: each day of the selected month — Admissions, Discharges (non-ICU), New Consultations, Signed Off, all filtered to that `consultant_id`, each counted **independently per day**. *(Fixed 2026-06-08 — previously a patient×consultation cross join inflated the counts and drew one flat total across all days.)*
- **Monthly**: each of the 12 months of the selected year (same metrics). *(Fixed 2026-06-08 — a month-key type mismatch had zeroed Jan–Sep.)*
- **Quarterly**: each quarter of the selected year.

---

## All-statistics page (`allstat.php` → `time1.php` + `kpis.php`)

**Time trend (`time1.php`)** — **global** (all consultants), per period: last **31 days** (daily) / last **12 months** (monthly) / the **4 quarters of the current year** (quarterly). Series: **Admissions**, **Discharges** (non-ICU), **New Consultations** (`consultation_date`), **Signed Off Consultations** (`signoff_date`). Plus the **KPI table** (same definitions as the statistics page).

---

## A4 reports (`statistics/a4.php` yearly, `statistics/a4-monthly.php` per-month) and the server-side PDF (`pdf-report.php` → `DMC\Reports\YearlyReport`)

The PDF report and `a4.php` are kept **numerically identical** (enforced by `tools/report_data_validate.php`). For the selected year:
- **Monthly Admissions / Discharges / New Consultations / Signed Off** — same definitions as above, bucketed by month.
- **Length-of-stay charts**: **Medical LOS** (`DATEDIFF(med_DISDATE, ADMDATE)`), **Physical/complete LOS** (`DATEDIFF(DISDATE, ADMDATE)`), **ICU LOS**, **Per-consultant average LOS** — averaged per month/consultant over the relevant discharges.
- **Census / bed-days** — occupied bed-days per month: `SUM(GREATEST(0, DATEDIFF(LEAST(month_cap, IFNULL(DISDATE, month_cap)), GREATEST(ADMDATE, month_start)) + 1))`, where `month_cap = min(last day of month, today)`. Weekend = `DAYOFWEEK(DISDATE) IN (6,7)` (Fri/Sat).
- **Discharge destinations** — count of discharges grouped by `DISTO`.
- **Mortality** — ICU vs ward as above.

---

## Notes & intentional variants
- **"Active patient" has per-page variants by design.** The canonical definition is `DISDATE IS NULL`; individual boards additionally exclude ICU / long-term / TB / medically-discharged depending on what that board is for. These are intentional (do not "unify" them without confirming the per-page intent).
- **Consultations are attributed to `consultant_id`**, the directed consultant — not the person who entered them.
- **ICU is reported separately** from ward activity throughout (the non-ICU filter), with dedicated ICU mortality / ICU LOS / "currently in ICU" metrics.
- Changes that move any of these numbers must be re-validated with `php tools/e2e/test_stats_values.php` (registered in `tests/run.php`).
