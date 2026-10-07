
// ---- File extras: Images + Optimize Website + Trash ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/images', [\App\Http\Controllers\ImagesController::class, 'index'])
        ->middleware('perm:files.view')->name('images.index');
    Route::get('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'index'])
        ->middleware('perm:files.view')->name('optimize.index');
    Route::post('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'store'])
        ->middleware('perm:files.manage')->name('optimize.store');
    Route::get('/trash', [\App\Http\Controllers\TrashController::class, 'index'])
        ->middleware('perm:files.view')->name('trash.index');
    Route::delete('/trash/{file}', [\App\Http\Controllers\TrashController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('trash.destroy');
});
// ---- /File extras ----
