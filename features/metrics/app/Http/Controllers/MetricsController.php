<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Metrics;
use App\Support\ModuleCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Metrics — Visitors / Errors / Bandwidth from the account access log. */
final class MetricsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $stats   = $account !== null ? Metrics::parse($this->logPathFor($account)) : null;

        return view('metrics.index', [
            'account'   => $account,
            'stats'     => $stats,
            'human'     => $stats !== null ? Metrics::human($stats['bytes']) : '0 B',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    private function logPathFor(Account $account): string
    {
        $pattern = (string) config('acp.access_log_pattern', '/var/log/apache2/{user}-access.log');

        return str_replace('{user}', $account->username, $pattern);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }
}
