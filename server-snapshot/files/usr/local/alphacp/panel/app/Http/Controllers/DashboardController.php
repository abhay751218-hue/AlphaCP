<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\Panel;
use App\Support\Paneld;
use Illuminate\View\View;

/** cPanel-style dashboard: quick stats + section/tile grid. */
class DashboardController extends Controller
{
    public function index(): View
    {
        $system   = Paneld::run('system.info', [], 8);   // real data via the root agent
        $services = Paneld::run('service.status', [], 10);

        return view('dashboard', [
            'system'    => $system,
            'services'  => $services['services'] ?? [],
            'queue'     => Panel::queueStats(),
            'tasks'     => Paneld::recentTasks(6),
            'sections'  => ModuleCatalog::sections(),
            'progress'  => ModuleCatalog::progress(),
            'audit'     => Audit::recent(6),
            'server'    => Panel::server(),
            'versions'  => Panel::versions(),
        ]);
    }
}
