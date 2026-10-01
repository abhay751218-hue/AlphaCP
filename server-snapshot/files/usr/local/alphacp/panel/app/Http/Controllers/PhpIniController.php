<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\PhpIni;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel MultiPHP INI Editor — allowlisted php.ini via paneld php.setIni. */
class PhpIniController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $current = is_array($account?->meta['php_ini'] ?? null) ? $account->meta['php_ini'] : [];

        return view('php.ini', [
            'account' => $account,
            'fields' => PhpIni::FIELDS,
            'current' => $current,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['ini' => 'Suspended/terminated account par INI nahi.']);
        }

        $directives = PhpIni::fromRequest($request->all());
        $meta = $account->meta ?? [];
        $meta['php_ini'] = $directives;
        $account->forceFill(['meta' => $meta])->save();

        AccountProvisioner::enqueue($account, 'php.setIni', [
            'username' => $account->username,
            'directives' => $directives,
        ]);
        $account->recordEvent('php.setIni.queued', (string) count($directives));
        Audit::log('php.setIni', 'info', 'account', $account->id, ['keys' => array_keys($directives)]);

        return redirect()->route('php.ini')->with('success', 'PHP INI queue me hai.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount;
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
