
// ---- Owner Ports Config ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ports', [\App\Http\Controllers\PortsController::class, 'index'])
        ->middleware('perm:system.manage')->name('ports.index');
    Route::post('/ports', [\App\Http\Controllers\PortsController::class, 'store'])
        ->middleware('perm:system.manage')->name('ports.store');
});
// ---- /Ports ----
