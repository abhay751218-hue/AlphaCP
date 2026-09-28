<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AgentQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
    {
        $services = AgentQueue::fetch('service.status', [], waitSeconds: 3, freshSeconds: 60);
        $system   = AgentQueue::fetch('system.info', [], waitSeconds: 3, freshSeconds: 60);

        $serviceList = $services['result']['services'] ?? [];
        $activeCount = $services['result']['active_count'] ?? 0;

        return view('dashboard', [
            'user'        => Auth::user(),
            'serverName'  => DB::table('servers')->where('id', AgentQueue::serverId())->value('name') ?? gethostname(),
            'modules'     => config('panel_modules.sections'),
            'services'    => $serviceList,
            'activeCount' => $activeCount,
            'sysinfo'     => $system['result'] ?? null,
            'sysState'    => $system['status'],
            'svcState'    => $services['status'],
            'queue'       => AgentQueue::stats(),
            'events'      => DB::table('audit_logs')->orderByDesc('id')->limit(8)->get(),
        ]);
    }
}
