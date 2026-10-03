<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BackupWizard;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Backup Wizard — action/scope via paneld backup.wizard. No tar, no shell, no pipe. */
class BackupWizardController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('backup-wizard.index', [
            'account' => $account,
            'row' => $account?->backupWizard,
            'actions' => Backup::ACTIONS,
            'scopes' => Backup::SCOPES,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['action' => 'Cannot queue backup wizard on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'action' => ['required', 'string', 'max:16'],
            'scope' => ['required', 'string', 'max:16'],
        ]);
        $action = Backup::tryAction($data['action']);
        $scope = Backup::tryScope($data['scope']);
        if ($action === null || $scope === null) {
            return back()->withErrors(['action' => 'Invalid wizard. Action backup/restore. Scope full/home/mail/mysql. No pipe/path.'])->withInput();
        }
        $row = $account->backupWizard;
        if ($row === null) {
            BackupWizard::query()->create([
                'account_id' => $account->id,
                'action' => $action,
                'scope' => $scope,
            ]);
        } else {
            $row->update([
                'action' => $action,
                'scope' => $scope,
            ]);
        }
        BackupProvisioner::enqueueWizard($account);
        $account->recordEvent('backup.wizard.queued', $action . ':' . $scope);
        Audit::log('backup.wizard', 'info', 'account', $account->id, ['action' => $action, 'scope' => $scope]);

        return redirect()->route('backup-wizard.index')->with('success', 'Backup wizard is queued.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'backupWizard']);
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
