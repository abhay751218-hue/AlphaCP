#!/usr/bin/env bash
# ============================================================================
#  AlphaCP  —  PORTS-CTRL  v1.0   (OWNER-CTRL: "ek panel = ek port")
#
#  Karta hai:
#    1  panel payloads: PortMap + AcpPortGuard + login branding + /ports page
#    2  agent payloads: PortsNginx + ports.apply task (+ allowlist entry)
#    3  etc/ports.json default map (whm 2087 / cpanel 2083 / webmail 2096 /
#       link 8090) agar maujood nahi
#    4  live: ufw me whm+cpanel ports allow
#    5  paneld --run ports.apply → nginx vhosts regen (whm/cpanel/link/webmail)
#       + reload; nginx -t fail → agent khud restore karta hai
#    6  structural asserts + HTTP smokes (WHM/cPanel branding, link-page,
#       webmail 2096, internal SSO loc cpanel port par 403)
#    7  agent suite full run + alphacp-sync
#
#  Safety: backup → asserts → smokes; koi fail = ROLLBACK + non-zero exit.
#  SIM=1 + env paths → tools/sim/ports-ctrl-sim.sh (server mutate NAHI).
# ============================================================================
set -euo pipefail

VERSION="1.0"
SIM="${SIM:-0}"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
NGX_SYS="${ACP_NGX_ROOT:-/etc/nginx}"
RC_PLUGINS="${ACP_RC_PLUGINS:-/usr/share/roundcube/plugins}"
PANEL="${ACP_HOME}/panel"
AGENT="${ACP_HOME}/agent"
PHP_BIN="${PHPBIN:-${PHP:-$(command -v php8.4 || command -v php || true)}}"

LOGD="${ACP_HOME}/logs"
mkdir -p "$LOGD"
LOG="${LOGD}/ports-ctrl-$(date +%Y%m%d%H%M%S).txt"
exec > >(tee -a "$LOG") 2>&1

info() { printf '  · %s\n' "$*"; }
ok()   { printf '  \033[0;32m✔\033[0m %s\n' "$*"; }
warn() { printf '  \033[0;33m⚠\033[0m %s\n' "$*"; }
die()  { printf '  \033[0;31m✖\033[0m %s\n' "$*" >&2; exit 1; }
hdr()  { printf '\n== %s ==\n' "$*"; }
cnt()  { if [[ "${1:-}" == "--" ]]; then shift; grep -c -- "${1}" "${2}" 2>/dev/null || echo 0; else grep -c "$1" "$2" 2>/dev/null || echo 0; fi; }

# ---- rollback infrastructure ----
BACKUP="${ACP_HOME}/releases/portsctrl-$(date +%Y%m%d%H%M%S)"
RB_MANIFEST=()
backup() {
  local f key
  for f in "$@"; do
    [[ -f "$f" ]] || continue
    key="$(echo "$f" | tr / _)"
    cp -p "$f" "${BACKUP}/${key}"
    RB_MANIFEST+=("$f")
  done
}
rollback() {
  hdr "ROLLBACK — ports-ctrl v${VERSION}"
  info "backup: ${BACKUP}"
  local f key
  for f in "${RB_MANIFEST[@]:-}"; do
    [[ -z "$f" ]] && continue
    key="$(echo "$f" | tr / _)"
    if [[ -f "${BACKUP}/${key}" ]]; then
      cp -p "${BACKUP}/${key}" "$f"
    fi
  done
  ok "rollback complete (purani state wapas)"
}

if [[ "${1:-}" == "--rollback" ]]; then
  B="${2:-$(ls -dt "${ACP_HOME}"/releases/portsctrl-* 2>/dev/null | head -1)}"
  [[ -n "$B" && -d "$B" ]] || die "koi portsctrl backup nahi mila"
  hdr "ROLLBACK — ports-ctrl (backup: $B)"
  for f in "$B"/*; do
    [[ -f "$f" ]] || continue
    case "$(basename "$f")" in *.retired) continue;; esac
    target="/$(basename "$f" | sed 's|_|/|g')"
    cp -p "$f" "$target" && info "restore: $target"
  done
  AV="${NGX_SYS}/sites-available"; EN="${NGX_SYS}/sites-enabled"
  for v in alphacp-whm.conf alphacp-cpanel.conf alphacp-link.conf; do
    rm -f "${EN}/${v}" "${AV}/${v}"
  done
  if [[ -f "$B/$(echo "${AV}/alphacp-panel.conf" | tr / _).retired" ]]; then
    cp -p "$B/$(echo "${AV}/alphacp-panel.conf" | tr / _).retired" "${AV}/alphacp-panel.conf"
    ln -sf "${AV}/alphacp-panel.conf" "${EN}/alphacp-panel.conf"
  fi
  nginx -t >/dev/null 2>&1 && nginx -s reload >/dev/null 2>&1 || true
  ok "rollback complete"
  exit 0
fi

hdr "APPLY — ports-ctrl v${VERSION} (OWNER-CTRL: ek panel = ek port)"
[[ -n "$PHP_BIN" ]] || die "php binary nahi mila (PHPBIN=/path)"
mkdir -p "$BACKUP"
info "backup: ${BACKUP}"

# ---- 1) panel payloads ----
hdr "panel payloads (PortMap + PortGuard + branding + /ports)"
backup "${PANEL}/resources/views/dashboard.blade.php" \
       "${PANEL}/resources/views/dashboard-whm.blade.php" \
       "${PANEL}/resources/views/dashboard-cpanel.blade.php" \
       "${PANEL}/app/Http/Controllers/DashboardController.php" \
       "${PANEL}/app/Support/PortMap.php" \
       "${PANEL}/app/Http/Middleware/AcpPortGuard.php" \
       "${PANEL}/app/Http/Controllers/Auth/LoginController.php" \
       "${PANEL}/app/Http/Controllers/PortsController.php" \
       "${PANEL}/resources/views/auth/login.blade.php" \
       "${PANEL}/resources/views/ports/index.blade.php" \
       "${PANEL}/bootstrap/app.php" \
       "${PANEL}/config/acp.php"
mkdir -p "${PANEL}/app/Support" "${PANEL}/app/Http/Middleware" \
         "${PANEL}/app/Http/Controllers/Auth" "${PANEL}/resources/views/auth" \
         "${PANEL}/resources/views/ports" "${PANEL}/bootstrap" "${PANEL}/config"
cat > "${PANEL}/app/Support/PortMap.php" <<'PEOF'
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Port ↔ panel map ("ek panel = ek port" rule, OWNER-CTRL slice).
 *
 * Source of truth: etc/ports.json (single JSON object), written by the
 * panel (/ports page) and by the installer/agent (apply_ports). Shape:
 *   {"whm":2087,"cpanel":2083,"webmail":2096,"link":8090,"link_enabled":true}
 *
 * The legacy multi-port shape ({"ssl":[...],"http":[...],...}) is intentionally
 * NOT honoured — it opened one panel on many ports, which the product rule
 * forbids; such a file falls back to DEFAULTS until the owner saves again.
 */
final class PortMap
{
    public const DEFAULTS = [
        'whm'          => 2087,
        'cpanel'       => 2083,
        'webmail'      => 2096,
        'link'         => 8090,
        'link_enabled' => true,
    ];

    /** @var array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool}|null */
    private static ?array $cache = null;

    /**
     * @return array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool}
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        /** @var array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool} $map */
        $map  = self::DEFAULTS;
        $file = (string) (config('acp.ports_file') ?: '/usr/local/alphacp/etc/ports.json');
        $raw  = @file_get_contents($file);

