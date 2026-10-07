<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FtpAccount;
use App\Support\Audit;
use App\Support\Ftp;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel FTP Accounts — Pure-FTPd virtual users, one chroot home per FTP login. */
final class FtpController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $rows    = $account !== null
            ? FtpAccount::query()->where('account_id', $account->id)->orderBy('username')->get()
            : collect();

        return view('ftp.index', [
            'account'   => $account,
            'rows'      => $rows,
            'enabled'   => Ftp::enabled(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['username' => 'Cannot manage FTP on a suspended/terminated account.']);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'quota_mb' => ['nullable', 'integer', 'min:0', 'max:102400'],
        ]);

        $login = strtolower($account->username) . '_' . strtolower($data['username']);
        if (FtpAccount::query()->where('username', $login)->exists()) {
            return back()->withErrors(['username' => 'FTP login already exists.'])->withInput();
        }

        $home = rtrim($account->home_path, '/') . '/ftp/' . strtolower($data['username']);

        FtpAccount::query()->create([
            'account_id' => $account->id,
            'username'   => $login,
            'home_path'  => $home,
            'quota_mb'   => (int) ($data['quota_mb'] ?? 0),
            'status'     => 'active',
        ]);

        Ftp::addUser($login, $data['password'], $home);
        Audit::log('ftp.add', 'info', 'account', $account->id, ['user' => $login]);

        return redirect()->route('ftp.index')->with('success', 'FTP account created.');
    }

    public function password(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $ftpAccount->account_id !== (int) $account->id) {
            abort(404);
        }

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:72']]);

        Ftp::passwd($ftpAccount->username, $data['password']);
        Audit::log('ftp.passwd', 'info', 'account', $account->id, ['user' => $ftpAccount->username]);

        return redirect()->route('ftp.index')->with('success', 'FTP password changed.');
    }

    public function destroy(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $ftpAccount->account_id !== (int) $account->id) {
            abort(404);
        }

        Ftp::delUser($ftpAccount->username);
        $ftpAccount->delete();
        Audit::log('ftp.del', 'info', 'account', $account->id, ['user' => $ftpAccount->username]);

        return redirect()->route('ftp.index')->with('success', 'FTP account removed.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
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
