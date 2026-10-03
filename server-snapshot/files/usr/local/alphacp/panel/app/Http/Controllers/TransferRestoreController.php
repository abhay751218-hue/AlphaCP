<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TransferRestore;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Transfer or Restore a cPanel Account via paneld backup.cpanel. No tar, no rsync, no shell, no pipe. */
class TransferRestoreController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('transfer-restore.index', [
            'row' => TransferRestore::query()->orderByDesc('id')->first(),
            'actions' => Backup::CPANEL_ACTIONS,
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
            'action' => ['required', 'string', 'max:16'],
        ]);
        $username = Backup::tryUsername($data['username']);
        $action = Backup::tryCpanelAction($data['action']);
        if ($username === null || $action === null) {
            return back()->withErrors(['action' => 'Invalid cPanel account job. Action transfer/restore. Username 3–16 a-z/0-9. No pipe/path.'])->withInput();
        }
        $row = TransferRestore::query()->orderByDesc('id')->first();
        if ($row === null) {
            TransferRestore::query()->create([
                'username' => $username,
                'action' => $action,
            ]);
        } else {
            $row->update([
                'username' => $username,
                'action' => $action,
            ]);
        }
        BackupProvisioner::enqueueCpanel($username, $action);
        Audit::log('backup.cpanel', 'info', 'system', null, ['username' => $username, 'action' => $action]);

        return redirect()->route('transfer-restore.index')->with('success', 'cPanel account job is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Transfer or Restore a cPanel Account is a WHM tool.');
        }
    }
}
