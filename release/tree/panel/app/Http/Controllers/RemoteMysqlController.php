<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MysqlRemoteHost;
use App\Support\Audit;
use App\Support\DatabaseProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Mysql;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Remote MySQL — access hosts via paneld db.remote. No mysql GRANT. */
class RemoteMysqlController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('remote-mysql.index', [
            'account' => $account,
            'rows' => $account?->remoteHosts()->orderBy('id')->get() ?? collect(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['host' => 'Cannot change Remote MySQL on a suspended/terminated account.']);
        }
        if (DatabaseProvisioner::remoteLimitReached($account)) {
            return back()->withErrors(['host' => 'Remote host limit reached (50).']);
        }
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
        ]);
        $host = Mysql::tryHost($data['host']);
        if ($host === null) {
            return back()->withErrors(['host' => 'Invalid host. Use %, IPv4, or FQDN. No pipe/path.'])->withInput();
        }
        $exists = MysqlRemoteHost::query()->where('account_id', $account->id)->where('host', $host)->exists();
        if ($exists) {
            return back()->withErrors(['host' => 'This host is already allowed.'])->withInput();
        }
        MysqlRemoteHost::query()->create([
            'account_id' => $account->id,
            'host' => $host,
        ]);
        DatabaseProvisioner::enqueueRemote($account);
        $account->recordEvent('db.remote.queued', $host);
        Audit::log('db.remote.add', 'info', 'account', $account->id, ['host' => $host]);

        return redirect()->route('remote-mysql.index')->with('success', 'Remote host is queued.');
    }

    public function destroy(Request $request, MysqlRemoteHost $mysql_remote_host): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mysql_remote_host->account_id !== $account->id) {
            abort(403);
        }
        $host = $mysql_remote_host->host;
        $mysql_remote_host->delete();
        DatabaseProvisioner::enqueueRemote($account);
        Audit::log('db.remote.remove', 'warning', 'account', $account->id, ['host' => $host]);

        return redirect()->route('remote-mysql.index')->with('success', 'Remote host is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'remoteHosts']);
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
