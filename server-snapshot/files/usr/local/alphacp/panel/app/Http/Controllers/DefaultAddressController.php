<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Catchall;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Default Address — catch-all via paneld mail.catchall. No pipes. */
class DefaultAddressController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('default-address.index', [
            'account' => $account,
            'rows' => $account?->catchalls()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['domain' => 'Cannot change default address on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'dest' => ['required', 'string', 'max:190'],
        ]);
        $domain = Mail::tryDomain($data['domain']);
        $dest = Mail::tryDest($data['dest']);
        $allowed = MailProvisioner::domainsFor($account);
        if ($domain === null || $dest === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['dest' => 'Invalid domain/dest. Dest must be an email, no pipe. Domain must belong to this account.'])->withInput();
        }
        Catchall::query()->updateOrCreate(
            ['account_id' => $account->id, 'domain' => $domain],
            ['dest' => $dest],
        );
        MailProvisioner::enqueueCatchalls($account);
        $account->recordEvent('mail.catchall.queued', '*@' . $domain);
        Audit::log('mail.catchall', 'info', 'account', $account->id, ['domain' => $domain, 'dest' => $dest]);

        return redirect()->route('default-address.index')->with('success', 'Default address is queued.');
    }

    public function destroy(Request $request, Catchall $catchall): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($catchall->account_id !== $account->id) {
            abort(403);
        }
        $src = $catchall->source();
        $catchall->delete();
        MailProvisioner::enqueueCatchalls($account);
        Audit::log('mail.catchall.remove', 'warning', 'account', $account->id, ['source' => $src]);

        return redirect()->route('default-address.index')->with('success', 'Default address is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'catchalls']);
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
