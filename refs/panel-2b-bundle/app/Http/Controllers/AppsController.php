<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
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
            $result = AppInstaller::installWordPress($account, Str::random(24));
            Audit::log('apps.install', 'info', 'account', $account->id, ['app' => 'wordpress', 'db' => $result['db']]);

            return redirect()->route('apps.index')
                ->with('success', 'WordPress install ho gaya (DB: ' . $result['db'] . ').');
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
