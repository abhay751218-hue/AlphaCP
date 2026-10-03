<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BackupRestoration;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Backup Restoration — full/partial/per-account via paneld backup.restoration. No tar, no shell, no pipe. */
class BackupRestorationController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('backup-restoration.index', [
            'row' => BackupRestoration::query()->orderByDesc('id')->first(),
            'modes' => Backup::MODES,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'mode' => ['required', 'string', 'max:16'],
            'username' => ['required', 'string', 'max:16'],
        ]);
        $mode = Backup::tryMode($data['mode']);
        $username = Backup::tryUsername($data['username']);
        if ($mode === null || $username === null) {
            return back()->withErrors(['mode' => 'Invalid backup restoration. Mode full/partial/account. Username 3–16 a-z/0-9. No pipe/path.'])->withInput();
        }
        $row = BackupRestoration::query()->orderByDesc('id')->first();
        if ($row === null) {
            BackupRestoration::query()->create([
                'mode' => $mode,
                'username' => $username,
            ]);
        } else {
            $row->update([
                'mode' => $mode,
                'username' => $username,
            ]);
        }
        BackupProvisioner::enqueueRestoration($mode, $username);
        Audit::log('backup.restoration', 'info', 'system', null, ['mode' => $mode, 'username' => $username]);

        return redirect()->route('backup-restoration.index')->with('success', 'Backup restoration is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Backup Restoration is a WHM tool.');
        }
    }
}
