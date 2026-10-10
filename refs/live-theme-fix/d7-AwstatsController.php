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
 * cPanel "Awstats" — visitor/bandwidth graphs (CSS bars, no JS) access-log
 * stats se. Data ROOT AGENT deta hai (metrics.access, readonly).
 */
final class AwstatsController extends Controller
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

        $top    = is_array($stats['top'] ?? null) ? $stats['top'] : [];
        $maxHit = $top === [] ? 1 : max(1, max($top));

        return view('awstats.index', [
            'account'   => $account,
            'stats'     => $stats,
            'top'       => $top,
            'maxHit'    => $maxHit,
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
