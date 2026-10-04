<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MysqlDatabase;
use App\Support\Audit;
use App\Support\DatabaseProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Mysql;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel MySQL Database Wizard (S8) — same real `db.create` task, step-by-step UI. */
class MysqlWizardController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $pending = $request->session()->get('mysql_wizard_name');
        $pending = is_string($pending) ? Mysql::tryName($pending) : null;

        return view('mysql-wizard.index', [
            'account' => $account,
            'pending' => $pending,
            'maxSql' => $account?->package?->formatLimit('MAXSQL') ?? '—',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['name' => 'Cannot change databases on a suspended/terminated account.']);
        }
        if ($request->boolean('cancel')) {
            $request->session()->forget('mysql_wizard_name');

            return redirect()->route('mysql-wizard.index');
        }
        if ($request->boolean('confirm')) {
            return $this->commit($request, $account);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:16'],
        ]);
        $name = Mysql::tryName($data['name']);
        if ($name === null) {
            return back()->withErrors(['name' => 'Invalid database name. Letters/numbers/_ , 1–16 chars, no pipe.'])->withInput();
        }
        $exists = MysqlDatabase::query()->where('account_id', $account->id)->where('name', $name)->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'This database already exists.'])->withInput();
        }
        $request->session()->put('mysql_wizard_name', $name);

        return redirect()->route('mysql-wizard.index');
    }

    private function commit(Request $request, Account $account): RedirectResponse
    {
        $raw = $request->session()->pull('mysql_wizard_name');
        $name = is_string($raw) ? Mysql::tryName($raw) : null;
        if ($name === null) {
            return back()->withErrors(['name' => 'Complete wizard step 1 first.']);
        }
        if (DatabaseProvisioner::limitReached($account)) {
            return back()->withErrors(['name' => 'Package MAXSQL limit reached.']);
        }
        $exists = MysqlDatabase::query()->where('account_id', $account->id)->where('name', $name)->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'This database already exists.']);
        }
        $database = MysqlDatabase::query()->create([
            'account_id' => $account->id,
            'name' => $name,
        ]);
        DatabaseProvisioner::enqueueCreate($account, $database);
        $account->recordEvent('db.wizard.queued', $account->username . '_' . $name);
        Audit::log('db.wizard', 'info', 'account', $account->id, ['name' => $account->username . '_' . $name]);

        return redirect()->route('mysql-wizard.index')->with('success', 'Step 4 complete — MariaDB database is queued. Users/privileges MySQL Users page se lagao.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'mysqlDatabases']);
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
