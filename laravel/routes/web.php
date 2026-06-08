<?php

use App\Http\Controllers\AdmissionsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ConsultationsController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PatientActionController;
use App\Http\Controllers\PatientsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

// Guest
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

// Authenticated
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.alt');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/patients', [PatientsController::class, 'index'])->name('patients.index');
    Route::post('/admissions/{admission}/assign', [PatientActionController::class, 'assign'])->name('admissions.assign');
    Route::post('/admissions/{admission}/discharge', [PatientActionController::class, 'discharge'])->name('admissions.discharge');
    Route::post('/admissions/{admission}/transfer', [PatientActionController::class, 'transfer'])->name('admissions.transfer');
    Route::post('/admissions/{admission}/reverse-discharge', [PatientActionController::class, 'reverseDischarge'])->name('admissions.reverse');
    Route::get('/consultations', [ConsultationsController::class, 'index'])->name('consultations.index');
    Route::post('/consultations', [ConsultationsController::class, 'store'])->name('consultations.store');
    Route::post('/consultations/{consultation}/signoff', [ConsultationsController::class, 'signoff'])->name('consultations.signoff');

    Route::get('/admissions/create', [AdmissionsController::class, 'create'])->name('admissions.create');
    Route::post('/admissions', [AdmissionsController::class, 'store'])->name('admissions.store');
    Route::get('/api/icd10', [AdmissionsController::class, 'icd10'])->name('icd10.search');

    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
    Route::get('/registry/export', [RegistryController::class, 'export'])->name('registry.export');
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Admin
    Route::middleware('admin')->group(function () {
        Route::get('/control', [ControlController::class, 'index'])->name('control.index');
        Route::put('/control/settings', [ControlController::class, 'updateSettings'])->name('control.settings');
        Route::put('/control/users/{user}', [ControlController::class, 'updateUser'])->name('control.users.update');
    });
});
