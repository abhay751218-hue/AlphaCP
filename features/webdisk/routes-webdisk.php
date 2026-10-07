
// ---- Web Disk (WebDAV accounts) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'index'])
        ->middleware('perm:files.view')->name('webdisk.index');
    Route::post('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'store'])
        ->middleware('perm:files.manage')->name('webdisk.store');
    Route::delete('/webdisk/{webDiskAccount}', [\App\Http\Controllers\WebDiskController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('webdisk.destroy');
});
// ---- /Web Disk ----
