<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Mailbox;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Email Accounts — virtual mailboxes via paneld mail.set. */
class MailController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('email.index', [
            'account' => $account,
            'boxes' => $account?->mailboxes()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'maxPop' => $account?->package?->formatLimit('MAXPOP') ?? '—',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Suspended/terminated account par email nahi.']);
        }
        if (MailProvisioner::limitReached($account)) {
            return back()->withErrors(['localpart' => 'Package MAXPOP limit poori.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'quota_mb' => ['required', 'integer', 'min:-1', 'max:102400'],
        ]);
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $hash = Mail::hashPassword($data['password']);
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $hash === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid mailbox (local/domain) ya password 8–72 chars. Domain is account ka hona chahiye.'])->withInput();
        }
        $exists = Mailbox::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists();
        if ($exists) {
            return back()->withErrors(['localpart' => 'Ye mailbox pehle se hai.'])->withInput();
        }
        Mailbox::query()->create([
            'account_id' => $account->id,
            'localpart' => $local,
            'domain' => $domain,
            'quota_mb' => (int) $data['quota_mb'],
            'password_hash' => $hash,
            'status' => 'pending',
        ]);
        MailProvisioner::enqueue($account);
        $account->recordEvent('mail.set.queued', $local . '@' . $domain);
        Audit::log('mail.add', 'info', 'account', $account->id, ['address' => $local . '@' . $domain]);

        return redirect()->route('email.index')->with('success', 'Mailbox queue me hai.');
    }

    public function destroy(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mailbox->account_id !== $account->id) {
            abort(403);
        }
        $addr = $mailbox->address();
        $mailbox->delete();
        MailProvisioner::enqueue($account);
        Audit::log('mail.remove', 'warning', 'account', $account->id, ['address' => $addr]);

        return redirect()->route('email.index')->with('success', 'Mailbox hataane ke liye queue me hai.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'mailboxes']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'Is login ka hosting account nahi hai.');
        }

        return $account;
    }
}
