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
 * cPanel Metrics — Visitors / Errors / Bandwidth / top pages.
 *
 * B2: pehle panel ka log-parser web-FPM se `/var/log/apache2/…` padhta
 * tha jo open_basedir me nahi hai → page live par khali/error. Ab log ROOT AGENT
 * padhta hai (`metrics.access`) aur panel synchronous result dikhata hai.
 */
final class MetricsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $stats   = null;

        if ($account !== null && in_array('metrics.access', Paneld::taskTypes(), true)) {
            $res = Paneld::run('metrics.access', ['account' => $account->username], 20);
            if (is_array($res['stats'] ?? null)) {
                $stats = $res['stats'];
            }
        }

        return view('metrics.index', [
            'account'   => $account,
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
