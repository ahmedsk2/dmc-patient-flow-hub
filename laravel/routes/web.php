<?php

use App\Http\Controllers\AdmissionsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ConsultationsController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataQualityController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\OrphanDiagnosesController;
use App\Http\Controllers\PatientActionController;
use App\Http\Controllers\PatientMergeController;
use App\Http\Controllers\PatientsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecentController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\StepUpController;
use App\Http\Controllers\StyleGuideController;
use App\Http\Controllers\TourController;
use App\Http\Controllers\TrashedController;
use Illuminate\Support\Facades\Route;

// Legacy URL compatibility — bookmarks / printed links / muscle memory from the old file-based
// app land on their replacements (301: permanent, cacheable). index.php usually resolves to '/'
// at the front controller already; the explicit route covers setups that pass it through.
foreach ([
    '/dashboard.php' => '/',
    '/dmc-patients.php' => '/patients',
    '/dmc-new-admissions.php' => '/admissions',
    '/active-list.php' => '/active-list',
    '/dmc-new-consultation.php' => '/consultations',
    '/48discharge.php' => '/recent',
    '/48consultation.php' => '/recent',
    '/search.php' => '/registry',
    '/statistics.php' => '/statistics',
    '/allstat.php' => '/statistics',
    '/control.php' => '/control',
    '/profile.php' => '/profile',
    '/index.php' => '/login',
] as $legacy => $target) {
    Route::redirect($legacy, $target, 301);
}

// Guest
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');   // brute-force guard (username+IP key)
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email')->middleware('throttle:auth');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

