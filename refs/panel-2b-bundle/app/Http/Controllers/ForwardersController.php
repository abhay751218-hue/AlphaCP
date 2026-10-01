<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Forwarder;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Forwarders — address → address via paneld mail.forward. No pipes. */
class ForwardersController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('forwarders.index', [
            'account' => $account,
            'rows' => $account?->forwarders()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'maxFwd' => $account?->package?->formatLimit('MAXFWD') ?? '—',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Cannot change forwarders on a suspended/terminated account.']);
        }
        if (MailProvisioner::fwdLimitReached($account)) {
            return back()->withErrors(['localpart' => 'Package MAXFWD limit reached.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'dest' => ['required', 'string', 'max:190'],
        ]);
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $dest = Mail::tryDest($data['dest']);
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $dest === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid source/dest. Dest must be an email, no pipe. Domain must belong to this account.'])->withInput();
        }
        if ($local . '@' . $domain === $dest) {
            return back()->withErrors(['dest' => 'Dest source jaisa nahi ho sakta.'])->withInput();
        }
        $exists = Forwarder::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists();
        if ($exists) {
            return back()->withErrors(['localpart' => 'Is address ka forwarder already exists.'])->withInput();
        }
        Forwarder::query()->create([
            'account_id' => $account->id,
            'localpart' => $local,
            'domain' => $domain,
            'dest' => $dest,
        ]);
        MailProvisioner::enqueueForwards($account);
        $account->recordEvent('mail.forward.queued', $local . '@' . $domain);
        Audit::log('mail.forward', 'info', 'account', $account->id, ['source' => $local . '@' . $domain, 'dest' => $dest]);

        return redirect()->route('forwarders.index')->with('success', 'Forwarder is queued.');
    }

    public function destroy(Request $request, Forwarder $forwarder): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($forwarder->account_id !== $account->id) {
            abort(403);
        }
        $src = $forwarder->source();
        $forwarder->delete();
        MailProvisioner::enqueueForwards($account);
        Audit::log('mail.forward.remove', 'warning', 'account', $account->id, ['source' => $src]);

        return redirect()->route('forwarders.index')->with('success', 'Forwarder is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'forwarders']);
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
