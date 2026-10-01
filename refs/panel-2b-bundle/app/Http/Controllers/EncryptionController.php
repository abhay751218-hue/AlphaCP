<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\EncryptionKey;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Encryption — GnuPG identity rows via paneld. No gpg, no private key. */
class EncryptionController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('encryption.index', [
            'account' => $account,
            'rows' => $account?->encryptionKeys()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Suspended/terminated account par encryption nahi.']);
        }
        if (MailProvisioner::encryptLimitReached($account)) {
            return back()->withErrors(['localpart' => 'Encryption limit 50 poori.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'comment' => ['required', 'string', 'max:100'],
        ]);
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $comment = Mail::tryNeedle($data['comment']);
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $comment === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid key. Comment me pipe/shell nahi. Domain is account ka hona chahiye.'])->withInput();
        }
        $exists = EncryptionKey::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists();
        if ($exists) {
            return back()->withErrors(['localpart' => 'Ye key pehle se hai.'])->withInput();
        }
        EncryptionKey::query()->create([
            'account_id' => $account->id,
            'localpart' => $local,
            'domain' => $domain,
            'comment' => $comment,
        ]);
        MailProvisioner::enqueueEncrypt($account);
        $account->recordEvent('mail.encrypt.queued', $local . '@' . $domain);
        Audit::log('mail.encrypt', 'info', 'account', $account->id, ['address' => $local . '@' . $domain]);

        return redirect()->route('encryption.index')->with('success', 'Encryption key queue me hai.');
    }

    public function destroy(Request $request, EncryptionKey $encryption_key): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($encryption_key->account_id !== $account->id) {
            abort(403);
        }
        $addr = $encryption_key->address();
        $encryption_key->delete();
        MailProvisioner::enqueueEncrypt($account);
        Audit::log('mail.encrypt.remove', 'warning', 'account', $account->id, ['address' => $addr]);

        return redirect()->route('encryption.index')->with('success', 'Key hataane ke liye queue me hai.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'encryptionKeys']);
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
