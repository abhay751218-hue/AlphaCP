<?php
declare(strict_types=1);

use App\Http\Controllers\AdminController;
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

    // WHM-style admin home (superadmin/admin only) — Step 2B-UI.
    Route::middleware('panel.admin')->group(function (): void {
        Route::get('/admin', [AdminController::class, 'dashboard'])->name('admin.dashboard');
    });

    // Reseller panel home (reseller/admin/superadmin) — Step 2B-UI.
    Route::middleware('panel.reseller')->group(function (): void {
        Route::get('/reseller', [AdminController::class, 'reseller'])->name('reseller.dashboard');
    });

    // Slice 2B-1 stops here. Module routes (files, email, domains, ...) land with
    // their roadmap steps — see docs/09-cpanel-parity-checklist.md.
    Route::view('/coming-soon', 'coming-soon')->name('coming.soon');
});
