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

/**
 * cPanel MySQL Databases (S8, panel 0.70.0) — real MariaDB via paneld
 * `db.create` / `db.drop` / `db.list`. The panel books the intent, the root
 * agent runs the SQL (socket auth, stdin scripts, no password in argv).
 */
class MysqlDatabasesController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('mysql.index', [
            'account' => $account,
            'rows' => $account?->mysqlDatabases()->orderBy('id')->get() ?? collect(),
            'users' => $account?->mysqlUsers()->with('databases')->orderBy('id')->get() ?? collect(),
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
        if (DatabaseProvisioner::limitReached($account)) {
            return back()->withErrors(['name' => 'Package MAXSQL limit reached.']);
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
        $database = MysqlDatabase::query()->create([
            'account_id' => $account->id,
            'name' => $name,
        ]);
        DatabaseProvisioner::enqueueCreate($account, $database);
        $account->recordEvent('db.queued', $account->username . '_' . $name);
        Audit::log('db.add', 'info', 'account', $account->id, ['name' => $account->username . '_' . $name]);

        return redirect()->route('mysql.index')->with('success', 'MariaDB database is queued.');
    }

    public function destroy(Request $request, MysqlDatabase $mysql_database): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mysql_database->account_id !== $account->id) {
            abort(403);
        }
        $full = $account->username . '_' . $mysql_database->name;
        foreach ($account->mysqlUsers()->with('databases')->get() as $user) {
            $user->databases()->detach($mysql_database->id);
        }
        DatabaseProvisioner::enqueueDrop($account, $mysql_database->name);
        $mysql_database->delete();
        Audit::log('db.remove', 'warning', 'account', $account->id, ['name' => $full]);

        return redirect()->route('mysql.index')->with('success', 'MariaDB database drop is queued (privileges revoke honge).');
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
