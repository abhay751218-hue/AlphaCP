<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Domain;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\PhpVersions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel MultiPHP Manager — per-account PHP version plus per-domain overrides
 * (docs/09 row 68). Privileged pool/vhost work is always paneld `php.setVersion`.
 */
class PhpController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('php.index', [
            'account' => $account,
            'domains' => $account?->domains()
                ->orderByRaw("CASE type WHEN 'main' THEN 0 WHEN 'addon' THEN 1 WHEN 'sub' THEN 2 ELSE 3 END")
                ->orderBy('domain')
                ->get() ?? collect(),
            'versions' => PhpVersions::all(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $read = $this->readVersion($request, $account);
        if ($read['error'] !== null) {
            return back()->withErrors(['php_version' => $read['error']]);
        }
        $php = (string) $read['version'];

        $account->forceFill(['php_version' => $php])->save();
        $account->domains()->update(['php_version' => $php]);
        AccountProvisioner::enqueue($account, 'php.setVersion', [
            'username' => $account->username,
            'php_version' => $php,
        ]);
        $account->recordEvent('php.setVersion.queued', $php);
        Audit::log('php.setVersion', 'info', 'account', $account->id, ['php_version' => $php]);

        return redirect()->route('php.index')->with('success', "PHP {$php} is queued for the whole account.");
    }

    /** Per-domain override (cPanel: MultiPHP Manager → per-domain dropdown). */
    public function updateDomain(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($domain->account_id !== $account->id) {
            abort(403);
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['php_version' => 'Cannot change PHP on a suspended/terminated account.']);
        }
        if (! in_array($domain->type, ['main', 'addon', 'sub'], true)) {
            return back()->withErrors(['php_version' => 'This domain type has no document root to serve PHP.']);
        }

        $read = $this->readVersion($request, $account);
        if ($read['error'] !== null) {
            return back()->withErrors(['php_version' => $read['error']]);
        }
        $php = (string) $read['version'];

        $domain->forceFill(['php_version' => $php])->save();

        AccountProvisioner::enqueue($account, 'php.setVersion', [
            'username' => $account->username,
            'php_version' => $php,
            'domain' => $domain->domain,
        ]);
        $account->recordEvent('php.setVersion.queued', $domain->domain . '=' . $php);
        Audit::log('php.setVersion.domain', 'info', 'domain', $domain->id, [
            'domain' => $domain->domain,
            'php_version' => $php,
        ]);

        return redirect()->route('php.index')->with('success', "PHP {$php} is queued for {$domain->domain}.");
    }

    /**
     * @return array{version: ?string, error: ?string}
     */
    private function readVersion(Request $request, Account $account): array
    {
        if ($account->isTerminated() || $account->isSuspended()) {
            return ['version' => null, 'error' => 'Cannot change PHP on a suspended/terminated account.'];
        }
        $data = $request->validate([
            'php_version' => ['required', 'string', 'regex:' . PhpVersions::pattern()],
        ]);
        $php = (string) $data['php_version'];
        if (! in_array($php, PhpVersions::all(), true)) {
            return ['version' => null, 'error' => 'This PHP version is not on this server.'];
        }

        return ['version' => $php, 'error' => null];
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
