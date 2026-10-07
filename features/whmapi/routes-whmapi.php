
// ---- WHM API 1 compatible (billing integration, Bearer token) ----
Route::prefix('json-api')->middleware([\App\Http\Middleware\EnsureApiToken::class])->group(function (): void {
    Route::get('/listaccts', [\App\Http\Controllers\WhmApiController::class, 'listaccts']);
    Route::get('/accountsummary', [\App\Http\Controllers\WhmApiController::class, 'accountsummary']);
    Route::post('/createacct', [\App\Http\Controllers\WhmApiController::class, 'createacct']);
    Route::get('/suspendacct', [\App\Http\Controllers\WhmApiController::class, 'suspendacct']);
    Route::get('/unsuspendacct', [\App\Http\Controllers\WhmApiController::class, 'unsuspendacct']);
    Route::get('/removeacct', [\App\Http\Controllers\WhmApiController::class, 'removeacct']);
});
// ---- /WHM API ----
