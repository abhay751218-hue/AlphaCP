<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\Waf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Security suite — ModSecurity (WAF) toggle + Virus Scanner (ClamAV). */
final class SecurityToolsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('security-tools.index', [
            'account'    => $account,
            'modsec'     => Waf::modsecEnabled(),
            'panelMode'  => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function toggleModsec(Request $request): RedirectResponse
    {
        $this->requireAccount($request);

        if (Waf::modsecEnabled()) {
            Waf::disableModsec();
            Audit::log('waf.modsec_off', 'warning', 'system', null);

            return redirect()->route('security-tools.index')->with('success', 'ModSecurity (WAF) band ho gaya.');
        }

        Waf::enableModsec();
        Audit::log('waf.modsec_on', 'warning', 'system', null);

        return redirect()->route('security-tools.index')->with('success', 'ModSecurity (WAF) chalu ho gaya.');
    }

    public function scan(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $output = Waf::scan(rtrim($account->home_path, '/') . '/public_html');
        Audit::log('waf.scan', 'info', 'account', $account->id);

        return redirect()->route('security-tools.index')
            ->with('success', 'Virus scan complete.')
            ->with('scan_output', $output);
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
