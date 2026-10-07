<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Package;
use App\Support\Audit;
use App\Support\DomainProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Panel;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Role-aware dashboards for server operators, resellers and hosting customers. */
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
        $resellerAccounts = collect();
        $resellerStats = ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'packages' => 0];

        if ($mode === 'whm') {
            $system = Paneld::run('system.info', [], 8);
            $services = Paneld::run('service.status', [], 10);
            $queue = Panel::queueStats();
            $audit = Audit::recent(6);
        } elseif ($mode === 'reseller') {
            // Every count and row is scoped to this reseller; never aggregate the
            // other resellers' customers into the reseller dashboard.
            $ownedAccounts = Account::query()->where('reseller_id', $user->id);
            $resellerStats = [
                'total' => (clone $ownedAccounts)->count(),
                'active' => (clone $ownedAccounts)->where('status', 'active')->count(),
                'pending' => (clone $ownedAccounts)->where('status', 'pending')->count(),
                'suspended' => (clone $ownedAccounts)->where('status', 'suspended')->count(),
                'packages' => Package::query()->where('status', 'active')
                    ->where(fn ($query) => $query->whereNull('owner_id')->orWhere('owner_id', $user->id))
                    ->count(),
            ];
            $resellerAccounts = (clone $ownedAccounts)
                ->with('package')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();
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
            'resellerStats' => $resellerStats,
            'recentResellerAccounts' => $resellerAccounts,
        ]);
    }
}
