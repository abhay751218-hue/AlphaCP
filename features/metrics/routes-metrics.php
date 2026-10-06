
// ---- Metrics (portable feature: access-log stats) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/metrics', [\App\Http\Controllers\MetricsController::class, 'index'])
        ->middleware('perm:metrics.view')->name('metrics.index');
});
// ---- /Metrics ----
