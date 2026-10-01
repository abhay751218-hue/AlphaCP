<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\ErrorPages;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Error Pages — custom 4xx/5xx HTML via paneld errorpages.set. */
class ErrorPagesController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $current = is_array($account?->meta['error_pages'] ?? null) ? $account->meta['error_pages'] : [];

        return view('errorpages.index', [
            'account' => $account,
            'codes' => ErrorPages::CODES,
            'current' => $current,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['pages' => 'Suspended/terminated account par error pages nahi.']);
        }

        $pages = ErrorPages::fromRequest($request->all());
        $meta = $account->meta ?? [];
        $meta['error_pages'] = $pages;
        $account->forceFill(['meta' => $meta])->save();

        AccountProvisioner::enqueue($account, 'errorpages.set', [
            'username' => $account->username,
            'pages' => $pages,
        ]);
        $account->recordEvent('errorpages.set.queued', (string) count($pages));
        Audit::log('errorpages.set', 'info', 'account', $account->id, ['codes' => array_keys($pages)]);

        return redirect()->route('errorpages.index')->with('success', 'Error pages queue me hain.');
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
