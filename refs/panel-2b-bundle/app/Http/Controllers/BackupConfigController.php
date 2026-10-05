<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BackupConfig;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Backup Config — schedule/retention via paneld backup.config. No tar, no shell, no pipe. */
class BackupConfigController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('backup-config.index', [
            'row' => BackupConfig::query()->orderByDesc('id')->first(),
            'schedules' => Backup::SCHEDULES,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'schedule' => ['required', 'string', 'max:16'],
            'retention' => ['required', 'string', 'max:8'],
        ]);
        $schedule = Backup::trySchedule($data['schedule']);
        $retention = Backup::tryRetention($data['retention']);
        if ($schedule === null || $retention === null) {
            return back()->withErrors(['schedule' => 'Invalid backup config. Schedule daily/weekly/monthly/disabled. Retention 1–365 days. No pipe/path.'])->withInput();
        }
        $row = BackupConfig::query()->orderByDesc('id')->first();
        if ($row === null) {
            BackupConfig::query()->create([
                'schedule' => $schedule,
                'retention' => $retention,
            ]);
        } else {
            $row->update([
                'schedule' => $schedule,
                'retention' => $retention,
            ]);
        }
        BackupProvisioner::enqueueConfig($schedule, $retention);
        Audit::log('backup.config', 'info', 'system', null, ['schedule' => $schedule, 'retention' => $retention]);

        return redirect()->route('backup-config.index')->with('success', 'Backup config is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Backup Config is a WHM tool.');
        }
    }
}
