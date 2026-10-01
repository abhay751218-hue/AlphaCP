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

/** cPanel Address Importer — CSV mailboxes via existing mail.set. No pipe. */
class AddressImporterController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('address-importer.index', [
            'account' => $account,
            'maxPop' => $account?->package?->formatLimit('MAXPOP') ?? '—',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['csv' => 'Suspended/terminated account par import nahi.']);
        }
        $data = $request->validate([
            'csv' => ['required', 'string', 'max:32000'],
        ]);
        $allowed = MailProvisioner::domainsFor($account);
        $rows = Mail::parseImport($data['csv'], $allowed);
        if ($rows === null) {
            return back()->withErrors(['csv' => 'Invalid CSV. Format: local,domain,password ya email,password. Pipe/shell/foreign domain fail closed.'])->withInput();
        }
        $max = (int) ($account->package?->MAXPOP ?? -1);
        if ($max >= 0 && $account->mailboxes()->count() + count($rows) > $max) {
            return back()->withErrors(['csv' => 'Package MAXPOP limit reached.']);
        }
        foreach ($rows as $row) {
            $exists = Mailbox::query()->where('account_id', $account->id)->where('localpart', $row['local'])->where('domain', $row['domain'])->exists();
            if ($exists) {
                return back()->withErrors(['csv' => 'Duplicate mailbox: ' . $row['local'] . '@' . $row['domain']])->withInput();
            }
        }
        foreach ($rows as $row) {
            $hash = Mail::hashPassword($row['password']);
            if ($hash === null) {
                return back()->withErrors(['csv' => 'Password hash fail.'])->withInput();
            }
            Mailbox::query()->create([
                'account_id' => $account->id,
                'localpart' => $row['local'],
                'domain' => $row['domain'],
                'quota_mb' => $row['quota_mb'],
                'password_hash' => $hash,
                'status' => 'pending',
            ]);
        }
        MailProvisioner::enqueue($account);
        $account->recordEvent('mail.import.queued', (string) count($rows));
        Audit::log('mail.import', 'info', 'account', $account->id, ['count' => count($rows)]);

        return redirect()->route('address-importer.index')->with('success', 'Import queue me hai (mail.set).');
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
