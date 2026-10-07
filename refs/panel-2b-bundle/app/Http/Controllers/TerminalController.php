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
