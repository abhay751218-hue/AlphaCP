
// ---- DNS Cluster (WHM) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'index'])
        ->middleware('perm:dns.view')->name('dns-cluster.index');
    Route::post('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dns-cluster.store');
    Route::post('/dns-cluster/sync', [\App\Http\Controllers\DnsClusterController::class, 'sync'])
        ->middleware('perm:dns.manage')->name('dns-cluster.sync');
    Route::delete('/dns-cluster/{dnsClusterNode}', [\App\Http\Controllers\DnsClusterController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dns-cluster.destroy');
});
// ---- /DNS Cluster ----
