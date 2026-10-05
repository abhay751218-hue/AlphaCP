<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Domain;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\PhpIni;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel MultiPHP INI Editor — allowlisted php.ini via paneld `php.setIni`.
 * Per-domain overrides are stored on the domain row and applied to that
 * domain's own FPM pool (docs/09 row 69).
 */
class PhpIniController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('php.ini', [
            'account' => $account,
            'domains' => $account?->domains()
                ->whereIn('type', ['main', 'addon', 'sub'])
                ->orderByRaw("CASE type WHEN 'main' THEN 0 WHEN 'addon' THEN 1 ELSE 2 END")
                ->orderBy('domain')
                ->get() ?? collect(),
            'fields' => PhpIni::FIELDS,
            'current' => is_array($account?->meta['php_ini'] ?? null) ? $account->meta['php_ini'] : [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['ini' => 'Cannot change PHP INI on a suspended/terminated account.']);
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

        return redirect()->route('php.ini')->with('success', 'PHP INI is queued.');
    }

    /** Per-domain INI (empty submit = clear the override, domain uses the account pool). */
    public function updateDomain(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($domain->account_id !== $account->id) {
            abort(403);
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['ini' => 'Cannot change PHP INI on a suspended/terminated account.']);
        }
        if (! in_array($domain->type, ['main', 'addon', 'sub'], true)) {
            return back()->withErrors(['ini' => 'This domain type has no document root to serve PHP.']);
        }

        $directives = PhpIni::fromRequest($request->all());
        $domain->forceFill(['php_ini' => $directives])->save();

        AccountProvisioner::enqueue($account, 'php.setIni', [
            'username' => $account->username,
            'directives' => $directives,
            'domain' => $domain->domain,
            'php_version' => $domain->php_version ?: $account->php_version,
        ]);
        $account->recordEvent('php.setIni.queued', $domain->domain . ' (' . count($directives) . ')');
        Audit::log('php.setIni.domain', 'info', 'domain', $domain->id, [
            'domain' => $domain->domain,
            'keys' => array_keys($directives),
        ]);

        return redirect()->route('php.ini')->with('success', "PHP INI for {$domain->domain} is queued.");
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
