<?php

use App\Http\Controllers\AdmissionsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ConsultationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PatientsController;
use App\Http\Controllers\RegistryController;
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
    Route::get('/consultations', [ConsultationsController::class, 'index'])->name('consultations.index');

    Route::get('/admissions/create', [AdmissionsController::class, 'create'])->name('admissions.create');
    Route::post('/admissions', [AdmissionsController::class, 'store'])->name('admissions.store');
    Route::get('/api/icd10', [AdmissionsController::class, 'icd10'])->name('icd10.search');

    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
    Route::get('/registry/export', [RegistryController::class, 'export'])->name('registry.export');
});
