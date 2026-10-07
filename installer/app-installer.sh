#!/usr/bin/env bash
# AlphaCP — Site Software / App Installer (WordPress one-click) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP App Installer  v1.0 (WordPress one-click)"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/apps"
cat > "$PANEL/app/Support/AppInstaller.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * cPanel "Site Software" — one-click app installs (WordPress first).
 * System ops (mysql/curl/tar/chown) via Process; wp-config via File.
 * Tests fake Process and point account home at a temp dir.
 */
final class AppInstaller
{
    /** @return array<int, array{id:string,name:string,desc:string}> */
    public static function catalog(): array
    {
        return [
            ['id' => 'wordpress', 'name' => 'WordPress', 'desc' => 'Blog / CMS / WooCommerce'],
            ['id' => 'joomla',    'name' => 'Joomla',    'desc' => 'CMS (jald aa raha hai)'],
            ['id' => 'drupal',    'name' => 'Drupal',    'desc' => 'CMS (jald aa raha hai)'],
        ];
    }

    /** @return array<string, mixed> */
    public static function installWordPress(Account $account, string $dbPassword): array
    {
        $home = rtrim($account->home_path, '/') . '/public_html';
        $db   = strtolower($account->username) . '_wp';

        File::makeDirectory($home, 0755, true, true);

        Process::run(sprintf(
            "mysql -e \"CREATE DATABASE IF NOT EXISTS %s; CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s'; GRANT ALL ON %s.* TO '%s'@'localhost'; FLUSH PRIVILEGES;\"",
            $db, $db, $dbPassword, $db, $db
        ));

        Process::run('curl -sL -o /tmp/wordpress.tar.gz https://wordpress.org/latest.tar.gz');
        Process::run('tar -xzf /tmp/wordpress.tar.gz -C ' . escapeshellarg($home) . ' --strip-components=1');

        File::put($home . '/wp-config.php', self::wpConfig($db, $db, $dbPassword));

        Process::run('chown -R ' . $account->username . ':' . $account->username . ' ' . escapeshellarg($home));

        return ['ok' => true, 'app' => 'wordpress', 'db' => $db, 'home' => $home];
    }

    private static function wpConfig(string $db, string $user, string $pass): string
    {
        return "<?php\n"
            . "define( 'DB_NAME', '" . $db . "' );\n"
            . "define( 'DB_USER', '" . $user . "' );\n"
            . "define( 'DB_PASSWORD', '" . $pass . "' );\n"
            . "define( 'DB_HOST', 'localhost' );\n"
            . "define( 'WP_DEBUG', false );\n";
    }
}
ACP_FILE_EOF
echo "  + app/Support/AppInstaller.php"
cat > "$PANEL/app/Http/Controllers/AppsController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AppInstaller;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** cPanel Site Software — app catalog + WordPress one-click install. */
final class AppsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('apps.index', [
            'account'   => $account,
            'catalog'   => AppInstaller::catalog(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate(['app' => ['required', 'string', 'in:wordpress']]);

        if ($data['app'] === 'wordpress') {
            $result = AppInstaller::installWordPress($account, Str::random(24));
            Audit::log('apps.install', 'info', 'account', $account->id, ['app' => 'wordpress', 'db' => $result['db']]);

            return redirect()->route('apps.index')
                ->with('success', 'WordPress install ho gaya (DB: ' . $result['db'] . ').');
        }

        return back()->withErrors(['app' => 'Ye app abhi available nahi.']);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/AppsController.php"
cat > "$PANEL/resources/views/apps/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Site Software')
@section('subtitle', 'One-click app installs — cPanel Site Software / WordPress jaisa')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="cards">
    @foreach ($catalog as $app)
    <div class="card">
        <h3>{{ $app['name'] }}</h3>
        <p class="muted">{{ $app['desc'] }}</p>
        @if ($app['id'] === 'wordpress')
            <form method="POST" action="{{ route('apps.store') }}">
                @csrf
                <input type="hidden" name="app" value="wordpress">
                <button class="btn" type="submit">Install WordPress</button>
            </form>
        @else
            <button class="btn secondary" disabled>Jald</button>
        @endif
    </div>
    @endforeach
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/apps/index.blade.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "Site Software / App Installer" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- Site Software / App Installer (WordPress one-click) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/apps', [\App\Http\Controllers\AppsController::class, 'index'])
        ->middleware('perm:software.view')->name('apps.index');
    Route::post('/apps', [\App\Http\Controllers\AppsController::class, 'store'])
        ->middleware('perm:software.manage')->name('apps.store');
});
// ---- /Site Software ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: cache clear =="
cd "$PANEL"
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] caches cleared"

echo "=================================================="
echo " ==> APP INSTALLER v1.0 INSTALLED  (panel: /apps)"
echo "=================================================="
alphacp-sync || true
