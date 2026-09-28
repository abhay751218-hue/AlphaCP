<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AuthController extends Controller
{
    /** cPHulk-style gate: too many failures from an IP+username pair = temporary lock. */
    private const MAX_FAILURES  = 5;
    private const WINDOW_MIN    = 15;
    private const LOCKOUT_MIN   = 15;

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'serverName' => DB::table('servers')->where('id', $this->serverId())->value('name') ?? gethostname(),
            'panelVersion' => (string) (getenv('ACP_PANEL_VERSION') ?: '0.3.0'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $ip       = $request->ip() ?? '0.0.0.0';
        $username = strtolower(trim($data['username']));

        // --- throttle ---------------------------------------------------------
        $failures = DB::table('login_attempts')
            ->where('ip', $ip)
            ->where('username', $username)
            ->where('successful', 0)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MIN))
            ->count();

        if ($failures >= self::MAX_FAILURES) {
            Audit::log('auth.login.throttled', severity: 'warning', meta: ['username' => $username], ip: $ip);

            return back()->withInput($request->only('username'))
                ->with('error', 'Bahut zyada galat koshish — ' . self::LOCKOUT_MIN . ' minute baad try karein.');
        }

        // --- attempt ----------------------------------------------------------
        $ok = Auth::attempt([
            'username' => $username,
            'password' => $data['password'],
            'status'   => 'active',
        ], false);

        DB::table('login_attempts')->insert([
            'ip'         => $ip,
            'username'   => $username,
            'successful' => $ok ? 1 : 0,
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);

        if (!$ok) {
            $user = User::where('username', $username)->first();
            if ($user !== null) {
                $user->increment('failed_logins');
            }
            Audit::log('auth.login.failed', actorType: 'user', actorId: $user?->id,
                severity: 'warning', meta: ['username' => $username], ip: $ip);

            return back()->withInput($request->only('username'))
                ->with('error', 'Username ya password galat hai.');
        }

        // --- success ----------------------------------------------------------
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            'failed_logins' => 0,
        ])->save();

        Audit::log('auth.login.success', actorType: 'user', actorId: $user->id, meta: ['username' => $username], ip: $ip);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $id = Auth::id();
        Audit::log('auth.logout', actorType: 'user', actorId: $id);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Aap logout ho gaye.');
    }

    private function serverId(): int
    {
        return (int) (getenv('ACP_SERVER_ID') ?: 1);
    }
}
