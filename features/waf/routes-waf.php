
// ---- Security Tools (ModSecurity WAF + Virus Scanner) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/security-tools', [\App\Http\Controllers\SecurityToolsController::class, 'index'])
        ->middleware('perm:security.view')->name('security-tools.index');
    Route::post('/security-tools/modsec', [\App\Http\Controllers\SecurityToolsController::class, 'toggleModsec'])
        ->middleware('perm:security.view')->name('security-tools.modsec');
    Route::post('/security-tools/scan', [\App\Http\Controllers\SecurityToolsController::class, 'scan'])
        ->middleware('perm:security.view')->name('security-tools.scan');
});
// ---- /Security Tools ----
