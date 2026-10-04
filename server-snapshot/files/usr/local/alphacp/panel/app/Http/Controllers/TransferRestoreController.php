<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\TransferRestore;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\CpanelArchives;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM Transfer or Restore a cPanel Account — queue a REAL cpmove import via
 * paneld `backup.cpanel`.
 *
 * The panel never reads the archive: the operator places the tarball on the
 * server, the panel queues the path, and the root agent validates + swaps the
 * home (keeping an `/home/.acp-prerestore-*` copy).
 */
class TransferRestoreController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('transfer-restore.index', [
            'row' => TransferRestore::query()->orderByDesc('id')->first(),
            'actions' => Backup::CPANEL_ACTIONS,
            'archives' => CpanelArchives::candidates(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
            'action' => ['required', 'string', 'max:16'],
            'archive_path' => ['required', 'string', 'max:255'],
            'sha256' => ['nullable', 'string', 'max:64'],
        ]);
        $username = Backup::tryUsername($data['username']);
        $action = Backup::tryCpanelAction($data['action']);
        $archive = Backup::tryArchivePath($data['archive_path']);
        $sha256 = Backup::normalizeSha256((string) ($data['sha256'] ?? ''));
        if ($username === null || $action === null || $archive === null || $sha256 === null) {
            return back()->withErrors([
                'action' => 'Invalid cPanel import. Username 3–16 a-z/0-9; action transfer/restore; archive ka poora path (.tar/.tar.gz/.tgz); sha256 optional 64 hex. No pipe/path escape.',
            ])->withInput();
        }
        $account = Account::query()->where('username', $username)->first();
        if ($account === null) {
            return back()->withErrors([
                'username' => 'Pehle Accounts → Create Account se yeh account banao — import existing account ke home me hota hai.',
            ])->withInput();
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
        BackupProvisioner::enqueueCpanel($username, $action, $archive, (string) $sha256);
        Audit::log('backup.cpanel', 'info', 'account', $account->id, [
            'username' => $username,
            'action' => $action,
            'archive' => $archive,
        ]);

        return redirect()->route('transfer-restore.index')->with('success', 'cPanel import queue ho gaya — status Review Transfers and Restores par dekho.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Transfer or Restore a cPanel Account is a WHM tool.');
        }
    }
}
