#!/usr/bin/env bash
# AlphaCP — File extras (Images + Optimize Website + Trash) installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP File extras installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/optimize" "$PANEL/resources/views/images" "$PANEL/resources/views/trash"
cat > "$PANEL/app/Models/OptimizeSetting.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** cPanel "Optimize Website" — compression setting per user. */
final class OptimizeSetting extends Model
{
    protected $table = 'optimize_settings';

    protected $fillable = ['user_id', 'level'];
}
ACP_FILE_EOF
echo "  + app/Models/OptimizeSetting.php"
cat > "$PANEL/app/Http/Controllers/OptimizeController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OptimizeSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel "Optimize Website" — content compression setting. */
final class OptimizeController extends Controller
{
    public function index(Request $request): View
    {
        $rec = OptimizeSetting::query()->where('user_id', $request->user()->id)->first();

        return view('optimize.index', ['level' => $rec?->level ?? 'disabled']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['level' => 'required|in:disabled,all,html']);

        OptimizeSetting::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['level' => $data['level']],
        );

        return redirect('/optimize-website');
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/OptimizeController.php"
cat > "$PANEL/app/Http/Controllers/ImagesController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/** cPanel "Images" — account ke image files ki list (thumbnail server-side nahi). */
final class ImagesController extends Controller
{
    private const EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    public function index(): View
    {
        $base = rtrim((string) (config('acp.images_base') ?: storage_path('app/images')), '/');
        $images = [];

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, self::EXT, true)) {
                    $images[] = $f;
                }
            }
        }

        return view('images.index', ['images' => $images]);
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/ImagesController.php"
cat > "$PANEL/app/Http/Controllers/TrashController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel "Trash" — deleted files ki list + permanent delete (realpath-guarded). */
final class TrashController extends Controller
{
    private function base(): string
    {
        return rtrim((string) (config('acp.trash_base') ?: storage_path('app/trash')), '/');
    }

    public function index(): View
    {
        $base  = $this->base();
        $files = [];

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    $files[] = $f;
                }
            }
        }

        return view('trash.index', ['files' => $files]);
    }

    public function destroy(Request $request, string $file): RedirectResponse
    {
        $base = realpath($this->base());
        $path = $base !== false ? realpath($base . '/' . $file) : false;

        if ($path !== false && str_starts_with($path, $base . '/') && is_file($path)) {
            unlink($path);
        }

        return redirect('/trash');
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/TrashController.php"
cat > "$PANEL/resources/views/optimize/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Optimize Website')
@section('subtitle', 'Content compression — site ko fast banao')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Compression setting</h3>
    <form method="POST" action="{{ route('optimize.store') }}">
        @csrf
        <label>Mode
            <select name="level">
                <option value="disabled" @selected($level === 'disabled')>No compression</option>
                <option value="all" @selected($level === 'all')>Compress all content</option>
                <option value="html" @selected($level === 'html')>Compress HTML only</option>
            </select>
        </label>
        <button class="btn" type="submit">Save</button>
    </form>
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/optimize/index.blade.php"
cat > "$PANEL/resources/views/images/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Images')
@section('subtitle', 'Account ki image files')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Images ({{ count($images) }})</h3>
    @if (empty($images))
        <p class="muted">Koi image file nahi.</p>
    @else
        <table>
            <tr><th>File</th></tr>
            @foreach ($images as $img)
            <tr><td>{{ $img }}</td></tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/images/index.blade.php"
cat > "$PANEL/resources/views/trash/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Trash')
@section('subtitle', 'Deleted files — permanent delete karo')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Trash ({{ count($files) }})</h3>
    @if (empty($files))
        <p class="muted">Trash khaali hai.</p>
    @else
        <table>
            <tr><th>File</th><th></th></tr>
            @foreach ($files as $f)
            <tr>
                <td>{{ $f }}</td>
                <td>
                    <form method="POST" action="{{ route('trash.destroy', $f) }}" onsubmit="return confirm('Permanent delete?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/trash/index.blade.php"
cat > "$PANEL/database/migrations/2026_10_07_000008_create_optimize_settings_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optimize_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('level', 12)->default('disabled'); // disabled|all|html
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optimize_settings');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000008_create_optimize_settings_table.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "File extras: Images + Optimize Website + Trash" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

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
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: migrate + cache clear =="
cd "$PANEL"
php artisan migrate --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated"

echo "=================================================="
echo " ==> FILE EXTRAS v1.0 INSTALLED  (/images /optimize-website /trash)"
echo "=================================================="
alphacp-sync || true
