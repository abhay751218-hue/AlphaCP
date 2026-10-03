<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BackupJob;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\Files;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Backup — job list via paneld backup.create. No tar, no shell, no pipe. */
class BackupController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('backup.index', [
            'account' => $account,
            'rows' => $account?->backupJobs()->orderBy('id')->get() ?? collect(),
            'kinds' => Backup::KINDS,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['kind' => 'Cannot queue backup on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:16'],
            'path' => ['nullable', 'string', 'max:240'],
        ]);
        $kind = Backup::tryKind($data['kind']);
        $pathRaw = (string) ($data['path'] ?? '');
        $path = '';
        if ($kind === null) {
            return back()->withErrors(['kind' => 'Invalid backup. Kind home/mail/mysql. Path only for home, relative, no pipe/path escape.'])->withInput();
        }
        if ($kind !== 'home') {
            if (trim($pathRaw) !== '') {
                return back()->withErrors(['kind' => 'Invalid backup. Kind home/mail/mysql. Path only for home, relative, no pipe/path escape.'])->withInput();
            }
        } else {
            $path = Files::tryRel($pathRaw);
            if ($path === null) {
                return back()->withErrors(['kind' => 'Invalid backup. Kind home/mail/mysql. Path only for home, relative, no pipe/path escape.'])->withInput();
            }
        }
        $exists = BackupJob::query()
            ->where('account_id', $account->id)
            ->where('kind', $kind)
            ->where('path', $path)
            ->exists();
        if (! $exists) {
            if (BackupProvisioner::limitReached($account)) {
                return back()->withErrors(['kind' => 'Backup job limit reached (10).']);
            }
            BackupJob::query()->create([
                'account_id' => $account->id,
                'kind' => $kind,
                'path' => $path,
            ]);
        }
        BackupProvisioner::enqueue($account);
        $account->recordEvent('backup.create.queued', $kind . ($path !== '' ? ':' . $path : ''));
        Audit::log('backup.create', 'info', 'account', $account->id, ['kind' => $kind, 'path' => $path]);

        return redirect()->route('backup.index')->with('success', 'Backup job is queued.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'backupJobs']);
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
