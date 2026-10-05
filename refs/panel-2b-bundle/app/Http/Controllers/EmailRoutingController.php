<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\EmailRoute;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Email Routing — per-domain auto/local/backup/remote via paneld. No Exim rewrite. */
class EmailRoutingController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('email-routing.index', [
            'account' => $account,
            'rows' => $account?->emailRoutes()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'modes' => ['auto', 'local', 'backup', 'remote'],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['domain' => 'Cannot change routing on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'mode' => ['required', 'string', 'max:16'],
        ]);
        $domain = Mail::tryDomain($data['domain']);
        $mode = strtolower(trim($data['mode']));
        $allowed = MailProvisioner::domainsFor($account);
        if ($domain === null || ! in_array($domain, $allowed, true) || ! in_array($mode, ['auto', 'local', 'backup', 'remote'], true)) {
            return back()->withErrors(['domain' => 'Invalid domain/mode. Domain must belong to this account. Mode auto/local/backup/remote.'])->withInput();
        }
        $row = EmailRoute::query()->where('account_id', $account->id)->where('domain', $domain)->first();
        if ($row === null) {
            if (MailProvisioner::routingLimitReached($account)) {
                return back()->withErrors(['domain' => 'Routing limit (50) reached.']);
            }
            EmailRoute::query()->create([
                'account_id' => $account->id,
                'domain' => $domain,
                'mode' => $mode,
            ]);
        } else {
            $row->update(['mode' => $mode]);
        }
        MailProvisioner::enqueueRouting($account);
        $account->recordEvent('mail.routing.queued', $domain . ':' . $mode);
        Audit::log('mail.routing', 'info', 'account', $account->id, ['domain' => $domain, 'mode' => $mode]);

        return redirect()->route('email-routing.index')->with('success', 'Email routing is queued.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'emailRoutes']);
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
