<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MysqlDatabase;
use App\Models\MysqlUser;
use App\Support\Audit;
use App\Support\AccountProvisioner;
use App\Support\DatabaseProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Mysql;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * cPanel MySQL Users + "Add User To Database" (S8, panel 0.70.0).
 *
 * Real provisioning: the panel books the user + grants and queues
 * `db.user.create` / `db.user.grant` / `db.user.drop`. The password is generated
 * here, sent to the agent once (stdin SQL, never argv) and shown to the operator
 * exactly once — nothing stores it.
 */
class MysqlUsersController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('mysql-users.index', [
            'account'     => $account,
            'users'       => $account?->mysqlUsers()->with('databases')->orderBy('id')->get() ?? collect(),
            'databases'   => $account?->mysqlDatabases()->orderBy('name')->get() ?? collect(),
            'maxSql'      => $account?->package?->formatLimit('MAXSQL') ?? '—',
            'panelMode'   => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['user' => 'Cannot change database users on a suspended/terminated account.']);
        }
        if (DatabaseProvisioner::userLimitReached($account)) {
            return back()->withErrors(['user' => 'Package MAXSQL limit reached.']);
        }
        $data = $request->validate([
            'user'        => ['required', 'string', 'max:16'],
            'host'        => ['nullable', 'string', 'max:190'],
            'password'    => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{10,64}$/'],
            'databases'   => ['nullable', 'array', 'max:50'],
            'databases.*' => ['integer'],
        ]);
        $name = Mysql::tryName($data['user']);
        if ($name === null) {
            return back()->withErrors(['user' => 'Invalid user name. Letters/numbers/_ , 1–16 chars, no pipe.'])->withInput();
        }
        $host = $this->host((string) ($data['host'] ?? 'localhost'));
        if ($host === null) {
            return back()->withErrors(['host' => 'Invalid host. localhost, IPv4, FQDN or % only.'])->withInput();
        }
        $exists = MysqlUser::query()->where('account_id', $account->id)->where('name', $name)->where('host', $host)->exists();
        if ($exists) {
            return back()->withErrors(['user' => 'This user already exists on that host.'])->withInput();
        }

        $databases = $this->selectedDatabases($account, $data['databases'] ?? []);
        $user = MysqlUser::query()->create([
            'account_id' => $account->id,
            'name'       => $name,
            'host'       => $host,
        ]);
        foreach ($databases as $database) {
            $user->databases()->attach($database->id);
        }

        // D3: user-chosen password allowed (alnum 10-64 only: safe in SQL literals,
        // no quote/backslash) — blank = strong random, shown once either way.
        $password = ($data['password'] ?? '') !== '' ? $data['password'] : Str::random(20);
        DatabaseProvisioner::enqueueUserCreate($account, $user, $password, $databases);
        $account->recordEvent('db.user.queued', $account->username . '_' . $name);
        Audit::log('db.user.add', 'info', 'account', $account->id, [
            'user'      => $account->username . '_' . $name,
            'host'      => $host,
            'databases' => array_map(static fn (MysqlDatabase $database): string => $database->fullName($account->username), $databases),
        ]);

        return redirect()->route('mysql-users.index')
            ->with('success', 'Database user is queued.')
            ->with('mysql_user_password', $password)
            ->with('mysql_user_full', $account->username . '_' . $name . '@' . $host);
    }

    public function grant(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['database' => 'Cannot change privileges on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'mysql_user_id'     => ['required', 'integer'],
            'mysql_database_id' => ['required', 'integer'],
        ]);
        $user = MysqlUser::query()->where('account_id', $account->id)->findOrFail($data['mysql_user_id']);
        $database = MysqlDatabase::query()->where('account_id', $account->id)->findOrFail($data['mysql_database_id']);

        if ($user->databases()->whereKey($database->id)->exists()) {
            return back()->withErrors(['database' => 'This user already has privileges on that database.']);
        }
        $user->databases()->attach($database->id);
        DatabaseProvisioner::enqueueUserGrant($account, $user, $database);
        $account->recordEvent('db.user.grant.queued', $database->fullName($account->username));
        Audit::log('db.user.grant', 'info', 'account', $account->id, [
            'user'     => $user->fullName($account->username),
            'database' => $database->fullName($account->username),
        ]);

        return redirect()->route('mysql-users.index')->with('success', 'Privileges are queued.');
    }

    /** D14 — grant ka ulta: Revoke privileges on one database. */
    public function revoke(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['database' => 'Cannot change privileges on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'mysql_user_id'     => ['required', 'integer'],
            'mysql_database_id' => ['required', 'integer'],
        ]);
        $user = MysqlUser::query()->where('account_id', $account->id)->findOrFail($data['mysql_user_id']);
        $database = MysqlDatabase::query()->where('account_id', $account->id)->findOrFail($data['mysql_database_id']);

        if (! $user->databases()->whereKey($database->id)->exists()) {
            return back()->withErrors(['database' => 'This user has no privileges on that database.']);
        }
        $user->databases()->detach($database->id);
        AccountProvisioner::enqueue($account, 'db.user.revoke', [
            'username' => $account->username,
            'user'     => $user->name,
            'host'     => $user->host,
            'database' => $database->name,
        ]);
        $account->recordEvent('db.user.revoke.queued', $database->fullName($account->username));
        Audit::log('db.user.revoke', 'warning', 'account', $account->id, [
            'user'     => $user->fullName($account->username),
            'database' => $database->fullName($account->username),
        ]);

        return redirect()->route('mysql-users.index')->with('success', 'Revoke is queued.');
    }

    /** cPanel "Change Password" — new password, shown once, never stored. */
    public function password(Request $request, MysqlUser $mysql_user): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mysql_user->account_id !== $account->id) {
            abort(403);
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['user' => 'Cannot change passwords on a suspended/terminated account.']);
        }

        $data = $request->validate([
            'password' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{10,64}$/'],
        ]);
        $password = ($data['password'] ?? '') !== '' ? $data['password'] : Str::random(20);
        DatabaseProvisioner::enqueueUserPassword($account, $mysql_user, $password);
        Audit::log('db.user.password', 'info', 'account', $account->id, [
            'user' => $mysql_user->fullName($account->username) . '@' . $mysql_user->host,
        ]);

        return redirect()->route('mysql-users.index')
            ->with('success', 'Password change is queued.')
            ->with('mysql_user_password', $password)
            ->with('mysql_user_full', $mysql_user->fullName($account->username) . '@' . $mysql_user->host);
    }

    public function destroy(Request $request, MysqlUser $mysql_user): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mysql_user->account_id !== $account->id) {
            abort(403);
        }
        $full = $mysql_user->fullName($account->username) . '@' . $mysql_user->host;
        $mysql_user->databases()->detach();
        DatabaseProvisioner::enqueueUserDrop($account, $mysql_user);
        $mysql_user->delete();
        Audit::log('db.user.remove', 'warning', 'account', $account->id, ['user' => $full]);

        return redirect()->route('mysql-users.index')->with('success', 'Database user is queued for removal.');
    }

    /** @param list<int> $ids @return list<MysqlDatabase> */
    private function selectedDatabases(Account $account, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return MysqlDatabase::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function host(string $raw): ?string
    {
        $host = strtolower(trim($raw));
        if ($host === '') {
            $host = 'localhost';
        }
        if ($host === 'localhost' || $host === '%') {
            return $host;
        }
        if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/', $host) === 1) {
            return $host;
        }
        if (str_contains($host, '/') || str_contains($host, '|') || str_contains($host, ' ') || str_contains($host, '..')) {
            return null;
        }
        if (substr_count($host, '.') < 1 || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'mysqlDatabases', 'mysqlUsers']);
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
