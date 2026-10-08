<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WebDiskAccount;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Web Disk" — WebDAV accounts (read-only / read-write).
 *
 * B3: provisioning ab ROOT AGENT karta hai (`webdisk.list` / `webdisk.create` /
 * `webdisk.delete`) — digest credential file + managed Apache DAV conf + vhost
 * include + reload. Pehle sirf DB row likhi jati thi (koi asli WebDAV nahi).
 * DB table sirf ownership/permissions cache hai; password DB me kabhi nahi jata.
 */
final class WebDiskController extends Controller
{
    public function index(Request $request): View
    {
        $account  = $this->accountFor($request);
        $accounts = [];
        $error    = null;

        if ($account !== null && in_array('webdisk.list', Paneld::taskTypes(), true)) {
            $res = Paneld::run('webdisk.list', ['account' => $account->username], 15);
            if ($res === null) {
                $error = 'Web Disk list agent se nahi mili (task fail/timeout) — agent logs dekhein.';
            } else {
                $accounts = is_array($res['accounts'] ?? null) ? $res['accounts'] : [];
            }
        } else {
            $error = 'Agent par webdisk tasks available nahi (agent update zaroori hai).';
        }

        return view('webdisk.index', [
            'account'  => $account,
            'accounts' => $accounts,
            'error'    => $error,
            'realm'    => 'AlphaCP-WebDisk',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login'       => 'required|string|regex:/^[a-z0-9._-]+$/i|max:60',
            'permissions' => 'required|in:ro,rw',
            'password'    => 'required|string|min:8|max:128',
        ]);

        $account = $this->accountFor($request);
        if ($account === null || !in_array('webdisk.create', Paneld::taskTypes(), true)) {
            return back()->with('error', 'Web Disk provisioning ke liye agent update zaroori hai.')->withInput();
        }

        $res = Paneld::run('webdisk.create', [
            'account'     => $account->username,
            'login'       => $data['login'],
            'permissions' => $data['permissions'],
            'password'    => $data['password'],
        ], 30);
        if ($res === null) {
            return back()->with('error', 'Web Disk account agent par create/reset nahi hua (task fail).')->withInput();
        }

        WebDiskAccount::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'login' => $data['login']],
            ['permissions' => $data['permissions']],
        );

        return redirect('/webdisk')->with('success', "Web Disk account '{$data['login']}' provision ho gaya.");
    }

    public function destroy(string $login, Request $request): RedirectResponse
    {
        $account = $this->accountFor($request);
        if ($account === null || !in_array('webdisk.delete', Paneld::taskTypes(), true)) {
            return back()->with('error', 'Web Disk provisioning ke liye agent update zaroori hai.');
        }

        $res = Paneld::run('webdisk.delete', [
            'account' => $account->username,
            'login'   => $login,
        ], 30);
        if ($res === null) {
            return back()->with('error', "Web Disk account '{$login}' agent se delete nahi hua (task fail).");
        }

        WebDiskAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('login', $login)
            ->delete();

        return redirect('/webdisk')->with('success', "Web Disk account '{$login}' remove ho gaya.");
    }

    private function accountFor(Request $request): ?\App\Models\Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount;
    }
}
