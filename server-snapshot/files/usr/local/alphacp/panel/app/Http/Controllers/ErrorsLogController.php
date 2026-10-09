<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Errors" — account ke Apache error-log ki aakhri entries.
 *
 * Log ROOT AGENT padhta hai (terminal.run `cat`, read-only whitelist +
 * PathGuard /var/log) — web-FPM open_basedir issue nahi hota (B2 pattern).
 */
final class ErrorsLogController extends Controller
{
    private const MAX_LINES = 40;

    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $lines   = [];
        $log     = null;
        $agentOk = in_array('terminal.run', Paneld::taskTypes(), true);

        if ($account !== null && $agentOk) {
            foreach ([
                '/var/log/apache2/' . $account->username . '-error.log',
                '/var/log/apache2/error.log',
            ] as $candidate) {
                $res = Paneld::run('terminal.run', ['command' => 'cat ' . $candidate], 30);
                if (is_array($res) && ($res['status'] ?? '') === 'ok' && trim((string) ($res['output'] ?? '')) !== '') {
                    $all   = preg_split('/\r?\n/', trim((string) $res['output']));
                    $lines = array_slice($all, -self::MAX_LINES);
                    $log   = $candidate;
                    break;
                }
            }
        }

        return view('errors-log.index', [
            'account'   => $account,
            'agentOk'   => $agentOk,
            'log'       => $log,
            'lines'     => array_reverse($lines),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }
}
