
// ---- Site Software / App Installer (WordPress one-click) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/apps', [\App\Http\Controllers\AppsController::class, 'index'])
        ->middleware('perm:software.view')->name('apps.index');
    Route::post('/apps', [\App\Http\Controllers\AppsController::class, 'store'])
        ->middleware('perm:software.manage')->name('apps.store');
});
// ---- /Site Software ----
