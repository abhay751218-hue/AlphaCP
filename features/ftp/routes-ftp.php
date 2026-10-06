
// ---- FTP Accounts (portable feature: pure-ftpd) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ftp', [\App\Http\Controllers\FtpController::class, 'index'])
        ->middleware('perm:files.view')->name('ftp.index');
    Route::post('/ftp', [\App\Http\Controllers\FtpController::class, 'store'])
        ->middleware('perm:files.manage')->name('ftp.store');
    Route::post('/ftp/{ftpAccount}/password', [\App\Http\Controllers\FtpController::class, 'password'])
        ->middleware('perm:files.manage')->name('ftp.password');
    Route::delete('/ftp/{ftpAccount}', [\App\Http\Controllers\FtpController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('ftp.destroy');
});
// ---- /FTP ----
