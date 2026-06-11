<?php

use App\Http\Controllers\AdmissionsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ConsultationsController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\PatientActionController;
use App\Http\Controllers\PatientsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecentController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

// Guest
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');   // brute-force guard
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email')->middleware('throttle:5,1');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

// MFA login challenge — reached after password but BEFORE the session is authenticated
// (identity held in the session, not the auth guard).
Route::get('/mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
Route::post('/mfa/challenge', [MfaController::class, 'verifyChallenge'])->middleware('throttle:5,1');   // brute-force guard on the 6-digit code

// Authenticated
Route::middleware(['auth', 'mfa.enroll', 'pwd'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.alt');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/patients', [PatientsController::class, 'index'])->name('patients.index');
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
    Route::post('/admissions/{admission}/reverse-discharge', [PatientActionController::class, 'reverseDischarge'])->name('admissions.reverse');
    Route::post('/admissions/{admission}/undo-medical-discharge', [PatientActionController::class, 'undoMedicalDischarge'])->name('admissions.undoMedical');
    Route::delete('/admissions/{admission}', [PatientActionController::class, 'destroy'])->name('admissions.destroy');   // admin-only (enforced in the action)

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
    Route::post('/consultations/{consultation}/signoff', [ConsultationsController::class, 'signoff'])->name('consultations.signoff');
    Route::post('/consultations/{consultation}/reverse-signoff', [ConsultationsController::class, 'reverseSignoff'])->name('consultations.reverseSignoff');
    Route::put('/consultations/{consultation}', [ConsultationsController::class, 'update'])->name('consultations.update');
    Route::delete('/consultations/{consultation}', [ConsultationsController::class, 'destroy'])->name('consultations.destroy');

    Route::get('/admissions', [AdmissionsController::class, 'index'])->name('admissions.index');
    Route::get('/admissions/create', [AdmissionsController::class, 'create'])->name('admissions.create');
    Route::post('/admissions', [AdmissionsController::class, 'store'])->name('admissions.store');
    Route::get('/api/icd10', [AdmissionsController::class, 'icd10'])->name('icd10.search');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Two-factor (TOTP) enrollment
    Route::get('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->name('mfa.confirm');
    Route::delete('/mfa', [MfaController::class, 'disable'])->name('mfa.disable');

    // Admin — analytics + registry + reports + all exports are admin-only (PHI exposure control;
    // non-admins' only statistics is the dashboard).
    Route::middleware('admin')->group(function () {
        Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
        Route::get('/registry/export', [RegistryController::class, 'export'])->name('registry.export');
        Route::get('/registry/export-xlsx', [RegistryController::class, 'exportXlsx'])->name('registry.export.xlsx');
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/pdf', [ReportsController::class, 'pdf'])->name('reports.pdf');
        Route::get('/reports/monthly', [ReportsController::class, 'monthly'])->name('reports.monthly');
        Route::get('/reports/monthly/pdf', [ReportsController::class, 'monthlyPdf'])->name('reports.monthly.pdf');

        Route::get('/recent', [RecentController::class, 'index'])->name('recent.index');
        Route::get('/import', [ImportController::class, 'index'])->name('import.index');
        Route::post('/import/preview', [ImportController::class, 'preview'])->name('import.preview');
        Route::post('/import', [ImportController::class, 'store'])->name('import.store');
        Route::get('/control', [ControlController::class, 'index'])->name('control.index');
        Route::put('/control/settings', [ControlController::class, 'updateSettings'])->name('control.settings');
        Route::put('/control/users/{user}', [ControlController::class, 'updateUser'])->name('control.users.update');
        Route::delete('/control/users/{user}', [ControlController::class, 'destroyUser'])->name('control.users.destroy');
        Route::post('/control/users/{user}/reset-mfa', [ControlController::class, 'resetMfa'])->name('control.users.resetMfa');
        Route::post('/control/users/{user}/send-reset', [ControlController::class, 'sendReset'])->name('control.users.sendReset');
        Route::post('/control/specialties', [ControlController::class, 'addSpecialty'])->name('control.specialties.add');
        Route::post('/control/reasons', [ControlController::class, 'addReason'])->name('control.reasons.add');
    });
});
