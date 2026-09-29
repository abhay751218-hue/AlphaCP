<?php

declare(strict_types=1);

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\PackagesController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AlphaCP panel routes
|--------------------------------------------------------------------------
| Contract for future edits (any AI/dev):
|   * Every privileged action lives behind `auth` + `2fa` + `password.fresh`.
|   * Permission checks live in the `perm:` middleware, never in views.
|   * Module routes keep the module prefix (users.*, system.*, audit.* …) so
|   * Step 3+ modules can be dropped in without touching what exists here.
|   * Anything that changes state MUST write to the audit log.
*/

// ---------------------------------------------------------------------------
// Guest
// ---------------------------------------------------------------------------
Route::middleware('guest')->group(function (): void {
    Route::get('/', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');
});

// ---------------------------------------------------------------------------
// Authenticated (2FA challenge happens before anything else)
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function (): void {
    Route::get('/two-factor', [TwoFactorController::class, 'challenge'])->name('twofactor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('twofactor.verify');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

// ---------------------------------------------------------------------------
// Panel (auth + 2FA verified + fresh password)
// ---------------------------------------------------------------------------
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // -- Security (always available to the logged-in user) -------------------
    Route::prefix('security')->name('security.')->group(function (): void {
        Route::get('/', [SecurityController::class, 'index'])->name('index');
        Route::post('/2fa/start', [SecurityController::class, 'startTwoFactor'])->name('2fa.start');
        Route::post('/2fa/confirm', [SecurityController::class, 'confirmTwoFactor'])->name('2fa.confirm');
        Route::post('/2fa/disable', [SecurityController::class, 'disableTwoFactor'])->name('2fa.disable');
        Route::get('/password', [SecurityController::class, 'password'])->name('password');
        Route::post('/password', [SecurityController::class, 'updatePassword'])->name('password.update');
        Route::get('/sessions', [SecurityController::class, 'sessions'])->name('sessions');
        Route::delete('/sessions/{id}', [SecurityController::class, 'destroySession'])->name('sessions.destroy');
    });

    // -- Hosting accounts (Step 3). /create MUST sit before /{account}.
    Route::get('/accounts', [AccountsController::class, 'index'])
        ->middleware('perm:accounts.view')->name('accounts.index');
    Route::get('/accounts/create', [AccountsController::class, 'create'])
        ->middleware('perm:accounts.create')->name('accounts.create');
    Route::post('/accounts', [AccountsController::class, 'store'])
        ->middleware('perm:accounts.create')->name('accounts.store');
    Route::get('/accounts/{account}', [AccountsController::class, 'show'])
        ->middleware('perm:accounts.view')->name('accounts.show');
    Route::post('/accounts/{account}/suspend', [AccountsController::class, 'suspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.suspend');
    Route::post('/accounts/{account}/unsuspend', [AccountsController::class, 'unsuspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.unsuspend');
    Route::post('/accounts/{account}/terminate', [AccountsController::class, 'terminate'])
        ->middleware('perm:accounts.terminate')->name('accounts.terminate');
    Route::post('/accounts/{account}/upgrade', [AccountsController::class, 'upgrade'])
        ->middleware('perm:accounts.modify')->name('accounts.upgrade');
    Route::post('/accounts/{account}/quota', [AccountsController::class, 'quota'])
        ->middleware('perm:accounts.modify')->name('accounts.quota');

    Route::get('/packages', [PackagesController::class, 'index'])
        ->middleware('perm:packages.view')->name('packages.index');
    Route::middleware('perm:packages.manage')->group(function (): void {
        Route::get('/packages/create', [PackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [PackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [PackagesController::class, 'edit'])->name('packages.edit');
        Route::put('/packages/{package}', [PackagesController::class, 'update'])->name('packages.update');
        Route::post('/packages/{package}/archive', [PackagesController::class, 'archive'])->name('packages.archive');
    });

    // -- Users (panel logins) -------------------------------------------------
    Route::middleware('perm:users.view')->group(function (): void {
        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    });
    Route::middleware('perm:users.manage')->group(function (): void {
        Route::get('/users/create', [UsersController::class, 'create'])->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/password', [UsersController::class, 'resetPassword'])->name('users.password');
    });

    // -- Audit -----------------------------------------------------------------
    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('perm:audit.view')->name('audit.index');

    // -- License / trial (admin) -----------------------------------------------
    Route::prefix('license')->name('license.')->middleware('perm:license.view')->group(function (): void {
        Route::get('/', [LicenseController::class, 'index'])->name('index');
        Route::post('/activate', [LicenseController::class, 'activate'])
            ->middleware('perm:license.manage')->name('activate');
    });

    // -- Server (admin) ----------------------------------------------------------
    Route::prefix('system')->name('system.')->middleware('perm:system.view')->group(function (): void {
        Route::get('/', [SystemController::class, 'index'])->name('index');
        Route::get('/services', [SystemController::class, 'services'])->name('services');
        Route::get('/tasks', [SystemController::class, 'tasks'])->name('tasks');
        Route::post('/tasks/run', [SystemController::class, 'runTask'])
            ->middleware('perm:system.manage')->name('tasks.run');
    });
});

// Fallback: unknown panel URLs get a clean 404 page, not a stack trace.
Route::fallback(fn () => response()->view('errors.404', [], 404));
