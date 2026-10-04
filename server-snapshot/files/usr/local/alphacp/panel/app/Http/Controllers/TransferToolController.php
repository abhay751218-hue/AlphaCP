<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\TransferTool;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\CpanelArchives;
use App\Support\Dns;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM Transfer Tool — migrate a cPanel account onto this server from a cpmove
 * archive the operator placed on the box (`paneld backup.transfer`).
 *
 * The source FQDN is recorded in the job result for the audit trail; pulling
 * the archive straight off the old server is a later S10 step.
 */
class TransferToolController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('transfer-tool.index', [
            'row' => TransferTool::query()->orderByDesc('id')->first(),
            'archives' => CpanelArchives::candidates(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
            'source' => ['required', 'string', 'max:190'],
            'archive_path' => ['required', 'string', 'max:255'],
            'sha256' => ['nullable', 'string', 'max:64'],
        ]);
        $username = Backup::tryUsername($data['username']);
        $source = Dns::tryDomain($data['source']);
        $archive = Backup::tryArchivePath($data['archive_path']);
        $sha256 = Backup::normalizeSha256((string) ($data['sha256'] ?? ''));
        if ($username === null || $source === null || $archive === null || $sha256 === null) {
            return back()->withErrors([
                'source' => 'Invalid transfer. Username 3–16 a-z/0-9; source FQDN; archive ka poora path (.tar/.tar.gz/.tgz); sha256 optional 64 hex. No pipe/path escape.',
            ])->withInput();
        }
        $account = Account::query()->where('username', $username)->first();
        if ($account === null) {
            return back()->withErrors([
                'username' => 'Pehle Accounts → Create Account se yeh account banao — transfer existing account ke home me import hota hai.',
            ])->withInput();
        }

        $row = TransferTool::query()->orderByDesc('id')->first();
        if ($row === null) {
            TransferTool::query()->create([
                'username' => $username,
                'source' => $source,
            ]);
        } else {
            $row->update([
                'username' => $username,
                'source' => $source,
            ]);
        }
        BackupProvisioner::enqueueTransfer($username, $source, $archive, (string) $sha256);
        Audit::log('backup.transfer', 'info', 'account', $account->id, [
            'username' => $username,
            'source' => $source,
            'archive' => $archive,
        ]);

        return redirect()->route('transfer-tool.index')->with('success', 'Transfer queue ho gaya — status Review Transfers and Restores par dekho.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Transfer Tool is a WHM tool.');
        }
    }
}
