<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BackupRestore;
use App\Support\Audit;
use App\Support\BackupProvisioner;
use App\Support\Files;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel File Restoration — path list via paneld backup.restore. No tar, no shell, no pipe. */
class FileRestorationController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('file-restoration.index', [
            'account' => $account,
            'rows' => $account?->backupRestores()->orderBy('id')->get() ?? collect(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['path' => 'Cannot queue restore on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'path' => ['required', 'string', 'max:240'],
        ]);
        $path = Files::tryRel($data['path']);
        if ($path === null || $path === '') {
            return back()->withErrors(['path' => 'Invalid restore path. Relative under home, no pipe/path escape.'])->withInput();
        }
        $exists = BackupRestore::query()
            ->where('account_id', $account->id)
            ->where('path', $path)
            ->exists();
        if (! $exists) {
            if (BackupProvisioner::restoreLimitReached($account)) {
                return back()->withErrors(['path' => 'Restore path limit reached (10).']);
            }
            BackupRestore::query()->create([
                'account_id' => $account->id,
                'path' => $path,
            ]);
        }
        BackupProvisioner::enqueueRestore($account);
        $account->recordEvent('backup.restore.queued', $path);
        Audit::log('backup.restore', 'info', 'account', $account->id, ['path' => $path]);

        return redirect()->route('file-restoration.index')->with('success', 'File restoration is queued.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'backupRestores']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
