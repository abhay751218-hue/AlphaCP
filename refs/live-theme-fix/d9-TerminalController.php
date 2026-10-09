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
 * Command ROOT AGENT chalata hai (`terminal.run`), jo whitelist DOBARA
 * validate karta hai (defense in depth); panel sirf result dikhata hai.
 *
 * D9: session-based command history (last 8, output trimmed) + quick
 * commands — view me chips, rerun buttons. Whitelist SAME (agent-mirror).
 */
final class TerminalController extends Controller
{
    private const ALLOWED = [
        'ls', 'pwd', 'whoami', 'date', 'uname', 'df', 'free', 'uptime',
        'git status', 'php -v', 'node -v', 'cat ',
    ];

    private const HISTORY_MAX = 8;
    private const HISTORY_OUTPUT_CAP = 4000;

    public function index(Request $request): View
    {
        return view('terminal.index', [
            'output'  => session('term_output'),
            'cmd'     => session('term_cmd'),
            'error'   => session('term_error'),
            'history' => (array) $request->session()->get('term_history', []),
            'allowed' => self::ALLOWED,
            'agentOk' => in_array('terminal.run', Paneld::taskTypes(), true),
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

        $output = (string) ($res['output'] ?? '');
        $error  = ($res['status'] ?? 'ok') === 'ok' ? null : trim((string) ($res['error'] ?? ''));

        $history = (array) $request->session()->get('term_history', []);
        array_unshift($history, [
            'cmd'    => $cmd,
            'output' => mb_substr($output, 0, self::HISTORY_OUTPUT_CAP),
            'error'  => $error,
            'at'     => now()->format('H:i:s'),
        ]);
        $request->session()->put('term_history', array_slice($history, 0, self::HISTORY_MAX));

        return redirect('/terminal')->with([
            'term_cmd'    => $cmd,
            'term_output' => $output,
            'term_error'  => $error,
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
