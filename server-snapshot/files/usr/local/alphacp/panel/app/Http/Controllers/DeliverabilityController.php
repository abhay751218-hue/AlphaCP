<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Email Deliverability — SPF/DMARC copy records via paneld. No DNS write. */
class DeliverabilityController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $rows = [];
        foreach ($account ? MailProvisioner::domainsFor($account) : [] as $domain) {
            $rows[] = Mail::recordsFor($domain);
        }

        return view('deliverability.index', [
            'account' => $account,
            'rows' => $rows,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['domain' => 'Suspended/terminated account par deliverability nahi.']);
        }
        $data = $request->validate([
            'domain' => ['nullable', 'string', 'max:190'],
        ]);
        $allowed = MailProvisioner::domainsFor($account);
        $want = [];
        if (isset($data['domain']) && $data['domain'] !== '') {
            $domain = Mail::tryDomain($data['domain']);
            if ($domain === null || ! in_array($domain, $allowed, true)) {
                return back()->withErrors(['domain' => 'Domain is account ka hona chahiye.'])->withInput();
            }
            $want = [$domain];
        } else {
            $want = $allowed;
        }
        if ($want === []) {
            return back()->withErrors(['domain' => 'No domains yet.']);
        }
        MailProvisioner::enqueueDeliverability($account, $want);
        $account->recordEvent('mail.deliverability.queued', implode(',', $want));
        Audit::log('mail.deliverability', 'info', 'account', $account->id, ['domains' => $want]);

        return redirect()->route('deliverability.index')->with('success', 'Deliverability records queue me hain. DNS Zone Editor S9 me likhega.');
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
