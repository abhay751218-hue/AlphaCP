<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel/WHM "Terminal" (simplified, non-interactive) — whitelisted read-only
 * commands only. Chaining/redirects (`;|&\`$><`) blocked.
 *
 * B1: pehle command web-FPM me Process facade se chalta tha → proc_open
 * disabled → HTTP 500. Ab command root agent chalata hai (`terminal.run`), jo
 * whitelist DOBARA validate karta hai (defense in depth); panel sirf result
 * synchronous dikhata hai (Paneld::run).
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
        if (! in_array('terminal.run', Paneld::taskTypes(), true)) {
            return redirect('/terminal')->with('term_error', 'Agent par terminal.run available nahi (agent update chahiye).');
        }

        $res = Paneld::run('terminal.run', ['command' => $cmd], 30);
        if ($res === null) {
            return redirect('/terminal')->with(['term_cmd' => $cmd, 'term_error' => 'Agent se jawab nahi mila (timeout).']);
        }

        return redirect('/terminal')->with([
            'term_cmd'    => $cmd,
            'term_output' => (string) ($res['output'] ?? ''),
            'term_error'  => ($res['status'] ?? 'ok') === 'ok' ? null : trim((string) ($res['error'] ?? '')),
        ]);
    }

    private function safe(string $cmd): bool
    {
        if (preg_match('/[;&|`$><\\\\]/', $cmd) === 1) {
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
