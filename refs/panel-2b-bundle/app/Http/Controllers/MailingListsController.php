<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\MailingList;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

/** Customer email distribution lists; Exim expands each list to static subscribers. */
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
            'maxMembers' => Mail::MAX_LIST_MEMBERS,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Cannot change lists on a suspended/terminated account.']);
        }
        if (MailProvisioner::listLimitReached($account)) {
            return back()->withErrors(['localpart' => 'Package MAXLST limit reached.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'owner' => ['required', 'string', 'max:190'],
            'members' => ['required', 'string', 'max:50000'],
        ]);
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $owner = Mail::tryDest($data['owner']);
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $owner === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid list/owner. Owner must be an email, no pipe. Domain must belong to this account.'])->withInput();
        }
        $address = $local . '@' . $domain;
        $members = $this->parseMembers($data['members']);
        if (in_array($address, $members, true)) {
            return back()->withErrors(['members' => 'A mailing list cannot subscribe to itself.'])->withInput();
        }
        if ($this->addressUsedByMailboxOrForwarder($account, $local, $domain)) {
            return back()->withErrors(['localpart' => 'This address is already a mailbox or forwarder.'])->withInput();
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
            'members' => $members,
        ]);
        MailProvisioner::enqueueLists($account);
        $account->recordEvent('mail.list.queued', $address);
        Audit::log('mail.list', 'info', 'account', $account->id, ['list' => $address, 'subscribers' => count($members)]);

        return redirect()->route('mailing-lists.index')->with('success', 'Distribution list is queued for Exim.');
    }

    public function update(Request $request, MailingList $mailing_list): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mailing_list->account_id !== $account->id) {
            abort(403);
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['members' => 'Cannot change lists on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'owner' => ['required', 'string', 'max:190'],
            'members' => ['required', 'string', 'max:50000'],
        ]);
        $owner = Mail::tryDest($data['owner']);
        if ($owner === null) {
            return back()->withErrors(['owner' => 'Owner must be a valid email address (no pipe or shell).'])->withInput();
        }
        $members = $this->parseMembers($data['members']);
        if ($this->addressUsedByMailboxOrForwarder($account, $mailing_list->localpart, $mailing_list->domain)) {
            return back()->withErrors(['members' => 'This list address now conflicts with a mailbox or forwarder.'])->withInput();
        }
        if (in_array($mailing_list->address(), $members, true)) {
            return back()->withErrors(['members' => 'A mailing list cannot subscribe to itself.'])->withInput();
        }
        $mailing_list->update(['owner' => $owner, 'members' => $members]);
        MailProvisioner::enqueueLists($account);
        $account->recordEvent('mail.list.updated', $mailing_list->address());
        Audit::log('mail.list.update', 'info', 'account', $account->id, [
            'list' => $mailing_list->address(),
            'subscribers' => count($members),
        ]);

        return redirect()->route('mailing-lists.index')->with('success', 'Subscribers are queued for Exim.');
    }

    public function destroy(Request $request, MailingList $mailing_list): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mailing_list->account_id !== $account->id) {
            abort(403);
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Cannot change lists on a suspended/terminated account.']);
        }
        $addr = $mailing_list->address();
        $mailing_list->delete();
        MailProvisioner::enqueueLists($account);
        Audit::log('mail.list.remove', 'warning', 'account', $account->id, ['list' => $addr]);

        return redirect()->route('mailing-lists.index')->with('success', 'List is queued for removal.');
    }

    /** @return list<string> */
    private function parseMembers(string $raw): array
    {
        $members = [];
        foreach (preg_split('/[,;\r\n]+/', $raw) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $email = Mail::tryDest($candidate);
            if ($email === null) {
                throw ValidationException::withMessages([
                    'members' => 'Each subscriber must be a valid email address; shell commands/pipes are not allowed.',
                ]);
            }
            $members[$email] = $email;
            if (count($members) > Mail::MAX_LIST_MEMBERS) {
                throw ValidationException::withMessages([
                    'members' => 'A list can have at most ' . Mail::MAX_LIST_MEMBERS . ' subscribers.',
                ]);
            }
        }
        if ($members === []) {
            throw ValidationException::withMessages(['members' => 'Add at least one subscriber address.']);
        }
        ksort($members);

        return array_values($members);
    }

    private function addressUsedByMailboxOrForwarder(Account $account, string $local, string $domain): bool
    {
        return Mailbox::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists()
            || Forwarder::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists();
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
