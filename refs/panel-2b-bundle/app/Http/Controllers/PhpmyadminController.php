<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PhpmyadminSetting;
use App\Support\Audit;
use App\Support\DatabaseProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel phpMyAdmin — enabled JSON via paneld db.phpmyadmin. No phpMyAdmin install, no SSO. */
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
        ]);
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

        return redirect()->route('phpmyadmin.index')->with('success', 'phpMyAdmin preference is queued.');
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
