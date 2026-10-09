<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PhpmyadminSetting;
use App\Support\Audit;
use App\Support\DatabaseProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * cPanel phpMyAdmin — D12: asli phpMyAdmin app (port acp.pma_port) + one-click
 * SSO. Open dabate hi agent `db.pmaSignon` se pma_<account> ka password rotate
 * hota hai, encrypted one-time token file banti hai (10 min), aur browser
 * /acp-signon.php shim par jata hai jo signon session bana kar login kara deta hai.
 */
class PhpmyadminController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $row = $account?->phpmyadminSetting;

        return view('phpmyadmin.index', [
            'account' => $account,
            'enabled' => (bool) ($row?->enabled ?? false),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
            'ssoReady' => in_array('db.pmaSignon', Paneld::taskTypes(), true),
            'pmaPort' => (int) config('acp.pma_port', 2098),
        ]);
    }

    /** D12 — cPanel-style one-click login. */
    public function open(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['enabled' => 'Suspended/terminated account par phpMyAdmin nahi khulega.']);
        }
        if (! (bool) ($account->phpmyadminSetting?->enabled ?? false)) {
            return back()->withErrors(['enabled' => 'phpMyAdmin access OFF hai — pehle neeche Enable karke Save karo.']);
        }
        if (! in_array('db.pmaSignon', Paneld::taskTypes(), true)) {
            return back()->withErrors(['enabled' => 'Agent par db.pmaSignon task nahi — paneld update/restart chahiye.']);
        }

        $res = Paneld::run('db.pmaSignon', ['username' => $account->username], 35);
        if (! is_array($res) || ($res['user'] ?? '') === '' || ($res['password'] ?? '') === '') {
            return back()->withErrors(['enabled' => 'SSO credentials nahi bane — agent log dekho (Task Queue Monitor).']);
        }

        // purani token files (1 ghanta+) saaf karo
        foreach (Storage::files('pma-sso') as $file) {
            if (Storage::lastModified($file) < time() - 3600) {
                Storage::delete($file);
            }
        }

        $token = bin2hex(random_bytes(32));
        Storage::put('pma-sso/' . $token . '.json', (string) json_encode([
            'user'     => (string) $res['user'],
            'password' => Crypt::encryptString((string) $res['password']),
            'expires'  => time() + 600,
        ]));

        Audit::log('db.pma.sso', 'info', 'account', $account->id, ['user' => (string) $res['user']]);
        $port = (int) config('acp.pma_port', 2098);

        return redirect()->away('https://' . $request->getHost() . ':' . $port . '/acp-signon.php?token=' . $token);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['enabled' => 'Cannot change phpMyAdmin on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'enabled' => ['required', 'in:0,1'],
        ]);
        $row = PhpmyadminSetting::query()->firstOrNew(['account_id' => $account->id]);
        $row->enabled = $data['enabled'] === '1';
        $row->save();
        DatabaseProvisioner::enqueuePhpmyadmin($account);
        $account->recordEvent('db.phpmyadmin.queued', $row->enabled ? 'on' : 'off');
        Audit::log('db.phpmyadmin', 'info', 'account', $account->id, ['enabled' => $row->enabled]);

        return redirect()->route('phpmyadmin.index')->with('success', 'phpMyAdmin preference saved.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'phpmyadminSetting']);
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
