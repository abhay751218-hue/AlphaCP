<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ModuleCatalog;
use App\Support\SystemStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM-style Monitoring / Resource Usage dashboard. */
final class MonitoringController extends Controller
{
    public function index(Request $request): View
    {
        return view('monitoring.index', [
            'memory'    => SystemStats::memory(),
            'load'      => SystemStats::load(),
            'disk'      => SystemStats::disk(),
            'cpus'      => SystemStats::cpus(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }
}