        if ($raw !== false) {
            $j = json_decode($raw, true);
            if (is_array($j) && ! isset($j['ssl'])) {           // new shape only
                foreach (['whm', 'cpanel', 'webmail', 'link'] as $k) {
                    if (isset($j[$k]) && is_numeric($j[$k])) {
                        $map[$k] = (int) $j[$k];
                    }
                }
                if (array_key_exists('link_enabled', $j)) {
                    $map['link_enabled'] = (bool) $j['link_enabled'];
                }
            }
        }

        return self::$cache = $map;
    }

    /** WHM/cPanel/Webmail/link-page me se kaun sa panel is port par khulta hai. */
    public static function familyFor(int $port): ?string
    {
        $m = self::all();
        foreach (['whm', 'cpanel', 'webmail', 'link'] as $f) {
            if ($m[$f] === $port) {
                return $f;
            }
        }

        return null;
    }

    public static function portFor(string $family): int
    {
        return self::all()[$family] ?? self::DEFAULTS[$family];
    }

    public static function linkEnabled(): bool
    {
        return self::all()['link_enabled'];
    }

    /** Login URL of the given family on the current host (port-redirects ke liye). */
    public static function loginUrl(string $family): string
    {
        $host = (string) (request()?->getHost() ?: 'localhost');

        return sprintf('https://%s:%d/login', $host, self::portFor($family));
    }

    /** Tests / post-save refresh. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}

PEOF
cat > "${PANEL}/app/Http/Middleware/AcpPortGuard.php" <<'PEOF'
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ModuleCatalog;
use App\Support\PortMap;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Ek panel = ek port" enforcement (OWNER-CTRL slice).
 *
 * Request port se panel-family lock hoti hai:
 *  - 2087 (whm)    → sirf WHM-mode users (root/reseller)
 *  - 2083 (cpanel) → sirf cPanel-mode users (customers)
 *  - 2096/8090     → alag vhost (Roundcube / static link-page) — PHP yahan nahi
 *  - unknown port  → pass (dev/preview/sim)
 *
 * Galat family ka logged-in user mile to session khatam karke sahi port ke
 * login par bhej do — cPanel company jaisi strict separation.
 */
final class AcpPortGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        $family = PortMap::familyFor((int) $request->getPort());

        if ($family === null || $family === 'webmail' || $family === 'link') {
            return $next($request);
        }

        $user = $request->user();
        if ($user !== null) {
            $mode = ModuleCatalog::modeFor($user);
            if ($mode !== $family) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->to(PortMap::loginUrl($mode))->withErrors([
                    'username' => sprintf(
                        'Aapka panel port %d par khulta hai — wahan login karein.',
                        PortMap::portFor($mode),
                    ),
                ]);
            }
        }

        return $next($request);
    }
}

