
// ---- Manage API Tokens (panel UI) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'index'])
        ->middleware('perm:api.view')->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'store'])
        ->middleware('perm:api.manage')->name('api-tokens.store');
    Route::delete('/api-tokens/{apiToken}', [\App\Http\Controllers\ApiTokensController::class, 'destroy'])
        ->middleware('perm:api.manage')->name('api-tokens.destroy');
});
// ---- /API Tokens ----
