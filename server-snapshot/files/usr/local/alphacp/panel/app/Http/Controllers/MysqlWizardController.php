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

/** cPanel MySQL Database Wizard — step-by-step name via existing db.set. No mysql binary. */
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
            return back()->withErrors(['name' => 'Suspended/terminated account par database nahi.']);
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
            return back()->withErrors(['name' => 'Invalid database name. Letters/numbers/_ , 1–16 chars, pipe nahi.'])->withInput();
        }
        $exists = MysqlDatabase::query()->where('account_id', $account->id)->where('name', $name)->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'Ye database pehle se hai.'])->withInput();
        }
        $request->session()->put('mysql_wizard_name', $name);

        return redirect()->route('mysql-wizard.index');
    }

    private function commit(Request $request, Account $account): RedirectResponse
    {
        $raw = $request->session()->pull('mysql_wizard_name');
        $name = is_string($raw) ? Mysql::tryName($raw) : null;
        if ($name === null) {
            return back()->withErrors(['name' => 'Wizard step 1 pehle complete karo.']);
        }
        if (DatabaseProvisioner::limitReached($account)) {
            return back()->withErrors(['name' => 'Package MAXSQL limit poori.']);
        }
        $exists = MysqlDatabase::query()->where('account_id', $account->id)->where('name', $name)->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'Ye database pehle se hai.']);
        }
        MysqlDatabase::query()->create([
            'account_id' => $account->id,
            'name' => $name,
        ]);
        DatabaseProvisioner::enqueue($account);
        $account->recordEvent('db.wizard.queued', $account->username . '_' . $name);
        Audit::log('db.wizard', 'info', 'account', $account->id, ['name' => $account->username . '_' . $name]);

        return redirect()->route('mysql-wizard.index')->with('success', 'Database queue me hai (db.set).');
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
            abort(403, 'Is login ka hosting account nahi hai.');
        }

        return $account;
    }
}
