<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MailingList;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Mailing Lists — list address + owner via paneld mail.list. No mailman. */
class MailingListsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('mailing-lists.index', [
            'account' => $account,
            'rows' => $account?->mailingLists()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'maxLst' => $account?->package?->formatLimit('MAXLST') ?? '—',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Suspended/terminated account par list nahi.']);
        }
        if (MailProvisioner::listLimitReached($account)) {
            return back()->withErrors(['localpart' => 'Package MAXLST limit reached.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'owner' => ['required', 'string', 'max:190'],
        ]);
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $owner = Mail::tryDest($data['owner']);
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $owner === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid list/owner. Owner email hona chahiye, pipe nahi. Domain is account ka hona chahiye.'])->withInput();
        }
        $exists = MailingList::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists();
        if ($exists) {
            return back()->withErrors(['localpart' => 'This list already exists.'])->withInput();
        }
        MailingList::query()->create([
            'account_id' => $account->id,
            'localpart' => $local,
            'domain' => $domain,
            'owner' => $owner,
        ]);
        MailProvisioner::enqueueLists($account);
        $account->recordEvent('mail.list.queued', $local . '@' . $domain);
        Audit::log('mail.list', 'info', 'account', $account->id, ['list' => $local . '@' . $domain]);

        return redirect()->route('mailing-lists.index')->with('success', 'Mailing list queue me hai.');
    }

    public function destroy(Request $request, MailingList $mailing_list): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mailing_list->account_id !== $account->id) {
            abort(403);
        }
        $addr = $mailing_list->address();
        $mailing_list->delete();
        MailProvisioner::enqueueLists($account);
        Audit::log('mail.list.remove', 'warning', 'account', $account->id, ['list' => $addr]);

        return redirect()->route('mailing-lists.index')->with('success', 'List hataane ke liye queue me hai.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'mailingLists']);
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
