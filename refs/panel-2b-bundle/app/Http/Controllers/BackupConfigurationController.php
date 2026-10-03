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

/** WHM Backup Configuration — JSON via paneld backup.config. No tar, no shell, no pipe. */
class BackupConfigurationController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('backup-configuration.index', [
            'row' => BackupConfig::query()->orderByDesc('id')->first(),
            'schedules' => Backup::SCHEDULES,
            'destinations' => Backup::DESTINATIONS,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'enabled' => ['nullable', 'string', 'max:8'],
            'schedule' => ['required', 'string', 'max:16'],
            'retention_days' => ['required', 'string', 'max:4'],
            'destination' => ['required', 'string', 'max:16'],
            'remote_host' => ['nullable', 'string', 'max:190'],
            'remote_user' => ['nullable', 'string', 'max:64'],
            'remote_path' => ['nullable', 'string', 'max:190'],
        ]);
        $enabled = Backup::tryEnabled((string) ($data['enabled'] ?? 'off'));
        $schedule = Backup::trySchedule($data['schedule']);
        $retention = Backup::tryRetentionDays($data['retention_days']);
        $destination = Backup::tryDestination($data['destination']);
        if ($enabled === null || $schedule === null || $retention === null || $destination === null) {
            return back()->withErrors(['schedule' => 'Invalid schedule/retention/destination. Retention 1–3650 days.'])->withInput();
        }
        $remoteHost = null;
        $remoteUser = null;
        $remotePath = null;
        if ($destination === 'remote') {
            $remoteHost = Backup::tryRemoteHost((string) ($data['remote_host'] ?? ''));
            if ($remoteHost === null) {
                return back()->withErrors(['remote_host' => 'Invalid remote host. IPv4 or FQDN only. No pipe/path escape.'])->withInput();
            }
            $remoteUser = Backup::tryRemoteUser((string) ($data['remote_user'] ?? ''));
            if ($remoteUser === null) {
                return back()->withErrors(['remote_user' => 'Invalid remote user. Lowercase, no pipe/path escape.'])->withInput();
            }
            $remotePath = Backup::tryRemotePath((string) ($data['remote_path'] ?? ''));
            if ($remotePath === null) {
                return back()->withErrors(['remote_path' => 'Invalid remote path. Relative only (no leading /, no .., no pipe).'])->withInput();
            }
        }
        $row = BackupConfig::query()->orderByDesc('id')->first();
        $attributes = [
            'enabled'        => $enabled,
            'schedule'       => $schedule,
            'retention_days' => $retention,
            'destination'    => $destination,
            'remote_host'    => $remoteHost,
            'remote_user'    => $remoteUser,
            'remote_path'    => $remotePath,
        ];
        if ($row === null) {
            $row = BackupConfig::query()->create($attributes);
        } else {
            $row->update($attributes);
        }
        BackupProvisioner::enqueueConfig($row);
        Audit::log('backup.config', 'info', 'system', null, [
            'enabled'        => $enabled,
            'schedule'       => $schedule,
            'retention_days' => $retention,
            'destination'    => $destination,
        ]);

        return redirect()->route('backup-configuration.index')->with('success', 'Backup configuration is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Backup Configuration is a WHM tool.');
        }
    }
}
