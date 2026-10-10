<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Metrics;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Raw Access" — account ke Apache access-log ki raw aakhri entries
 * + summary stats. Log ROOT AGENT padhta hai (terminal.run cat + metrics.access).
 */
final class RawAccessController extends Controller
{
    private const MAX_LINES = 50;

    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $lines   = [];
        $log     = null;
        $stats   = null;
        $agentOk = in_array('terminal.run', Paneld::taskTypes(), true);

        if ($account !== null && $agentOk) {
            $candidate = '/var/log/apache2/' . $account->username . '-access.log';
            $res = Paneld::run('terminal.run', ['command' => 'cat ' . $candidate], 30);
            if (is_array($res) && ($res['status'] ?? '') === 'ok' && trim((string) ($res['output'] ?? '')) !== '') {
                $all   = preg_split('/\r?\n/', trim((string) $res['output']));
                $lines = array_slice($all, -self::MAX_LINES);
                $log   = $candidate;
            }
        }

        if ($account !== null && in_array('metrics.access', Paneld::taskTypes(), true)) {
            $res = Paneld::run('metrics.access', ['account' => $account->username], 20);
            if (is_array($res['stats'] ?? null)) {
                $stats = $res['stats'];
            }
        }

        return view('raw-access.index', [
            'account'   => $account,
            'agentOk'   => $agentOk,
            'log'       => $log,
            'lines'     => array_reverse($lines),
            'stats'     => $stats,
            'human'     => $stats !== null ? Metrics::human((int) ($stats['bytes'] ?? 0)) : '0 B',
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
