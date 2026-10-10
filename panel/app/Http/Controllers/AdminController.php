<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AgentQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * WHM-style admin home + reseller home (Step 2B-UI).
 *
 * Data sources: paneld via AgentQueue (system.info, service.status) + panel DB
 * (servers, accounts, packages, audit_logs). The accounts/packages tables land
 * with Steps S3/S4 — until then Schema::hasTable() guards keep the page green
 * and show honest empty states instead of SQL errors.
 *
 * @acp-task system.info   — read-only server facts (allowlist, agent/config/tasks.php)
 * @acp-task service.status — read-only service states (allowlist)
 */
final class AdminController extends Controller
{
    public function dashboard(): View
    {
        return $this->render('admin.dashboard', 'admin');
    }

    public function reseller(): View
    {
        return $this->render('reseller.dashboard', 'reseller');
    }

    private function render(string $view, string $kind): View
    {
        $services = AgentQueue::fetch('service.status', [], waitSeconds: 3, freshSeconds: 60);
        $system   = AgentQueue::fetch('system.info', [], waitSeconds: 3, freshSeconds: 60);

        /** @var User $user */
        $user = Auth::user();
        $isReseller = $kind === 'reseller';

        // Accounts + packages arrive with S3/S4 migrations; guard so the panel
        // never 500s before they exist.
        $hasAccounts = Schema::hasTable('accounts');
        $hasPackages = Schema::hasTable('packages');

        $accounts = collect();
        $accountStats = ['total' => 0, 'active' => 0, 'suspended' => 0, 'pending' => 0];
        $packageCount = 0;

        if ($hasAccounts) {
            $base = DB::table('accounts');
            // Assumption: accounts.reseller_id stores the reseller's users.id
            // (hierarchy link per docs/02-database-schema.sql).
            if ($isReseller) {
                $base->where('reseller_id', $user->id);
            }

            $accountStats = [
                'total'     => (clone $base)->count(),
                'active'    => (clone $base)->where('status', 'active')->count(),
                'suspended' => (clone $base)->where('status', 'suspended')->count(),
                'pending'   => (clone $base)->where('status', 'pending')->count(),
            ];

            $accounts = $base
                ->leftJoin('packages', 'accounts.package_id', '=', 'packages.id')
                ->select('accounts.*', 'packages.name as package_name')
                ->orderByDesc('accounts.id')
                ->limit(12)
                ->get();
        }

        if ($hasPackages) {
            $packages = DB::table('packages');
            if ($isReseller) {
                $packages->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', $user->id));
            }
            $packageCount = $packages->count();
        }

        return view($view, [
            'serverName'   => DB::table('servers')->where('id', AgentQueue::serverId())->value('name') ?? gethostname(),
            'menu'         => config('whm_menu.' . ($isReseller ? 'reseller' : 'admin')),
            'panelKind'    => $kind,
            'services'     => $services['result']['services'] ?? [],
            'activeCount'  => $services['result']['active_count'] ?? 0,
            'sysinfo'      => $system['result'] ?? null,
            'sysState'     => $system['status'],
            'queue'        => AgentQueue::stats(),
            'accounts'     => $accounts,
            'accountStats' => $accountStats,
            'accountsReady'=> $hasAccounts,
            'packageCount' => $packageCount,
            'packagesReady'=> $hasPackages,
            'events'       => DB::table('audit_logs')->orderByDesc('id')->limit(8)->get(),
        ]);
    }
}
