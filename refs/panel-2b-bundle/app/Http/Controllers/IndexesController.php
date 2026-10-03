<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\Indexes;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Indexes — Apache directory listing via paneld indexes.set. */
class IndexesController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $current = is_string($account?->meta['indexes'] ?? null) ? $account->meta['indexes'] : 'off';
        if (! isset(Indexes::MODES[$current])) {
            $current = 'off';
        }

        return view('indexes.index', [
            'account' => $account,
            'modes' => Indexes::MODES,
            'current' => $current,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['mode' => 'Cannot change indexes on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:off,simple,fancy'],
        ]);
        $mode = $data['mode'];
        $meta = $account->meta ?? [];
        $meta['indexes'] = $mode;
        $account->forceFill(['meta' => $meta])->save();

        AccountProvisioner::enqueue($account, 'indexes.set', [
            'username' => $account->username,
            'mode' => $mode,
        ]);
        $account->recordEvent('indexes.set.queued', $mode);
        Audit::log('indexes.set', 'info', 'account', $account->id, ['mode' => $mode]);

        return redirect()->route('indexes.index')->with('success', "Indexes '{$mode}' is queued.");
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
