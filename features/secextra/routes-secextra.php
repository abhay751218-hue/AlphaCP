
// ---- Security extras: Hotlink + Leech Protection ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/hotlink-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'hotlink'])
        ->middleware('perm:security.view')->name('secextra.hotlink');
    Route::get('/leech-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'leech'])
        ->middleware('perm:security.view')->name('secextra.leech');
    Route::post('/security-extras', [\App\Http\Controllers\SecurityExtrasController::class, 'store'])
        ->middleware('perm:security.manage')->name('secextra.store');
});
// ---- /Security extras ----
