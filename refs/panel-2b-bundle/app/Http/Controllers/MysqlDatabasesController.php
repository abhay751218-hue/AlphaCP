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

/** cPanel MySQL Databases — prefixed names via paneld db.set. No mysql binary. */
class MysqlDatabasesController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('mysql.index', [
            'account' => $account,
            'rows' => $account?->mysqlDatabases()->orderBy('id')->get() ?? collect(),
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
        if (DatabaseProvisioner::limitReached($account)) {
            return back()->withErrors(['name' => 'Package MAXSQL limit poori.']);
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
        MysqlDatabase::query()->create([
            'account_id' => $account->id,
            'name' => $name,
        ]);
        DatabaseProvisioner::enqueue($account);
        $account->recordEvent('db.set.queued', $account->username . '_' . $name);
        Audit::log('db.add', 'info', 'account', $account->id, ['name' => $account->username . '_' . $name]);

        return redirect()->route('mysql.index')->with('success', 'Database queue me hai.');
    }

    public function destroy(Request $request, MysqlDatabase $mysql_database): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mysql_database->account_id !== $account->id) {
            abort(403);
        }
        $full = $account->username . '_' . $mysql_database->name;
        $mysql_database->delete();
        DatabaseProvisioner::enqueue($account);
        Audit::log('db.remove', 'warning', 'account', $account->id, ['name' => $full]);

        return redirect()->route('mysql.index')->with('success', 'Database hataane ke liye queue me hai.');
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
