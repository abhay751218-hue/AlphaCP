
// ---- G5: Git Version Control + Terminal ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/git', [\App\Http\Controllers\GitController::class, 'index'])
        ->middleware('perm:files.view')->name('git.index');
    Route::post('/git/clone', [\App\Http\Controllers\GitController::class, 'clone'])
        ->middleware('perm:files.manage')->name('git.clone');
    Route::get('/git/status/{dir}', [\App\Http\Controllers\GitController::class, 'status'])
        ->middleware('perm:files.view')->name('git.status');
    Route::post('/git/pull/{dir}', [\App\Http\Controllers\GitController::class, 'pull'])
        ->middleware('perm:files.manage')->name('git.pull');

    Route::get('/terminal', [\App\Http\Controllers\TerminalController::class, 'index'])
        ->middleware('perm:system.manage')->name('terminal.index');
    Route::post('/terminal', [\App\Http\Controllers\TerminalController::class, 'run'])
        ->middleware('perm:system.manage')->name('terminal.run');
});
// ---- /G5 ----