// MFA login challenge — reached after password but BEFORE the session is authenticated
// (identity held in the session, not the auth guard).
Route::get('/mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
Route::post('/mfa/challenge', [MfaController::class, 'verifyChallenge'])->middleware('throttle:auth');   // brute-force guard on the 6-digit code (pending-user+IP key)

// Authenticated. session.timeout (Phase 4 — Item 2) runs first so an idle/expired session is
// bounced to login before any page work; the MFA/pwd gates follow.
Route::middleware(['auth', 'session.timeout', 'mfa.enroll', 'pwd'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.alt');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/patients', [PatientsController::class, 'index'])->name('patients.index');
    // Wave 1 (EHC UI) — SPC-TM-011: the board's free-text term (patient name/MRN) travels in a POST
    // body, never a query string (URLs land in history/proxy/access logs). GET stays for the
    // shareable non-PII filters; a legacy GET-with-term redirects term-less inside index().
    Route::post('/patients', [PatientsController::class, 'index'])->name('patients.search');
    // Wave 2, Item 2: global patient quick-jump. Lives in the AUTH (non-admin) group on purpose — it
    // is PHI-aware by SCOPING (active-only + D1 for non-admins; full history for admins), not by
    // blocking non-admins. The admin-only /api/patients/search (PatientMergeController) is separate.
    // SPC-TM-011: POST-only since Wave 1 — the term (or the recents' opaque id list) rides the body.
    Route::post('/api/patients/quick-search', [PatientsController::class, 'quickSearch'])->name('patients.quickSearch');
    Route::get('/active-list', [PatientsController::class, 'activeList'])->name('patients.activeList');   // printable census (all roles, D1-scoped)
    Route::post('/admissions/shuffle', [PatientActionController::class, 'shuffle'])->name('admissions.shuffle');
    Route::post('/admissions/reassign', [PatientActionController::class, 'bulkReassign'])->name('admissions.reassign');
    Route::post('/admissions/{admission}/assign-to-me', [PatientActionController::class, 'assignToMe'])->name('admissions.assignToMe');
    Route::post('/admissions/{admission}/longterm', [PatientActionController::class, 'toggleLongterm'])->name('admissions.longterm');
    Route::post('/admissions/{admission}/assign', [PatientActionController::class, 'assign'])->name('admissions.assign');
    Route::post('/admissions/{admission}/bed', [PatientActionController::class, 'updateBed'])->name('admissions.bed');
    Route::get('/admissions/{admission}/edit', [AdmissionsController::class, 'edit'])->name('admissions.edit');
    Route::post('/admissions/{admission}/modify', [PatientActionController::class, 'modify'])->name('admissions.modify');
    Route::post('/admissions/{admission}/medical-discharge', [PatientActionController::class, 'medicalDischarge'])->name('admissions.medicalDischarge');
    Route::post('/admissions/{admission}/complete-discharge', [PatientActionController::class, 'completeDischarge'])->name('admissions.completeDischarge');
    Route::post('/admissions/{admission}/icu-discharge', [PatientActionController::class, 'icuDischarge'])->name('admissions.icuDischarge');
    Route::post('/admissions/{admission}/transfer', [PatientActionController::class, 'transfer'])->name('admissions.transfer');
    Route::post('/admissions/{admission}/icu-pull', [PatientActionController::class, 'icuPull'])->name('admissions.icuPull');
    Route::post('/admissions/{admission}/reverse-discharge', [PatientActionController::class, 'reverseDischarge'])->name('admissions.reverse')->middleware('stepup');   // Phase 4 — Item 4
    Route::post('/admissions/{admission}/undo-medical-discharge', [PatientActionController::class, 'undoMedicalDischarge'])->name('admissions.undoMedical');
    Route::delete('/admissions/{admission}', [PatientActionController::class, 'destroy'])->name('admissions.destroy')->middleware('stepup');   // admin-only + step-up (Phase 4 — Item 4)

    // Phase 4 — Item 4: step-up re-auth form (auth-only; the gated actions enforce admin themselves)
    Route::get('/stepup', [StepUpController::class, 'show'])->name('stepup.show');
    Route::post('/stepup', [StepUpController::class, 'verify'])->name('stepup.verify');

    // Handovers — read is all-roles; save is canManage/outgoing (enforced in the action)
    Route::get('/admissions/{admission}/handover', [HandoverController::class, 'show'])->name('admissions.handover.show');
    Route::post('/admissions/{admission}/handover', [HandoverController::class, 'save'])->name('admissions.handover.save');
    Route::get('/handovers', [HandoverController::class, 'index'])->name('handovers.index');
    Route::get('/handovers/preflight', [HandoverController::class, 'preflight'])->name('handovers.preflight');
    Route::post('/handovers/sign-many', [HandoverController::class, 'signMany'])->name('handovers.signMany');
    Route::post('/handovers/{signature}/sign', [HandoverController::class, 'sign'])->name('handovers.sign');
    Route::get('/api/notifications', [HandoverController::class, 'notifications'])->name('notifications.index');
    Route::post('/notifications/read-all', [HandoverController::class, 'readAll'])->name('notifications.readAll');
    Route::get('/consultations', [ConsultationsController::class, 'index'])->name('consultations.index');
    Route::post('/consultations', [ConsultationsController::class, 'store'])->name('consultations.store');
    // Wave 1 (EHC UI) — SPC-TM-011: POST /consultations is already the create action, so the
    // term-in-body search lands on a sibling URI wired to the SAME index() (non-PII status/scope
    // stay in the query string). The GET twin catches refresh/bookmarks of that URI and falls back
    // to the term-less workspace instead of a 405.
    Route::post('/consultations/search', [ConsultationsController::class, 'index'])->name('consultations.search');
    Route::get('/consultations/search', fn () => redirect()->route('consultations.index'));
    Route::post('/consultations/{consultation}/signoff', [ConsultationsController::class, 'signoff'])->name('consultations.signoff');
    Route::post('/consultations/{consultation}/reverse-signoff', [ConsultationsController::class, 'reverseSignoff'])->name('consultations.reverseSignoff');
    Route::put('/consultations/{consultation}', [ConsultationsController::class, 'update'])->name('consultations.update');
    Route::delete('/consultations/{consultation}', [ConsultationsController::class, 'destroy'])->name('consultations.destroy');

    // Wave 1 (usability), Item 3: the Recent-Activity VIEW is read-only and open to every clinical
    // role (incl. Observer) — nurses/registrars use it to see what happened during a shift. The
    // same-day UNDO actions stay ADMIN-ONLY, enforced server-side inside the reverse-discharge /
    // reverse-signoff controllers (admissions.reverse + consultations.reverseSignoff above), NOT by
    // this route's middleware. Nav visibility is cosmetic; those controllers are the real gate.
    Route::get('/recent', [RecentController::class, 'index'])->name('recent.index');

    Route::get('/admissions', [AdmissionsController::class, 'index'])->name('admissions.index');
    Route::get('/admissions/create', [AdmissionsController::class, 'create'])->name('admissions.create');
    Route::post('/admissions', [AdmissionsController::class, 'store'])->name('admissions.store');
    Route::get('/api/icd10', [AdmissionsController::class, 'icd10'])->name('icd10.search');

    // Wave 2, Item 10: first-login onboarding tour — mark "seen" so the auto-tour stops nagging.
    // Auth group (every clinical role gets the tour); idempotent; the "?" replay never posts here.
    Route::post('/tour/complete', [TourController::class, 'complete'])->name('tour.complete');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Two-factor (TOTP) enrollment. Self-DISABLE was removed (owner decision, J2-14):
    // once enrolled, only an admin can reset MFA (Control panel reset-mfa).
    Route::get('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->name('mfa.confirm');

    // Admin — analytics + registry + reports + all exports are admin-only (PHI exposure control;
    // non-admins' only statistics is the dashboard).
    Route::middleware('admin')->group(function () {
        // Wave 0 — internal design-system reference. No patient data; admin-gated as internal tooling.
        Route::get('/style-guide', [StyleGuideController::class, 'index'])->name('styleguide');

        Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        // Phase 3 — §3.4: currently-filtered statistics exports (reuse the index query methods)
        Route::get('/statistics/export', [StatisticsController::class, 'exportXlsx'])->name('statistics.export.xlsx');
        Route::get('/statistics/export/pdf', [StatisticsController::class, 'exportPdf'])->name('statistics.export.pdf');
        Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
        // Wave 1 (EHC UI) — SPC-TM-011: the free-text term (name/MRN/diagnosis keyword — the two
        // fields the audit trail already treats as PHI) moves into a POST body; non-PII filters
        // stay in the query string so filtered views remain shareable. A legacy GET-with-term
        // redirects term-less inside index(). Count + exports gain POST twins so a term-filtered
        // count/export also carries its term in the body (the GET forms remain for term-less use).
        Route::post('/registry', [RegistryController::class, 'index'])->name('registry.search');
        // Phase 3 — §3.6: cheap match-count for the pre-export row-count advisory (before the exports)
        Route::get('/registry/count', [RegistryController::class, 'matchCount'])->name('registry.count');
        Route::post('/registry/count', [RegistryController::class, 'matchCount']);
        Route::get('/registry/export', [RegistryController::class, 'export'])->name('registry.export');
        Route::post('/registry/export', [RegistryController::class, 'export']);
        Route::get('/registry/export-xlsx', [RegistryController::class, 'exportXlsx'])->name('registry.export.xlsx');
        Route::post('/registry/export-xlsx', [RegistryController::class, 'exportXlsx']);
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/pdf', [ReportsController::class, 'pdf'])->name('reports.pdf');
        Route::get('/reports/monthly', [ReportsController::class, 'monthly'])->name('reports.monthly');
        Route::get('/reports/monthly/pdf', [ReportsController::class, 'monthlyPdf'])->name('reports.monthly.pdf');
        // Phase 3 — §3.6: stream a queued-generated (async) booklet PDF, deleted after send
        Route::get('/reports/pdf-download/{key}', [ReportsController::class, 'downloadGenerated'])->name('reports.pdf.download');
        // Phase 3 — §3.1: per-consultant scorecard PDF over a date range
        Route::get('/reports/consultant/{user}/pdf', [ReportsController::class, 'consultantPdf'])->name('reports.consultant.pdf');
        // Phase 3 — §3.2: governance / M&M pack (screen form + de-identified PDF)
        Route::get('/reports/governance', [ReportsController::class, 'governance'])->name('reports.governance');
        Route::get('/reports/governance/pdf', [ReportsController::class, 'governancePdf'])->name('reports.governance.pdf');

        Route::get('/import', [ImportController::class, 'index'])->name('import.index');
        Route::post('/import/preview', [ImportController::class, 'preview'])->name('import.preview');
        Route::post('/import', [ImportController::class, 'store'])->name('import.store');
        Route::get('/control', [ControlController::class, 'index'])->name('control.index');
        Route::put('/control/settings', [ControlController::class, 'updateSettings'])->name('control.settings');
        Route::put('/control/users/{user}', [ControlController::class, 'updateUser'])->name('control.users.update');
        Route::delete('/control/users/{user}', [ControlController::class, 'destroyUser'])->name('control.users.destroy')->middleware('stepup');   // Phase 4 — Item 4
        Route::post('/control/users/{user}/reset-mfa', [ControlController::class, 'resetMfa'])->name('control.users.resetMfa');
        Route::post('/control/users/{user}/send-reset', [ControlController::class, 'sendReset'])->name('control.users.sendReset');
        Route::post('/control/specialties', [ControlController::class, 'addSpecialty'])->name('control.specialties.add');
        Route::post('/control/reasons', [ControlController::class, 'addReason'])->name('control.reasons.add');
        // Phase 3 — §3.3: monthly-report email recipients
        Route::post('/control/report-recipients', [ControlController::class, 'addReportRecipient'])->name('control.recipients.add');
        Route::delete('/control/report-recipients/{recipient}', [ControlController::class, 'removeReportRecipient'])->name('control.recipients.destroy');

        // Audit log viewer + exports (Phase 2 — Item 1). Admin-only (route group + FormRequest::authorize).
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');
        Route::get('/audit/export-xlsx', [AuditController::class, 'exportXlsx'])->name('audit.export.xlsx');

        // Phase 4 — Item 1: Recently Deleted (soft-delete) view + Restore actions
        Route::get('/trashed', [TrashedController::class, 'index'])->name('trashed.index');
        Route::post('/trashed/admissions/{id}/restore', [TrashedController::class, 'restoreAdmission'])->name('trashed.admissions.restore');
        Route::post('/trashed/consultations/{id}/restore', [TrashedController::class, 'restoreConsultation'])->name('trashed.consultations.restore');
        Route::post('/trashed/users/{id}/restore', [TrashedController::class, 'restoreUser'])->name('trashed.users.restore');

        // Phase 4 — Item 3: Security panel (read-only login-anomaly surfacing)
        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');

        // Phase 4 — Item 5: orphan ICD-10 code report (admission diagnoses with no icd10 row)
        Route::get('/admin/orphan-diagnoses', [OrphanDiagnosesController::class, 'index'])->name('orphan.diagnoses');

        // Phase 4 — Item 6: data-quality dashboard
        Route::get('/data-quality', [DataQualityController::class, 'index'])->name('data-quality.index');

        // Phase 4 — Item 9: patient-merge / MRN-dedup tooling. The merge POST is step-up gated
        // (high-risk, identity-changing — re-points clinical history between patient records).
        Route::get('/admin/patient-merge', [PatientMergeController::class, 'index'])->name('patient-merge.index');
        // SPC-TM-011 (Wave 1): the merge typeahead searches by patient name/MRN — POST-only now.
        Route::post('/api/patients/search', [PatientMergeController::class, 'searchPatients'])->name('patient-merge.search-patients');
        Route::post('/admin/patient-merge/search', [PatientMergeController::class, 'search'])->name('patient-merge.preview');
        Route::post('/admin/patient-merge', [PatientMergeController::class, 'merge'])->name('patient-merge.merge')->middleware('stepup');
    });
});
