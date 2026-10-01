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

/** cPanel Webmail — preferred client via paneld. No Roundcube/Horde install, no SSO. */
class WebmailController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $row = $account?->webmailSetting;

        return view('webmail.index', [
            'account' => $account,
            'enabled' => (bool) ($row?->enabled ?? false),
            'client' => (string) ($row?->client ?? 'roundcube'),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
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

        return redirect()->route('webmail.index')->with('success', 'Webmail is queued.');
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
