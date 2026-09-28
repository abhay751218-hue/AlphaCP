<?php
declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// --- public ---------------------------------------------------------------
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// --- panel (login required) ----------------------------------------------
Route::middleware('panel.auth')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Slice 2B-1 stops here. Module routes (files, email, domains, ...) land with
    // their roadmap steps — see docs/09-cpanel-parity-checklist.md.
    Route::view('/coming-soon', 'coming-soon')->name('coming.soon');
});
