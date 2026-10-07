
// ---- Reseller Center (WHM-style) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/resellers', [\App\Http\Controllers\ResellersController::class, 'index'])
        ->middleware('perm:users.view')->name('resellers.index');
    Route::post('/resellers', [\App\Http\Controllers\ResellersController::class, 'store'])
        ->middleware('perm:roles.manage')->name('resellers.store');
    Route::post('/resellers/privileges', [\App\Http\Controllers\ResellersController::class, 'updatePrivileges'])
        ->middleware('perm:roles.manage')->name('resellers.privileges');
    Route::delete('/resellers/{user}', [\App\Http\Controllers\ResellersController::class, 'destroy'])
        ->middleware('perm:roles.manage')->name('resellers.destroy');
});
// ---- /Reseller Center ----
