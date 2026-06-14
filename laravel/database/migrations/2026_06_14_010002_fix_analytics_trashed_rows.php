<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Phase 4 — Item 1: MARKER migration (no schema change). It documents the exhaustive list of raw
 * DB::table() analytics that bypass the SoftDeletes global scope and therefore had explicit
 * whereNull('...deleted_at') added by hand. The actual enforcement is the failing-test-driven
 * checklist in SoftDeleteTest (test_stats_exclude_soft_deleted_admissions / _report_excludes_... /
 * census). This file exists so the change is greppable in the migration history and a future schema
 * audit can find the rationale.
 *
 * RAW analytics that gained whereNull('admissions.deleted_at') (or the bare-column equivalent for
 * single-table queries, and whereNull('consultations.deleted_at') where consultations are counted):
 *
 *   DashboardController::index
 *     - census / activeIcu, admissionsToday, dischargesToday, deathsMonth, boardingCount,
 *       boardingWorklist, avgLosMonth, priorWeekOccupancy, deathsPrior, readmitYtd, myUnit.*
 *     - heavy tier: 30-day admBy/disBy, LOS distribution, census mix donut, donutTb,
 *       perConsultant, consultantBoard, adm24/dis24 activity, ytd.admissions/discharges,
 *       activeConsults/signed24h/ytd.consultations/ytd.signoffs (consultations.deleted_at)
 *   StatisticsController::index / physician / compareBlock / gatherExportTables / exportPdf
 *     - every admissions + consultations COUNT/AVG/series/readmission-join query
 *   ReportsController::index / gather / gatherBooklet / governancePdf / consultantPdf +
 *     helpers byMonth/byDay/losByMonth/readmitsByMonth/censusFamilies/destinationBuckets/
 *     consultantLos + availableYears DISTINCT
 *   Concerns\MetricQueries::seriesBy (shared by Statistics + Reports)
 *
 * RegistryController + PatientsController use Eloquent Admission::query()/Consultation::query()
 * for their result sets, which DO get the global scope — no raw guard needed there. Their only raw
 * DB::table() calls hit reference tables (icd10, tb_diagnoses) and are unaffected.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
