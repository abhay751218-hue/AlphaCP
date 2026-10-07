<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\AppInstaller;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** cPanel Site Software — app catalog + WordPress one-click install. */
final class AppsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('apps.index', [
            'account'   => $account,
            'catalog'   => AppInstaller::catalog(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate(['app' => ['required', 'string', 'in:wordpress']]);

        if ($data['app'] === 'wordpress') {
            // B1: download/extract/chown/DB sab root agent karta hai (web-FPM proc_open
            // disabled tha → 500). Panel sirf queue karta hai.
            AccountProvisioner::enqueue($account, 'apps.install', [
                'username'    => $account->username,
                'app'         => 'wordpress',
                'db_password' => Str::random(24),
            ]);
            $account->recordEvent('apps.install.queued', ['app' => 'wordpress']);
            Audit::log('apps.install', 'info', 'account', $account->id, ['app' => 'wordpress']);

            return redirect()->route('apps.index')
                ->with('success', 'WordPress install queue me hai — public_html + DB ban kar task history me dikhega.');
        }

        return back()->withErrors(['app' => 'Ye app abhi available nahi.']);
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