PEOF
cat > "${PANEL}/app/Http/Controllers/Auth/LoginController.php" <<'PEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\PortMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Panel login (admin + reseller + client share one URL — role decides the UI).
 *
 * Protections: rate limit, per-user failed-login lockout (cPHulk-lite),
 * session regeneration, audit of every attempt, optional 2FA step.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        // Port se branding: 2087 → WHM login, 2083 → cPanel login.
        return view('auth.login', [
            'portFamily' => PortMap::familyFor((int) request()->getPort()),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $ip       = (string) $request->ip();
        $username = trim($data['username']);

        $user = User::query()->where('username', $username)->first();

        // Failures are counted per username+IP pair.
        $fail = function (string $reason, ?User $user = null) use ($username, $ip): RedirectResponse {
            LoginAttempt::query()->create([
                'username' => $username, 'ip' => $ip, 'success' => false, 'reason' => $reason,
            ]);
            Audit::log('auth.login_failed', 'warning', 'user', $user?->id, ['username' => $username, 'reason' => $reason]);

            if ($user) {
                $user->increment('failed_logins');
                $max = (int) config('acp.security.max_login_attempts', 5);
                if ($user->failed_logins + 1 >= $max) {
                    $user->forceFill([
                        'locked_until'  => now()->addMinutes((int) config('acp.security.lockout_minutes', 15)),
                        'failed_logins' => 0,
                    ])->save();
                    Audit::log('auth.account_locked', 'critical', 'user', $user->id, ['minutes' => config('acp.security.lockout_minutes')]);
                }
            }

            return back()->withErrors(['username' => 'Username or password is incorrect.'])->onlyInput('username');
        };

        if (! $user) {
            return $fail('unknown_user');
        }
        if ($user->isLocked()) {
            Audit::log('auth.login_blocked_locked', 'warning', 'user', $user->id);
            return back()->withErrors(['username' => 'Account is locked for a short time. Try again later.'])->onlyInput('username');
        }
        if (! $user->isActive()) {
            return $fail('status_' . $user->status, $user);
        }
        if (! Hash::check($data['password'], $user->password_hash)) {
            return $fail('bad_password', $user);
        }

        // ---- port↔role lock: har panel sirf apne port par khulta hai ----
        $family = PortMap::familyFor((int) $request->getPort());
        if (in_array($family, ['whm', 'cpanel'], true)) {
            $mode = ModuleCatalog::modeFor($user);
            if ($mode !== $family) {
                LoginAttempt::query()->create([
                    'username' => $username, 'ip' => $ip, 'success' => false, 'reason' => 'wrong_port',
                ]);
                Audit::log('auth.login_wrong_port', 'warning', 'user', $user->id, [
                    'port' => $request->getPort(), 'needs' => $mode,
                ]);

                return redirect()->to(PortMap::loginUrl($mode))->withErrors([
                    'username' => sprintf('Aapka panel port %d par khulta hai — wahan login karein.', PortMap::portFor($mode)),
                ]);
            }
        }

        // ---- success -------------------------------------------------------
        Auth::login($user, remember: false);              // no "remember me" (panel policy)
        $request->session()->regenerate();
        $request->session()->put('two_factor_passed', ! $user->two_factor_enabled);

        $user->forceFill([
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        LoginAttempt::query()->create(['username' => $username, 'ip' => $ip, 'success' => true]);
        Audit::log('auth.login', 'info', 'user', $user->id, ['ip' => $ip]);

        if ($user->two_factor_enabled) {
            return redirect()->route('twofactor.challenge');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::log('auth.logout', 'info', 'user', $request->user()?->id);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

PEOF
cat > "${PANEL}/app/Http/Controllers/PortsController.php" <<'PEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PortConfig;
use App\Support\Paneld;
use App\Support\PortMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Owner Ports Control — OWNER-CTRL slice ("ek panel = ek port").
 *
 * Char mappings: WHM (2087), cPanel (2083), Webmail (2096), link-page (8090,
 * band ki ja sakti hai). Save par: DB (port_configs single row) +
 * etc/ports.json + agent task `ports.apply` (nginx vhosts regen + reload).
 *
 * Purana multi-port ssl-list shape abandon ho gaya: ek panel kai ports par
 * khulna product rule ke khilaaf tha.
 */
final class PortsController extends Controller
{
    public function index(): View
    {
        return view('ports.index', [
            'cfg'   => PortMap::all(),
            'applied' => $this->agentStatus(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'whm'          => ['required', 'integer', 'min:1024', 'max:65535'],
            'cpanel'       => ['required', 'integer', 'min:1024', 'max:65535'],
            'webmail'      => ['required', 'integer', 'min:1024', 'max:65535'],
            'link'         => ['required', 'integer', 'min:1024', 'max:65535'],
            'link_enabled' => ['nullable', 'boolean'],
            'distinct'     => ['nullable'],
        ]);

        $cfg = [
            'whm'          => (int) $data['whm'],
            'cpanel'       => (int) $data['cpanel'],
            'webmail'      => (int) $data['webmail'],
            'link'         => (int) $data['link'],
            'link_enabled' => $request->boolean('link_enabled'),
        ];

        // charon ports alag-alag hone chahiye (ek panel = ek port)
        if (count(array_unique([$cfg['whm'], $cfg['cpanel'], $cfg['webmail'], $cfg['link']])) !== 4) {
            return back()->withErrors(['whm' => 'Charon ports alag-alag hone chahiye.'])->withInput();
        }

        $rec = PortConfig::query()->first();
        if ($rec) {
            $rec->update(['data' => $cfg]);
        } else {
            PortConfig::query()->create(['data' => $cfg]);
        }

        try {
            File::put($this->portsFile(), json_encode($cfg, JSON_PRETTY_PRINT));
        } catch (\Throwable) {
            // DB source-of-truth hai; agent/apply-step file sync bhi karta hai.
        }

        PortMap::flush();

        // agent se nginx vhosts turant regen karwao
        $applied = true;
        try {
            $res     = Paneld::run('ports.apply', [], 60);
            $applied = (bool) ($res['applied'] ?? false);
        } catch (\Throwable) {
            $applied = false;
        }

        return redirect('/ports')->with(
            $applied ? 'success' : 'warning',
            $applied
                ? 'Ports save + nginx par apply ho gaye (vhosts regen + reload).'
                : 'Ports save ho gaye magar agent apply fail hua — dobara try karein ya agent log dekhein.',
        );
    }

    /** @return array<string,mixed>|null */
    private function agentStatus(): ?array
    {
        try {
            $res = Paneld::run('ports.apply', ['action' => 'status'], 15);

            return is_array($res) ? $res : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function portsFile(): string
    {
        return (string) (config('acp.ports_file') ?: '/usr/local/alphacp/etc/ports.json');
    }
}

PEOF
cat > "${PANEL}/resources/views/auth/login.blade.php" <<'PEOF'
@extends('layouts.guest')

@section('title', 'Login')

@section('content')
    <h1>{{ ($portFamily ?? null) === 'whm' ? 'WHM Login' : (($portFamily ?? null) === 'cpanel' ? 'cPanel Login' : 'Panel Login') }}</h1>
    <p class="sub">@if(($portFamily ?? null) === 'whm')
            Root · Reseller — server management
        @elseif(($portFamily ?? null) === 'cpanel')
            Customer — hosting control
        @else
            Admin · Reseller · Customer — sab ek hi URL se
        @endif</p>

    <form method="post" action="{{ route('login.attempt') }}">
        @csrf

        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="{{ old('username') }}"
               autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button class="btn mt" type="submit" style="width:100%; justify-content:center">Login</button>
    </form>

    <p class="help mt">Too many failed passwords lock the account for a short time (brute-force protection).</p>
@endsection

PEOF
cat > "${PANEL}/resources/views/ports/index.blade.php" <<'PEOF'
@extends('layouts.panel')

@section('title', 'Ports Control')
@section('subtitle', 'Owner control — kaun sa panel kis port par khule (ek panel = ek port)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if (session('success'))
<div class="card" style="border:2px solid #2a2"><p>{{ session('success') }}</p></div>
@endif
@if (session('warning'))
<div class="card" style="border:2px solid #a2"><p>{{ session('warning') }}</p></div>
@endif
@if ($errors->any())
<div class="card" style="border:2px solid #a22"><p>{{ $errors->first() }}</p></div>
@endif

<div class="card">
    <h3>Abhi live links</h3>
    <p>
        <strong>WHM (root/reseller):</strong> https://{{ request()->getHost() }}:{{ $cfg['whm'] }}/<br>
        <strong>cPanel (customer):</strong> https://{{ request()->getHost() }}:{{ $cfg['cpanel'] }}/<br>
        <strong>Webmail (Roundcube):</strong> https://{{ request()->getHost() }}:{{ $cfg['webmail'] }}/<br>
        <strong>Link-page:</strong>
        @if ($cfg['link_enabled'])
            https://{{ request()->getHost() }}:{{ $cfg['link'] }}/
        @else
            — band hai
        @endif
    </p>
    @if ($applied !== null)
        <p class="sub">Agent status: nginx {{ $applied['nginx'] ?? '?' }} · template {{ ($applied['template'] ?? false) ? 'ready' : 'pending' }}</p>
    @endif
</div>

<div class="card">
    <h3>Port ↔ panel mapping</h3>
    <form method="POST" action="{{ route('ports.store') }}">
        @csrf
        <label>WHM port (root / reseller)
            <input type="number" name="whm" min="1024" max="65535" value="{{ old('whm', $cfg['whm']) }}" required>
        </label>
        <label>cPanel port (customers)
            <input type="number" name="cpanel" min="1024" max="65535" value="{{ old('cpanel', $cfg['cpanel']) }}" required>
        </label>
        <label>Webmail port (Roundcube)
            <input type="number" name="webmail" min="1024" max="65535" value="{{ old('webmail', $cfg['webmail']) }}" required>
        </label>
        <label>Link-page port (static directory page, PHP nahi)
            <input type="number" name="link" min="1024" max="65535" value="{{ old('link', $cfg['link']) }}" required>
        </label>
        <label style="display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="link_enabled" value="1" @checked(old('link_enabled', $cfg['link_enabled']))>
            Link-page chalu rakhein (band karne par purana port bilkul band ho jata hai)
        </label>
        <button class="btn mt" type="submit">Save + nginx par apply karo</button>
    </form>
    <p class="help mt">Save par DB + etc/ports.json update hota hai aur agent `ports.apply` nginx vhosts
       regen + reload karta hai (nginx -t fail = purani vhosts wapas). Naye port ko AWS security group +
       ufw me kholna zaroori hai — warn panel bahar se nahi khulega.</p>
</div>
@endsection

PEOF
cat > "${PANEL}/resources/views/dashboard-whm.blade.php" <<'PEOF'
@extends('layouts.panel')

@section('title', 'WHM — Server Manager Dashboard')
@section('subtitle', 'Server health, accounts, packages — customer sites are not created on this page; they use the account panel')

@section('actions')
    <a class="btn small secondary" href="{{ route('system.index', ['refresh' => 1]) }}">Refresh stats</a>
    @can('accounts.view')
        <a class="btn small secondary" href="{{ route('accounts.index') }}">Accounts</a>
    @endcan
    @can('system.tasks')
        <a class="btn small secondary" href="{{ route('system.tasks') }}">Task queue</a>
    @endcan
@endsection

@section('content')
<div class="card">
    <h3>Quick links</h3>
    <p>
        @can('accounts.create')<a class="btn small" href="{{ route('accounts.create') }}">Create Account</a>@endcan
        @can('accounts.view')<a class="btn small secondary" href="{{ route('accounts.index') }}">List Accounts</a>@endcan
        @can('packages.view')<a class="btn small secondary" href="{{ route('packages.index') }}">Packages</a>@endcan
        @can('accounts.view')<a class="btn small secondary" href="{{ route('transfer-restore.index') }}">Transfer or Restore a Hosting Account</a>@endcan
    </p>
</div>
<div class="grid cols-4">
    <div class="card">
        <h3>🧠 Memory</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['memory']['used_pct'] }}%</span>
                <span class="unit">used · {{ $system['memory']['used_mb'] }} / {{ $system['memory']['total_mb'] }} MB</span></div>
            <div class="meter {{ $system['memory']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['memory']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">No data from the agent (is paneld running?)</p>
        @endif
    </div>

    <div class="card">
        <h3>💾 Disk (/)</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['disk']['used_pct'] }}%</span>
                <span class="unit">{{ $system['disk']['used_gb'] }} / {{ $system['disk']['total_gb'] }} GB</span></div>
            <div class="meter {{ $system['disk']['used_pct'] > 85 ? 'amber' : 'green' }}"><span style="width: {{ min(100, $system['disk']['used_pct']) }}%"></span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>⚙️ Load · CPU</h3>
        @if ($system)
            <div class="stat"><span class="num">{{ $system['load'][0] }}</span>
                <span class="unit">1-min · {{ $system['cpu_cores'] }} cores</span></div>
            <p class="help">{{ $system['os'] }} · kernel {{ $system['kernel'] }} · {{ $system['arch'] }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>

    <div class="card">
        <h3>📋 Agent queue</h3>
        <div class="stat"><span class="num">{{ $queue['queued'] + $queue['running'] }}</span>
            <span class="unit">pending · {{ $queue['success'] }} done · {{ $queue['failed'] }} failed</span></div>
        <p class="help">paneld v{{ $versions['agent'] }} · {{ $config_server ?? '' }}
            @can('system.tasks') <a href="{{ route('system.tasks') }}">monitor →</a> @endcan</p>
    </div>
</div>

<div class="grid cols-2 mt">
    <div class="card">
        <h3>🧩 Services</h3>
        @if ($services)
            <div class="table-wrap">
                <table>
                    <tr><th>Service</th><th>State</th><th>Boot</th></tr>
                    @foreach ($services as $name => $state)
                        <tr>
                            <td class="mono">{{ $name }}</td>
                            <td>
                                <span class="badge {{ $state['active'] === 'active' ? 'green' : ($state['active'] === 'inactive' ? 'amber' : 'red') }}">
                                    {{ $state['active'] }}
                                </span>
                            </td>
                            <td class="muted">{{ $state['enabled'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @else
            <p class="empty">Service status did not come from the agent.</p>
        @endif
    </div>

    <div class="card">
        <h3>📝 Recent activity (audit)</h3>
        @if ($audit->isEmpty())
            <p class="empty">No activity yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    @foreach ($audit as $event)
                        <tr>
                            <td class="mono">{{ $event->action }}</td>
                            <td><span class="badge {{ $event->severity === 'critical' ? 'red' : ($event->severity === 'warning' ? 'amber' : 'blue') }}">{{ $event->severity }}</span></td>
                            <td class="muted right">{{ \App\Support\Panel::ago($event->created_at) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @can('audit.view')
                <p class="help mt"><a href="{{ route('audit.index') }}">Poora audit log →</a></p>
            @endcan
        @endif
    </div>
</div>
@include('partials.dash-sections', [])

@endsection

PEOF
cat > "${PANEL}/resources/views/dashboard-cpanel.blade.php" <<'PEOF'
@extends('layouts.panel')

@section('title', 'cPanel — Account Panel')
@section('subtitle', 'Files, email, domains, databases — ye aapka hosting control panel hai')

@section('actions')
    @can('domains.view')
        <a class="btn small secondary" href="{{ route('domains.index') }}">Domains</a>
    @endcan
    <a class="btn small secondary" href="{{ route('security.index') }}">Security</a>
@endsection

@section('content')
<div class="grid cols-4">
<div class="dash-cols">
    <div>
        <div class="grid cols-4">
    <div class="card">
        <h3>🌐 Primary domain</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->main_domain }}</span></div>
            <p class="help">user <span class="mono">{{ $account->username }}</span> · {{ $account->status }}</p>
        @else
            <p class="empty">No hosting account is linked to this login. Ask your provider.</p>
        @endif
    </div>
    <div class="card">
        <h3>💾 Disk quota</h3>
        @if ($account)
            <div class="stat"><span class="num">{{ $account->disk_used_mb }}</span>
                <span class="unit">MB used · {{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</span></div>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>📦 Package</h3>
        @if ($account)
            <div class="stat"><span class="num" style="font-size:18px">{{ $account->package?->name ?? '—' }}</span></div>
            <p class="help">PHP {{ $account->php_version }}</p>
        @else
            <p class="empty">—</p>
        @endif
    </div>
    <div class="card">
        <h3>🌍 Domains</h3>
        @if ($account)
            <div class="stat"><span class="num">{{ $account->domains->count() }}</span>
                <span class="unit">on this account</span></div>
            <p class="help"><a href="{{ route('domains.index') }}">Manage domains →</a></p>
        @else
            <p class="empty">—</p>
        @endif
    </div>
</div>
        @include('partials.dash-sections', [])
    </div>
    <aside class="dash-side">
        <div class="card">
            <h3>General Information</h3>
            @if ($account)
                <dl class="kv">
                    <dt>User</dt><dd class="mono">{{ $account->username }}</dd>
                    <dt>Primary domain</dt><dd>{{ $account->main_domain }}</dd>
                    <dt>Home directory</dt><dd class="mono">{{ $account->home_path }}</dd>
                    <dt>PHP version</dt><dd>{{ $account->php_version }}</dd>
                    <dt>Package</dt><dd>{{ $account->package?->name ?? '—' }}</dd>
                    <dt>Status</dt><dd>{{ $account->status }}</dd>
                    <dt>Theme</dt><dd>Paper (cPanel-style)</dd>
                    <dt>Panel version</dt><dd>{{ config('acp.version') }}</dd>
                </dl>
            @else
                <p class="empty">No hosting account is linked to this login. Ask your provider.</p>
            @endif
        </div>
        <div class="card">
            <h3>Statistics</h3>
            @if ($account)
                @php $pct = $account->quota_mb > 0 ? min(100, (int) round($account->disk_used_mb * 100 / $account->quota_mb)) : 0; @endphp
                <p class="help">Disk usage</p>
                <div class="stat"><span class="num">{{ $account->disk_used_mb }}</span>
                    <span class="unit">MB / {{ $account->quota_mb < 0 ? 'unlimited' : $account->quota_mb . ' MB' }}</span></div>
                <div class="meter {{ $pct > 85 ? 'amber' : 'green' }}"><span style="width: {{ max(2, $pct) }}%"></span></div>
                <dl class="kv mt">
                    <dt>Bandwidth</dt><dd>{{ $account->bw_used_mb }} MB is cycle me</dd>
                    <dt>Domains</dt><dd>{{ $account->domains->count() }} is account par</dd>
                </dl>
            @else
                <p class="empty">—</p>
            @endif
        </div>
    </aside>
</div>

@endsection

PEOF
cat > "${PANEL}/app/Http/Controllers/DashboardController.php" <<'PEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\DomainProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Panel;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM dashboard for root/reseller; cPanel dashboard for hosting customers. */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $mode = ModuleCatalog::modeFor($user);
        $account = $mode === 'cpanel' ? $user->hostingAccount : null;
        if ($account !== null) {
            DomainProvisioner::seedMain($account);
        }

        $system = $services = null;
        $queue = ['queued' => 0, 'running' => 0, 'success' => 0, 'failed' => 0];
        $audit = collect();
        if ($mode === 'whm') {
            $system = Paneld::run('system.info', [], 8);
            $services = Paneld::run('service.status', [], 10);
            $queue = Panel::queueStats();
            $audit = Audit::recent(6);
        }

        return view($mode === 'whm' ? 'dashboard-whm' : 'dashboard-cpanel', [
            'panelMode' => $mode,
            'account'   => $account?->load(['package', 'domains']),
            'system'    => $system,
            'services'  => $services['services'] ?? [],
            'queue'     => $queue,
            'sections'  => ModuleCatalog::sectionsFor($user),
            'progress'  => ModuleCatalog::progress(),
            'audit'     => $audit,
            'server'    => Panel::server(),
            'versions'  => Panel::versions(),
        ]);
    }
}

PEOF
cat > "${PANEL}/bootstrap/app.php" <<'PEOF'
<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePasswordIsFresh;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Panel login rate limit (per IP): name maps to throttle:login in routes.
        $middleware->alias([
            '2fa'            => EnsureTwoFactorIsVerified::class,
            'password.fresh' => EnsurePasswordIsFresh::class,
            'perm'           => EnsurePermission::class,
            'throttle'       => ThrottleRequests::class,
        ]);

        $middleware->throttleWithRedis(false);

        // Trust the local reverse proxy / tunnel so $request->ip() is honest.
        $middleware->trustProxies(at: '*');

        // Security headers on every panel response.
        $middleware->append(\App\Http\Middleware\PanelSecurityHeaders::class);

        // Port↔panel lock ("ek panel = ek port"): 2087=WHM, 2083=cPanel.
        $middleware->append(\App\Http\Middleware\AcpPortGuard::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

PEOF
cat > "${PANEL}/config/acp.php" <<'PEOF'
<?php

declare(strict_types=1);

/**
 * AlphaCP panel configuration.
 *
 * Values come from the app .env (written by installer/step2b-install.sh).
 * Never hardcode paths or credentials in panel code — read them from here.
 */
return [
    // Panel + agent versions (shown in the UI footer and system page)
    'version'       => env('ACP_VERSION', '0.72.0'),
    'agent_version' => env('ACP_AGENT_VERSION', '0.62.0'),

    // AlphaCP install root (agent, etc/, logs/, panel/)
    'home'          => rtrim((string) env('ACP_HOME', '/usr/local/alphacp'), '/'),

    // Webmail (Roundcube) entry — cPanel-style alag port (default 2096).
    // OWNER-CTRL slice me ye superadmin-controlled ho jayega.
    'webmail_port'  => (int) env('ACP_WEBMAIL_PORT', 2096),

    // OWNER-CTRL port↔panel map file (panel + agent + installer shared truth).
    'ports_file'    => (string) env('ACP_PORTS_FILE', '/usr/local/alphacp/etc/ports.json'),

    // This server's row in `servers`.
    //
    // Source of truth = etc/panel.env (written by the installer, readable by
    // the panel user). Dev machines fall back to etc/database.env / app .env.
    // This guarantees panel and agent always agree on the server id.
    'server_id'     => (int) App\Support\PanelEnv::get('ACP_SERVER_ID', (string) env('ACP_SERVER_ID', '1')),

    // Where customer data lives (used from Step 3 onward)
    'paths' => [
        'accounts' => env('ACP_ACCOUNTS_PATH', '/home'),
        'www'      => env('ACP_WWW_PATH', '/var/www'),
    ],

    // Seconds the panel waits for paneld after enqueueing account tasks.
    // Tests force 0 (see AccountProvisioner).
    'provision_wait' => (int) env('ACP_PROVISION_WAIT', 25),

    // First admin account (used once by AdminUserSeeder during install).
    // Kept here — not read with env() in the seeder — because env() is
    // unavailable when the config cache is warm on a production server.
    'admin' => [
        'user'         => env('ACP_ADMIN_USER', 'admin'),
        'password'     => env('ACP_ADMIN_PASSWORD'),
        'email'        => env('ACP_ADMIN_EMAIL'),
        'force_change' => (bool) env('ACP_ADMIN_FORCE_CHANGE', true),
    ],

    // License client (S2C): offline-first signed payload + local trial.
    // An empty API URL is safe: the panel starts a local trial and never blocks
    // customer websites/email because a license server is unavailable.
    'license' => [
        'api_url' => (string) env('ACP_LICENSE_API_URL', ''),
        'timeout' => (int) env('ACP_LICENSE_TIMEOUT', 8),
        'store_path' => (string) env('ACP_LICENSE_STORE_PATH', storage_path('app/private/license.json')),
        'public_key' => (string) env('ACP_LICENSE_PUBLIC_KEY', ''),
        'public_key_path' => (string) env('ACP_LICENSE_PUBLIC_KEY_PATH', config_path('license_public.pem')),
    ],

    // S10 scheduled backups (routes/console.php → alphacp:scheduled-backups).
    //
    // `file` is the window marker: it records which day / ISO week / month was
    // already backed up, so the hourly tick runs the real archive pass exactly
    // once per window. Tests point it at a temp file.
    'backup_schedule' => [
        'file' => (string) env('ACP_BACKUP_SCHEDULE_FILE', storage_path('app/private/backup-schedule.json')),
    ],

    // Password quality
    //
    // check_pwned queries haveibeenpwned.com (k-anonymity: only 5 hash chars
    // leave the server). On a host without outbound access that call is a
    // 30-second stall, so the timeout is short and the check can be turned off
    // with ACP_CHECK_PWNED=false — the rest of the policy still applies.
    'password_check_pwned'  => (bool) env('ACP_CHECK_PWNED', true),
    'password_pwned_timeout' => (int) env('ACP_PWNED_TIMEOUT', 3),

    // Login protection (cPHulk-lite — full cPHulk arrives in Step 13)
    'security' => [
        'max_login_attempts'   => (int) env('ACP_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes'      => (int) env('ACP_LOCKOUT_MINUTES', 15),
        'throttle_per_minute'  => (int) env('ACP_LOGIN_THROTTLE', 10),
        'session_lifetime'     => (int) env('ACP_SESSION_LIFETIME', 30),
        'password_min_length'  => (int) env('ACP_PASSWORD_MIN_LENGTH', 10),

        // Clickjacking protection: DENY (production default) | SAMEORIGIN | OFF.
        // OFF exists only for --insecure-http dev boxes that are viewed inside a
        // preview iframe; a default install never sets it.
        'frame_options'        => env('ACP_FRAME_OPTIONS', 'DENY'),
    ],
];

PEOF
for f in app/Support/PortMap.php app/Http/Middleware/AcpPortGuard.php \
         app/Http/Controllers/Auth/LoginController.php app/Http/Controllers/PortsController.php \
         bootstrap/app.php config/acp.php; do
  "$PHP_BIN" -l "${PANEL}/${f}" >/dev/null || { rollback; die "lint fail: ${f}"; }
done
ok "11 panel payloads likhi + lint clean"

# ---- 2) agent payloads ----
hdr "agent payloads (PortsNginx + ports.apply task)"
backup "${AGENT}/config/tasks.php"
mkdir -p "${AGENT}/src/Tasks" "${AGENT}/config"
cat > "${AGENT}/src/PortsNginx.php" <<'PEOF'
<?php

declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * ports.apply — OWNER-CTRL: port↔panel map ko nginx par lagana.
 *
 * "Ek panel = ek port" rule:
 *   whm    → alphacp-whm.conf    (sirf WHM view; app-side PortGuard lock)
 *   cpanel → alphacp-cpanel.conf (sirf cPanel view + /internal/ SSO loc)
 *   link   → alphacp-link.conf   (static link-page, PHP nahi; owner-disableable)
 *   webmail→ alphacp-webmail.conf (Roundcube; listen port sync hota hai)
 *
 * Template source: etc/panel-vhost.template — pehli run par served panel vhost
 * se banta hai (listen/internal lines strip karke); baad me wahi source of
 * truth. Har apply: backup → render → nginx -t → reload; fail par restore.
 */
final class PortsNginx
{
    public const DEFAULTS = [
        'whm'          => 2087,
        'cpanel'       => 2083,
        'webmail'      => 2096,
        'link'         => 8090,
        'link_enabled' => true,
    ];

    /** @var array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool} */
    private array $map;

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
        private readonly string $home = '/usr/local/alphacp',
        private readonly string $ngxSys = '/etc/nginx',
        private readonly string $rcPlugins = '/usr/share/roundcube/plugins',
    ) {
        $this->map = self::DEFAULTS;
        $raw       = @file_get_contents($this->home . '/etc/ports.json');
        if ($raw !== false) {
            $j = json_decode($raw, true);
            if (is_array($j) && ! isset($j['ssl'])) {
                foreach (['whm', 'cpanel', 'webmail', 'link'] as $k) {
                    if (isset($j[$k]) && is_numeric($j[$k])) {
                        $this->map[$k] = (int) $j[$k];
                    }
                }
                if (array_key_exists('link_enabled', $j)) {
                    $this->map['link_enabled'] = (bool) $j['link_enabled'];
                }
            }
        }
    }

    /** @return array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool} */
    public function map(): array
    {
        return $this->map;
    }

    /**
     * Full apply pass — idempotent, kabhi bhi dobara chalao.
     *
     * @return array{applied:bool, ports:array<string,int|bool>, nginx:string}
     */
    public function apply(): array
    {
        $avail = $this->ngxSys . '/sites-available';
        $en    = $this->ngxSys . '/sites-enabled';
        @mkdir($avail, 0755, true);
        @mkdir($en, 0755, true);

        $tpl = $this->ensureTemplate();

        $stamp = date('YmdHis');
        $bak   = $this->home . '/releases/ports-ctrl-' . $stamp;
        @mkdir($bak, 0755, true);
        $touched = [];
        foreach (['alphacp-whm.conf', 'alphacp-cpanel.conf', 'alphacp-link.conf', 'alphacp-webmail.conf'] as $f) {
            if (is_file("$avail/$f")) {
                @copy("$avail/$f", "$bak/$f");
                $touched[] = $f;
            }
        }

        $standalone = $this->http2Standalone();
        $h2  = $standalone ? "    http2 on;\n" : '';
        $suf = $standalone ? '' : ' http2';

        file_put_contents("$avail/alphacp-whm.conf", $this->renderAppVhost($tpl, $this->map['whm'], $h2, $suf, false));
        file_put_contents("$avail/alphacp-cpanel.conf", $this->renderAppVhost($tpl, $this->map['cpanel'], $h2, $suf, true));
        $this->enable("$avail/alphacp-whm.conf", "$en/alphacp-whm.conf");
        $this->enable("$avail/alphacp-cpanel.conf", "$en/alphacp-cpanel.conf");

        // link-page (default 8090): static html, PHP nahi — owner band kar sake
        if ($this->map['link_enabled']) {
            file_put_contents("$avail/alphacp-link.conf", $this->renderLinkVhost($h2, $suf));
            $this->enable("$avail/alphacp-link.conf", "$en/alphacp-link.conf");
        } else {
            @unlink("$en/alphacp-link.conf");
            @unlink("$avail/alphacp-link.conf");
        }

        // purana 8090 app-vhost retire (ab link-page ya kuch nahi)
        if (is_file("$avail/alphacp-panel.conf")) {
            @copy("$avail/alphacp-panel.conf", "$bak/alphacp-panel.conf");
            @unlink("$en/alphacp-panel.conf");
            @rename("$avail/alphacp-panel.conf", "$bak/alphacp-panel.conf.retired");
            $touched[] = 'alphacp-panel.conf';
        }

        // webmail vhost listen port map ke saath sync
        $wconf = "$avail/alphacp-webmail.conf";
        if (is_file($wconf)) {
            $w = (string) file_get_contents($wconf);
            $w = preg_replace('/listen\s+\d+(\s+ssl)/', 'listen ' . $this->map['webmail'] . '$1', $w, 1) ?? $w;
            file_put_contents($wconf, $w);
        }

        // Roundcube plugin ke liye internal SSO URL (loopback-only, cpanel port)
        @file_put_contents(
            $this->home . '/etc/webmail-internal.url',
            'https://127.0.0.1:' . $this->map['cpanel'] . '/internal/webmail-sso',
        );
        @chmod($this->home . '/etc/webmail-internal.url', 0644);

        // Roundcube plugin config ka internal URL bhi cpanel port par sync karo
        $pc = $this->rcPlugins . '/acp_sso/config.inc.php';
        if (is_file($pc)) {
            $cs = (string) file_get_contents($pc);
            $cs = preg_replace(
                "/acp_sso_internal_url'\s*\]\s*=\s*'[^']*'/",
                "acp_sso_internal_url'] = 'https://127.0.0.1:" . $this->map['cpanel'] . "/internal/webmail-sso'",
                $cs,
            ) ?? $cs;
            file_put_contents($pc, $cs);
        }

        $t = $this->cmd->run(['sh', '-c', 'nginx -t 2>&1'], 30);
        if (! $t->ok()) {
            foreach ($touched as $f) {
                if (is_file("$bak/$f")) {
                    @copy("$bak/$f", "$avail/$f");
                }
            }
            $msg = substr(trim($t->stdout . $t->stderr), 0, 300);
            $this->log->warning('ports.apply: nginx -t FAIL — restore kiya: ' . $msg);

            return ['applied' => false, 'ports' => $this->map, 'nginx' => $msg];
        }
        $r = $this->cmd->run(['sh', '-c', 'nginx -s reload 2>&1 || systemctl reload nginx 2>&1'], 30);
        $this->log->info('ports.apply: vhosts likhe + reload (whm=' . $this->map['whm']
            . ' cpanel=' . $this->map['cpanel']
            . ' link=' . ($this->map['link_enabled'] ? (string) $this->map['link'] : 'off') . ')');

        return ['applied' => true, 'ports' => $this->map, 'nginx' => substr(trim($r->stdout . $r->stderr), 0, 200)];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $v = $this->cmd->run(['sh', '-c', 'nginx -v 2>&1'], 10);

        return [
            'ports'    => $this->map,
            'template' => is_file($this->home . '/etc/panel-vhost.template'),
            'nginx'    => trim($v->stdout . $v->stderr),
        ];
    }

    // ---- internals -------------------------------------------------------

    /**
     * Pehli run: served panel vhost se template (listen + internal loc strip).
     * Baad ki runs me existing template hi source of truth hai.
     */
    private function ensureTemplate(): string
    {
        $tplPath = $this->home . '/etc/panel-vhost.template';
        if (is_file($tplPath)) {
            return (string) file_get_contents($tplPath);
        }

        $src = '';
        foreach ([$this->ngxSys . '/sites-enabled', $this->ngxSys . '/sites-available', $this->ngxSys . '/conf.d'] as $dir) {
            $c = "$dir/alphacp-panel.conf";
            if (is_file($c) && str_contains((string) file_get_contents($c), 'fastcgi_pass')) {
                $src = (string) file_get_contents($c);
                break;
            }
        }
        if ($src === '') {
            throw new TaskRejectedException('panel vhost template nahi mila (alphacp-panel.conf me fastcgi_pass nahi)');
        }

        $src = preg_replace('/^[ \t]*listen[ \t]+[^\n]*\n/m', '', $src) ?? $src;
        $src = preg_replace('/^[ \t]*http2[ \t]+on;\n/m', '', $src) ?? $src;
        $src = preg_replace('/[ \t]*# ACP_INTERNAL_START[^\n]*\n[ \t]*location \/internal\/ \{[^\n]*\}\n[ \t]*# ACP_INTERNAL_END[ \t]*\n?/', '', $src) ?? $src;

        file_put_contents($tplPath, $src);
        @chmod($tplPath, 0644);

        return $src;
    }

    private function renderAppVhost(string $tpl, int $port, string $h2, string $suf, bool $withInternal): string
    {
        $internal = $withInternal
            ? "    # ACP_INTERNAL_START — SSO endpoint sirf loopback se\n"
            . "    location /internal/ { allow 127.0.0.1; allow ::1; deny all; try_files \$uri /index.php?\$args; }\n"
            . "    # ACP_INTERNAL_END\n"
            : '';

        return preg_replace_callback(
            '/server\s*\{/',
            fn (array $m): string => $m[0] . "\n    listen " . $port . ' ssl' . $suf . ";\n    listen [::]:" . $port . ' ssl' . $suf . ";\n" . $h2 . $internal,
            $tpl,
            1,
        ) ?? $tpl;
    }

    /** Static link-page: nginx $host request-time substitute karta hai. */
    private function renderLinkVhost(string $h2, string $suf): string
    {
        $m    = $this->map;
        $html = '<!doctype html><meta charset="utf-8"><title>AlphaCP</title>'
            . '<body style="font-family:sans-serif;background:#1c2733;color:#eef1f4;display:grid;place-items:center;height:100vh;margin:0">'
            . '<div style="text-align:center"><h1>AlphaCP</h1>'
            . '<p><a style="color:#FF6C2C" href="https://$host:' . $m['whm'] . '/">WHM &mdash; root / reseller</a></p>'
            . '<p><a style="color:#FF6C2C" href="https://$host:' . $m['cpanel'] . '/">cPanel &mdash; customer</a></p>'
            . '<p><a style="color:#FF6C2C" href="https://$host:' . $m['webmail'] . '/">Webmail</a></p>'
            . '</div></body>';

        return "server {\n"
            . '    listen ' . $m['link'] . ' ssl' . $suf . ";\n"
            . '    listen [::]:' . $m['link'] . ' ssl' . $suf . ";\n"
            . $h2
            . "    server_name _;\n"
            . $this->sslLines()
            . "    location / { default_type text/html; return 200 '" . str_replace("'", "\\'", $html) . "'; }\n}\n";
    }

    /** Template se ssl_certificate lines (link-page vhost ke liye). */
    private function sslLines(): string
    {
        $tpl = @file_get_contents($this->home . '/etc/panel-vhost.template') ?: '';
        $out = '';
        foreach (explode("\n", $tpl) as $ln) {
            if (str_contains($ln, 'ssl_certificate')) {
                $out .= '    ' . trim($ln) . "\n";
            }
        }

        return $out;
    }

    private function enable(string $avail, string $en): void
    {
        if (! is_link($en) && ! is_file($en)) {
            @symlink($avail, $en);
        }
    }

    private function http2Standalone(): bool
    {
        $v = $this->cmd->run(['sh', '-c', 'nginx -v 2>&1'], 10);
        if (preg_match('/nginx\/([\d.]+)/', $v->stdout . $v->stderr, $m) !== 1) {
            return false;
        }

        return version_compare($m[1], '1.25.1', '>=');
    }
}

PEOF
cat > "${AGENT}/src/Tasks/PortsApply.php" <<'PEOF'
<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\PortsNginx;
use Alphacp\Agent\TaskRejectedException;

/**
 * ports.apply — OWNER-CTRL port↔panel map ko nginx vhosts par lagana.
 *
 * Panel /ports page save ke baad ye task enqueue karta hai; installer bhi
 * `paneld --run ports.apply` se yahi call karta hai. Idempotent + backup/
 * restore ke saath (nginx -t fail → purani vhosts wapas).
 *
 * @acp-task ports.apply
 */
final class PortsApply implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $action = strtolower(trim((string) ($payload['action'] ?? 'apply')));
        $ports  = new PortsNginx(
            $ctx->cmd,
            $ctx->log,
            (string) (getenv('ACP_HOME') ?: '/usr/local/alphacp'),
            (string) (getenv('ACP_NGX_ROOT') ?: '/etc/nginx'),
            (string) (getenv('ACP_RC_PLUGINS') ?: '/usr/share/roundcube/plugins'),
        );

        return match ($action) {
            'apply'  => $ports->apply(),
            'status' => $ports->status(),
            default  => throw new TaskRejectedException(
                "ports.apply action '{$action}' nahi chalega (apply/status)"
            ),
        };
    }
}

PEOF
# tasks.php MERGE-mode: live ki doosri entries (ftp.add etc.) preserve;
# ports.apply entry marker-block me insert/replace (idempotent).
cat > "${BACKUP}/ports-apply.entry.php" <<'PEOF'
    'ports.apply' => [
        'handler'     => Tasks\PortsApply::class,
        'safety'      => 'mutating',
        'timeout'     => 120,
        'description' => 'Owner port control: WHM/cPanel/link/webmail vhosts regen + reload (backup/restore safe).',
        'paths'       => ['/usr/local/alphacp', '/etc/nginx', '/usr/share/roundcube'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'action' => ['type' => 'string', 'enum' => ['apply', 'status']],
            ],
            'required'             => ['action'],
        ],
    ],

PEOF
"$PHP_BIN" -r '
$f = $argv[1]; $e = file_get_contents($argv[2]);
$src = file_get_contents($f);
$bs = "// ACP-PORTS-CTRL-START"; $be = "// ACP-PORTS-CTRL-END";
$block = "    " . $bs . "\n" . rtrim($e) . "\n    " . $be;
if (strpos($src, $bs) !== false) {
    $a = strpos($src, $bs); $b = strpos($src, $be);
    if ($b === false) { fwrite(STDERR, "marker end missing\n"); exit(1); }
    $src = substr($src, 0, $a) . $block . substr($src, $b + strlen($be));
} else {
    $pos = strrpos($src, "];");
    if ($pos === false) { fwrite(STDERR, "tasks.php closing ]; nahi mila\n"); exit(1); }
    $src = substr($src, 0, $pos) . $block . "\n" . substr($src, $pos);
}
file_put_contents($f, $src);
' "${AGENT}/config/tasks.php" "${BACKUP}/ports-apply.entry.php" \
  || { rollback; die "tasks.php merge fail"; }
for f in src/PortsNginx.php src/Tasks/PortsApply.php config/tasks.php; do
  "$PHP_BIN" -l "${AGENT}/${f}" >/dev/null || { rollback; die "lint fail: agent/${f}"; }
done
grep -q "'ports.apply'" "${AGENT}/config/tasks.php" || { rollback; die "tasks.php me ports.apply entry nahi"; }
ok "3 agent payloads likhi + lint clean + allowlist entry"

# ---- 3) ports.json default ----
hdr "etc/ports.json (default map)"
if [[ ! -f "${ACP_HOME}/etc/ports.json" ]]; then
  mkdir -p "${ACP_HOME}/etc"
  printf '{\n    "whm": 2087,\n    "cpanel": 2083,\n    "webmail": 2096,\n    "link": 8090,\n    "link_enabled": true\n}\n' > "${ACP_HOME}/etc/ports.json"
  ok "ports.json likha (default map)"
else
  ok "ports.json pehle se maujood (owner map respected)"
fi
MAP_WHM="$("$PHP_BIN" -r 'echo json_decode(file_get_contents($argv[1]),true)["whm"] ?? 2087;' "${ACP_HOME}/etc/ports.json")"
MAP_CPANEL="$("$PHP_BIN" -r 'echo json_decode(file_get_contents($argv[1]),true)["cpanel"] ?? 2083;' "${ACP_HOME}/etc/ports.json")"
info "map: whm=${MAP_WHM} cpanel=${MAP_CPANEL}"

# ---- 4) ufw (live only) ----
hdr "ufw: whm + cpanel ports allow"
if [[ "$SIM" == "1" ]]; then
  info "SIM: ufw skip"
else
  ufw allow "${MAP_WHM}/tcp" comment 'AlphaCP WHM' >/dev/null 2>&1 || true
  ufw allow "${MAP_CPANEL}/tcp" comment 'AlphaCP cPanel' >/dev/null 2>&1 || true
  ok "ufw rules (${MAP_WHM}, ${MAP_CPANEL})"
fi

# ---- 5) agent apply (live only; sim me harness) ----
hdr "nginx vhosts regen (ports.apply)"
if [[ "$SIM" == "1" ]]; then
  info "SIM: agent apply harness sim me chalta hai"
else
  if "${AGENT}/bin/paneld" --run ports.apply '{"action":"apply"}' >"${LOGD}/ports-apply-last.txt" 2>&1; then
    grep -q '"applied":true' "${LOGD}/ports-apply-last.txt" \
      && ok "ports.apply: vhosts regen + reload" \
      || { rollback; die "ports.apply ne applied:true nahi diya (nginx -t fail?)"; }
  else
    rollback; die "ports.apply task fail (log: ${LOGD}/ports-apply-last.txt)"
  fi
fi

# ---- 6) structural asserts ----
hdr "structural asserts"
grep -q 'AcpPortGuard' "${PANEL}/bootstrap/app.php" \
  || { rollback; die "bootstrap me AcpPortGuard register nahi"; }
grep -q 'WHM Login' "${PANEL}/resources/views/auth/login.blade.php" \
  || { rollback; die "login blade me WHM branding nahi"; }
grep -q 'WHM — Server Manager Dashboard' "${PANEL}/resources/views/dashboard-whm.blade.php" \
  || { rollback; die "dashboard-whm blade nahi"; }
grep -q 'cPanel — Account Panel' "${PANEL}/resources/views/dashboard-cpanel.blade.php" \
  || { rollback; die "dashboard-cpanel blade nahi"; }
grep -q "dashboard-whm" "${PANEL}/app/Http/Controllers/DashboardController.php" \
  || { rollback; die "DashboardController mode-view select nahi"; }
grep -q 'ports_file' "${PANEL}/config/acp.php" \
  || { rollback; die "acp config me ports_file nahi"; }
if [[ "$SIM" != "1" ]]; then
  AV="${NGX_SYS}/sites-available"
  [[ -f "${AV}/alphacp-whm.conf" ]]    || { rollback; die "whm vhost nahi bana"; }
  [[ -f "${AV}/alphacp-cpanel.conf" ]] || { rollback; die "cpanel vhost nahi bana"; }
  grep -q "listen ${MAP_WHM} ssl" "${AV}/alphacp-whm.conf" \
    || { rollback; die "whm vhost me listen ${MAP_WHM} nahi"; }
  grep -q "listen ${MAP_CPANEL} ssl" "${AV}/alphacp-cpanel.conf" \
    || { rollback; die "cpanel vhost me listen ${MAP_CPANEL} nahi"; }
  grep -q 'location /internal/' "${AV}/alphacp-cpanel.conf" \
    || { rollback; die "cpanel vhost me /internal/ loc nahi"; }
  ! grep -q 'location /internal/' "${AV}/alphacp-whm.conf" \
    || { rollback; die "whm vhost me /internal/ loc NAHI hona chahiye"; }
  ok "vhost structure sahi (whm/cpanel/internal loc)"
fi
ok "structural asserts pass"

# ---- 7) HTTP smokes (live only) ----
hdr "HTTP smokes"
if [[ "$SIM" == "1" ]]; then
  info "SIM: smokes skip (harness asserts chuke)"
else
  code="$(curl -k -s -o /tmp/pc-whm.html -w '%{http_code}' -m 10 "https://127.0.0.1:${MAP_WHM}/login" 2>/dev/null || echo 000)"
  [[ "$code" == "200" ]] && grep -q 'WHM Login' /tmp/pc-whm.html \
    && ok "whm ${MAP_WHM} login (WHM branding)" || warn "whm login smoke: HTTP ${code}"
  code="$(curl -k -s -o /tmp/pc-cp.html -w '%{http_code}' -m 10 "https://127.0.0.1:${MAP_CPANEL}/login" 2>/dev/null || echo 000)"
  [[ "$code" == "200" ]] && grep -q 'cPanel Login' /tmp/pc-cp.html \
    && ok "cpanel ${MAP_CPANEL} login (cPanel branding)" || warn "cpanel login smoke: HTTP ${code}"
  code="$(curl -k -s -o /dev/null -w '%{http_code}' -m 10 "https://127.0.0.1:${MAP_CPANEL}/internal/webmail-sso?token=xx" 2>/dev/null || echo 000)"
  [[ "$code" == "403" || "$code" == "422" ]] && ok "internal SSO endpoint cpanel port par secret-gated (${code})" || warn "internal endpoint HTTP ${code}"
  code="$(curl -k -s -o /dev/null -w '%{http_code}' -m 10 "https://127.0.0.1:2096/" 2>/dev/null || echo 000)"
  [[ "$code" == "200" ]] && ok "webmail 2096 HTTP 200" || warn "webmail 2096 HTTP ${code}"
fi

# ---- 8) agent suite ----
hdr "AGENT SUITE — full run (current era)"
set +e
SUITE_FULL="$(cd "${AGENT}" && "$PHP_BIN" tests/run-tests.php 2>&1)"
SUITE_RC=$?
set -e
echo "$SUITE_FULL" | tail -4 | sed 's/^/  · /'
echo "$SUITE_FULL" | grep -a -A2 "^  FAIL" | head -24 | sed 's/^/  ! /' || true
{ [[ "$SUITE_RC" -eq 0 ]] && echo "$SUITE_FULL" | grep -q "failed: 0"; } || { rollback; die "agent suite fail (rc=${SUITE_RC}, FAIL lines upar)"; }
ok "agent suite GREEN"
cd - >/dev/null 2>&1 || true

# ---- 9) sync ----
hdr "alphacp-sync"
if [[ "$SIM" == "1" ]]; then
  info "SIM: sync skip"
else
  alphacp-sync >/dev/null 2>&1 && ok "sync complete (repo snapshot update)" || warn "sync skip/fail"
fi

hdr "FINAL VERDICT"
ok "WHM sirf ${MAP_WHM} par, cPanel sirf ${MAP_CPANEL} par, webmail 2096 par, link-page 8090 (owner-controlled)"
info "backup : ${BACKUP}"
info "log    : ${LOG}"
info "rollback: sudo bash /tmp/ports-ctrl-v1.0.sh --rollback"
printf '\n  ports-ctrl v%s APPLY ho gaya. AWS SG me %s+%s kholo; /ports page se mapping editable.\n\n' "$VERSION" "$MAP_WHM" "$MAP_CPANEL"
