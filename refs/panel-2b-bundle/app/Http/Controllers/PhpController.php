<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\PhpVersions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel MultiPHP Manager — per-account PHP version (pool + vhost socket). */
class PhpController extends Controller
{
    public function index(Request $request): View
    {
        return view('php.index', [
            'account' => $this->accountFor($request),
            'versions' => PhpVersions::all(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['php_version' => 'Suspended/terminated account par PHP nahi badlega.']);
        }
        $data = $request->validate([
            'php_version' => ['required', 'string', 'regex:' . PhpVersions::pattern()],
        ]);
        $php = $data['php_version'];
        if (! in_array($php, PhpVersions::all(), true)) {
            return back()->withErrors(['php_version' => 'Ye PHP version is server par nahi hai.']);
        }

        $account->forceFill(['php_version' => $php])->save();
        $account->domains()->where('type', 'main')->update(['php_version' => $php]);
        AccountProvisioner::enqueue($account, 'php.setVersion', [
            'username' => $account->username,
            'php_version' => $php,
        ]);
        $account->recordEvent('php.setVersion.queued', $php);
        Audit::log('php.setVersion', 'info', 'account', $account->id, ['php_version' => $php]);

        return redirect()->route('php.index')->with('success', "PHP {$php} queue me hai.");
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
            abort(403, 'Is login ka hosting account nahi hai.');
        }
        return $account;
    }
}
