<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\WebmailSetting;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel Webmail — client preference + one-click SSO open (Roundcube :2096).
 *
 * D9: ab har mailbox ke saath apna "Open" button — jo mailbox chuno usi se
 * Roundcube login hota hai (pehle sirf pehla mailbox khulta tha).
 */
class WebmailController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $row = $account?->webmailSetting;

        $mailboxes = collect();
        if ($account !== null) {
            $mailboxes = $account->mailboxes()->orderBy('domain')->orderBy('localpart')->get();
        }

        return view('webmail.index', [
            'account' => $account,
            'enabled' => (bool) ($row?->enabled ?? false),
            'client' => (string) ($row?->client ?? 'roundcube'),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
            'mailboxes' => $mailboxes,
            'webmailPort' => (int) config('acp.webmail_port', 2096),
        ]);
    }

    /**
     * cPanel-style "Open Webmail": one-time SSO token (10 min) banao aur
     * Roundcube (port webmail_port) par bhejo; plugin token verify kar ke
     * Dovecot master-user se seamless login karta hai.
     */
    public function open(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'mailbox_id' => ['nullable', 'integer'],
        ]);

        $query = $account->mailboxes()->orderBy('id');
        if (! empty($data['mailbox_id'])) {
            $query = $account->mailboxes()->whereKey((int) $data['mailbox_id']);
        }
        $mailbox = $query->first()?->address();

        if ($mailbox === null || $mailbox === '') {
            return back()->withErrors(['webmail' => 'Koi mailbox nahi mila — pehle Email Accounts me ek account banayein.']);
        }

        $token = bin2hex(random_bytes(32));
        \Illuminate\Support\Facades\DB::table('webmail_sso_tokens')->insert([
            'token'      => $token,
            'user_id'    => (int) $request->user()->id,
            'mailbox'    => $mailbox,
            'used'       => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $port = (int) config('acp.webmail_port', 2096);

        return redirect()->away('https://' . $request->getHost() . ':' . $port . '/?_acp_token=' . $token);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['client' => 'Cannot change Webmail on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'enabled' => ['nullable', 'in:0,1'],
            'client' => ['required', 'string', 'max:16'],
        ]);
        $client = Mail::tryClient($data['client']);
        if ($client === null) {
            return back()->withErrors(['client' => 'Client must be roundcube or horde, no pipe.'])->withInput();
        }
        $row = WebmailSetting::query()->firstOrNew(['account_id' => $account->id]);
        $row->enabled = ($data['enabled'] ?? '0') === '1';
        $row->client = $client;
        $row->save();
        MailProvisioner::enqueueWebmail($account);
        $account->recordEvent('mail.webmail.queued', $row->enabled ? $client : 'off');
        Audit::log('mail.webmail', 'info', 'account', $account->id, ['enabled' => $row->enabled, 'client' => $client]);

        return redirect()->route('webmail.index')->with('success', 'Webmail preference saved.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'webmailSetting']);
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
