
// ---- Monitoring / Resource Usage ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/monitoring', [\App\Http\Controllers\MonitoringController::class, 'index'])
        ->middleware('perm:metrics.view')->name('monitoring.index');
});
// ---- /Monitoring ----
