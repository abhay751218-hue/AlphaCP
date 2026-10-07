
// ---- IP Blocker (portable feature: ufw/iptables deny) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'index'])
        ->middleware('perm:security.view')->name('ip-blocker.index');
    Route::post('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'store'])
        ->middleware('perm:security.view')->name('ip-blocker.store');
    Route::delete('/ip-blocker/{blockedIp}', [\App\Http\Controllers\IpBlockerController::class, 'destroy'])
        ->middleware('perm:security.view')->name('ip-blocker.destroy');
});
// ---- /IP Blocker ----
