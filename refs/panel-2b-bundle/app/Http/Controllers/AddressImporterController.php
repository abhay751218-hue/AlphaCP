<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Mailbox;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/** cPanel Address Importer — paste/upload CSV via mail.set. Never flash plaintext passwords. */
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
            return back()->withErrors(['csv' => 'Cannot import on a suspended/terminated account.']);
        }

        // Do not use $request->validate() or withInput() here: CSV contains cleartext
        // mailbox passwords, and Laravel's normal invalid-form redirect flashes input.
        $validator = Validator::make($request->all(), [
            'csv' => ['nullable', 'string', 'max:32000'],
            'csv_file' => ['nullable', 'file', 'max:31', 'mimes:csv,txt'],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();
        $csv = is_string($data['csv'] ?? null) ? $data['csv'] : '';
        $upload = $request->file('csv_file');

        if ($upload !== null && trim($csv) !== '') {
            return back()->withErrors(['csv' => 'Paste CSV or upload a file, not both.']);
        }
        if ($upload !== null) {
            $path = $upload->getRealPath();
            $contents = is_string($path) ? @file_get_contents($path) : false;
            if (! is_string($contents)) {
                return back()->withErrors(['csv_file' => 'Could not read the uploaded CSV file.']);
            }
            $csv = $contents;
        }
        if (trim($csv) === '') {
            return back()->withErrors(['csv' => 'Paste CSV rows or choose a CSV file.']);
        }

        $allowed = MailProvisioner::domainsFor($account);
        $rows = Mail::parseImport($csv, $allowed);
        unset($csv, $contents); // Do not retain cleartext credentials beyond parsing.
        if ($rows === null) {
            return back()->withErrors(['csv' => 'Invalid CSV or duplicate address. Format: local,domain,password ya email,password. Pipe/shell/foreign domain fail closed.']);
        }
        $max = (int) ($account->package?->MAXPOP ?? -1);
        if ($max >= 0 && $account->mailboxes()->count() + count($rows) > $max) {
            return back()->withErrors(['csv' => 'Package MAXPOP limit reached.']);
        }
        foreach ($rows as $row) {
            $exists = Mailbox::query()->where('account_id', $account->id)->where('localpart', $row['local'])->where('domain', $row['domain'])->exists();
            if ($exists) {
                return back()->withErrors(['csv' => 'Duplicate mailbox: ' . $row['local'] . '@' . $row['domain']]);
            }
        }

        // Hash the entire batch before inserting anything, then commit all rows
        // together so one bad hash or a concurrent unique-key conflict can't leave
        // a partially imported account behind.
        $prepared = [];
        foreach ($rows as $row) {
            $hash = Mail::hashPassword($row['password']);
            if ($hash === null) {
                return back()->withErrors(['csv' => 'Password hash fail; no mailboxes were imported.']);
            }
            $prepared[] = [
                'account_id' => $account->id,
                'localpart' => $row['local'],
                'domain' => $row['domain'],
                'quota_mb' => $row['quota_mb'],
                'password_hash' => $hash,
                'status' => 'pending',
            ];
        }
        try {
            DB::transaction(static function () use ($prepared): void {
                foreach ($prepared as $mailbox) {
                    Mailbox::query()->create($mailbox);
                }
            });
        } catch (QueryException) {
            return back()->withErrors(['csv' => 'Mailbox import conflicted with another change; no rows were saved.']);
        }

        MailProvisioner::enqueue($account);
        $account->recordEvent('mail.import.queued', (string) count($prepared));
        Audit::log('mail.import', 'info', 'account', $account->id, ['count' => count($prepared)]);

        return redirect()->route('address-importer.index')->with('success', 'Import is queued (mail.set).');
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
