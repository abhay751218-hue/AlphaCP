#!/usr/bin/env bash
# AlphaCP — G5 Git Version Control + Terminal portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP G5 (Git + Terminal) installer  v1.0"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/resources/views/git" "$PANEL/resources/views/terminal"
cat > "$PANEL/app/Http/Controllers/GitController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

/**
 * cPanel "Git Version Control" — repos list/clone/pull/status.
 * Sab git ops Process facade se (sandbox-testable via Process::fake).
 * Security: sirf config('acp.git_base') ke andar ke dirs (realpath guard).
 */
final class GitController extends Controller
{
    private function base(): string
    {
        return rtrim((string) (config('acp.git_base') ?: storage_path('app/git')), '/');
    }

    public function index(): View
    {
        $base  = $this->base();
        $repos = [];

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $d) {
                if ($d === '.' || $d === '..') {
                    continue;
                }
                if (is_dir($base . '/' . $d . '/.git')) {
                    $repos[] = $d;
                }
            }
        }

        return view('git.index', ['repos' => $repos]);
    }

    public function clone(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => 'required|url',
            'dir' => 'required|string|regex:/^[a-z0-9._-]+$/i',
        ]);

        $target = $this->base() . '/' . $data['dir'];

        Process::timeout(120)->run('git clone -- ' . escapeshellarg($data['url']) . ' ' . escapeshellarg($target));

        return redirect('/git');
    }

    public function pull(string $dir): RedirectResponse
    {
        $path = $this->resolve($dir);
        if ($path === null) {
            return redirect('/git');
        }

        Process::timeout(120)->run('git -C ' . escapeshellarg($path) . ' pull --ff-only');

        return redirect('/git');
    }

    public function status(string $dir): View|RedirectResponse
    {
        $path = $this->resolve($dir);
        if ($path === null) {
            return redirect('/git');
        }

        $result = Process::timeout(30)->run(['git', '-C', $path, 'status', '--porcelain']);

        return view('git.status', ['dir' => $dir, 'output' => $result->output()]);
    }

    private function resolve(string $dir): ?string
    {
        $base = realpath($this->base());
        if ($base === false) {
            return null;
        }

        $path = realpath($base . '/' . $dir);
        if ($path === false || ! str_starts_with($path, $base . '/')) {
            return null;
        }

        return $path;
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/GitController.php"
cat > "$PANEL/app/Http/Controllers/TerminalController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

/**
 * cPanel "Terminal" (simplified, non-interactive) — whitelisted commands only.
 * Chaining/redirects (`;|&\`$><`) blocked. Process facade se (testable via fake).
 */
final class TerminalController extends Controller
{
    private const ALLOWED = [
        'ls', 'pwd', 'whoami', 'date', 'uname', 'df', 'free', 'uptime',
        'git status', 'php -v', 'node -v', 'cat ',
    ];

    public function index(): View
    {
        return view('terminal.index', [
            'output' => session('term_output'),
            'cmd'    => session('term_cmd'),
            'error'  => session('term_error'),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $cmd = trim((string) $request->input('command', ''));

        if ($cmd === '' || ! $this->safe($cmd)) {
            return redirect('/terminal')->with('term_error', 'Ye command allowed nahi hai (sirf read-only whitelist).');
        }

        $result = Process::timeout(30)->run($cmd);

        return redirect('/terminal')->with(['term_cmd' => $cmd, 'term_output' => $result->output()]);
    }

    private function safe(string $cmd): bool
    {
        if (preg_match('/[;&|`$><\\\\]/', $cmd)) {
            return false;
        }

        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($cmd, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/TerminalController.php"
cat > "$PANEL/resources/views/git/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Git Version Control')
@section('subtitle', 'AlphaCP Git — repos clone/pull/status')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
<div class="card">
    <h3>Repository clone karo</h3>
    <form method="POST" action="{{ route('git.clone') }}">
        @csrf
        <label>Git URL
            <input type="url" name="url" placeholder="https://github.com/user/repo.git" required>
        </label>
        <label>Folder name
            <input type="text" name="dir" placeholder="myrepo" pattern="[A-Za-z0-9._-]+" required>
        </label>
        <button class="btn" type="submit">Clone</button>
    </form>
</div>

<div class="card">
    <h3>Repositories ({{ count($repos) }})</h3>
    @if (empty($repos))
        <p class="muted">Koi git repository nahi.</p>
    @else
        <table>
            <tr><th>Repo</th><th></th><th></th></tr>
            @foreach ($repos as $r)
            <tr>
                <td>{{ $r }}</td>
                <td><a class="btn small" href="{{ route('git.status', $r) }}">Status</a></td>
                <td>
                    <form method="POST" action="{{ route('git.pull', $r) }}">
                        @csrf
                        <button class="btn small secondary" type="submit">Pull</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/git/index.blade.php"
cat > "$PANEL/resources/views/git/status.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Git Status')
@section('subtitle', 'Repo: ' . $dir)

@section('actions')
    <a class="btn small secondary" href="{{ route('git.index') }}">← Git</a>
@endsection

@section('content')
<div class="card">
    <h3>git status --porcelain</h3>
    <pre style="white-space:pre-wrap;background:#111;padding:12px;border-radius:6px">{{ $output ?: '(clean — koi change nahi)' }}</pre>
</div>
@endsection
ACP_FILE_EOF
echo "  + resources/views/git/status.blade.php"
cat > "$PANEL/resources/views/terminal/index.blade.php" <<'ACP_FILE_EOF'
@extends('layouts.panel')

@section('title', 'Terminal')
@section('subtitle', 'AlphaCP Terminal (read-only whitelist — non-interactive)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($error)
<div class="card" style="border:2px solid #a22"><p>{{ $error }}</p></div>
@endif

<div class="card">
    <h3>Command chalao</h3>
    <form method="POST" action="{{ route('terminal.run') }}">
        @csrf
        <label>Command
            <input type="text" name="command" placeholder="ls -la" value="{{ $cmd }}" required>
        </label>
        <button class="btn" type="submit">Run</button>
    </form>
    <p class="muted">Allowed: ls, pwd, whoami, date, uname, df, free, uptime, git status, php -v, node -v, cat …</p>
</div>

@if ($cmd)
<div class="card">
    <h3>$ {{ $cmd }}</h3>
    <pre style="white-space:pre-wrap;background:#111;padding:12px;border-radius:6px">{{ $output }}</pre>
</div>
@endif
@endsection
ACP_FILE_EOF
echo "  + resources/views/terminal/index.blade.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "G5: Git Version Control + Terminal" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'

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
echo " ==> G5 (GIT + TERMINAL) v1.0 INSTALLED  (panel: /git, /terminal)"
echo "=================================================="
alphacp-sync || true
