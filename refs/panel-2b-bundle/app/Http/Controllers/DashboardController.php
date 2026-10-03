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

        return view('dashboard', [
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
