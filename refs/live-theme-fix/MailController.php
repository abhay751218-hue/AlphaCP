<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Mailbox;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel Email Accounts — virtual mailboxes via paneld mail.set.
 * D1 (depth parity): quota/password edit (email.update), per-box disk usage
 * (mail.usage), Connect Devices data, cPanel-style index.
 */
class MailController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $boxes = $account?->mailboxes()->orderBy('id')->get() ?? collect();

        return view('email.index', [
            'account' => $account,
            'boxes' => $boxes,
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'maxPop' => $account?->package?->formatLimit('MAXPOP') ?? '—',
            'usage' => $this->usageFor($account, $boxes),
            'mailHost' => $this->mailHost($request),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Cannot change email on a suspended/terminated account.']);
        }
        if (MailProvisioner::limitReached($account)) {
            return back()->withErrors(['localpart' => 'Package MAXPOP limit reached.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'quota_mode' => ['nullable', 'string', 'in:limited,unlimited'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:102400'],
        ]);
        $quota = ($data['quota_mode'] ?? 'limited') === 'unlimited' ? -1 : (int) ($data['quota_mb'] ?? 1024);
        if ($quota === 0) {
            $quota = 1024;
        }
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $hash = Mail::hashPassword($data['password']);
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $hash === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid mailbox (local/domain) ya password 8–72 chars. Domain must belong to this account.'])->withInput();
        }
        $exists = Mailbox::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists();
        if ($exists) {
            return back()->withErrors(['localpart' => 'This mailbox already exists.'])->withInput();
        }
        Mailbox::query()->create([
            'account_id' => $account->id,
            'localpart' => $local,
            'domain' => $domain,
            'quota_mb' => $quota,
            'password_hash' => $hash,
            'status' => 'pending',
        ]);
        MailProvisioner::enqueue($account);
        $account->recordEvent('mail.set.queued', $local . '@' . $domain);
        Audit::log('mail.add', 'info', 'account', $account->id, ['address' => $local . '@' . $domain]);

        return redirect()->route('email.index')->with('success', 'Mailbox is queued.');
    }

    /** D1: quota change + optional password change — same declarative mail.set sync. */
    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($mailbox->account_id !== $account->id) {
            abort(403);
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['quota_mb' => 'Cannot change email on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'quota_mode' => ['required', 'string', 'in:limited,unlimited'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:102400'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
        ]);
        $quota = $data['quota_mode'] === 'unlimited' ? -1 : (int) ($data['quota_mb'] ?? 0);
        if ($quota === 0) {
            return back()->withErrors(['quota_mb' => 'Storage MB required (ya Unlimited chuno).']);
        }
        $changes = [];
        if ((int) $mailbox->quota_mb !== $quota) {
            $mailbox->quota_mb = $quota;
            $changes[] = 'quota';
        }
        if (! empty($data['password'])) {
            $hash = Mail::hashPassword($data['password']);
            if ($hash === null) {
                return back()->withErrors(['password' => 'Password 8–72 chars.']);
            }
            $mailbox->password_hash = $hash;
            $changes[] = 'password';
        }
        if ($changes === []) {
            return redirect()->route('email.index')->with('info', 'No changes.');
        }
        $mailbox->status = 'pending';
        $mailbox->save();
        MailProvisioner::enqueue($account);
        $account->recordEvent('mail.set.queued', $mailbox->address());
        Audit::log('mail.update', 'info', 'account', $account->id, [
            'address' => $mailbox->address(),
            'changed' => implode(',', $changes),
        ]);

        return redirect()->route('email.index')->with('success', 'Mailbox update is queued (' . implode(' + ', $changes) . ').');
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

        return redirect()->route('email.index')->with('success', 'Mailbox is queued for removal.');
    }

    /**
     * D1: per-mailbox disk usage (MB) via readonly mail.usage — ek call per
     * distinct domain. Agent slow/down ho to page kabhi na toote (null = '—').
     *
     * @param \Illuminate\Support\Collection<int, Mailbox>|null $boxes
     * @return array<string, int> address => used MB
     */
    private function usageFor(?Account $account, $boxes): array
    {
        if ($account === null || $boxes === null || $boxes->isEmpty() || $boxes->count() > 30 || app()->environment('testing')) {
            return [];
        }
        $out = [];
        $domains = $boxes->pluck('domain')->unique()->take(4);
        foreach ($domains as $domain) {
            try {
                $result = Paneld::run('mail.usage', [
                    'username' => $account->username,
                    'path' => (string) $domain,
                ], 6);
            } catch (\Throwable) {
                $result = null;
            }
            if (! is_array($result)) {
                continue;
            }
            foreach (($result['entries'] ?? []) as $row) {
                if (is_array($row) && isset($row['name'])) {
                    $out[$row['name'] . '@' . $domain] = (int) round(((int) ($row['bytes'] ?? 0)) / 1048576);
                }
            }
        }

        return $out;
    }

    /** Connect Devices ke liye mail server host (panel host, port hata kar). */
    private function mailHost(Request $request): string
    {
        $host = (string) $request->getHost();

        return $host !== '' ? $host : 'localhost';
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
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
