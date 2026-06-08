<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ConsultationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PatientsController;
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
});
