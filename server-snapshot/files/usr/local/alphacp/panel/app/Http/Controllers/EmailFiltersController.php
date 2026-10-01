<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\MailFilter;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Email Filters — contains-match via paneld mail.filter. No pipes. */
class EmailFiltersController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('email-filters.index', [
            'account' => $account,
            'rows' => $account?->mailFilters()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Cannot change filters on a suspended/terminated account.']);
        }
        if (MailProvisioner::filterLimitReached($account)) {
            return back()->withErrors(['localpart' => 'Filter limit of 50 reached.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'field' => ['required', 'string', 'max:16'],
            'needle' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'max:16'],
            'folder' => ['nullable', 'string', 'max:32'],
        ]);
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $field = Mail::tryFilterField($data['field']);
        $needle = Mail::tryNeedle($data['needle']);
        $action = Mail::tryFilterAction($data['action']);
        $folder = '';
        if ($action === 'folder') {
            $folder = Mail::tryLocal((string) ($data['folder'] ?? '')) ?? '';
        }
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $field === null || $needle === null || $action === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid filter. No pipe/regex/shell. Domain must belong to this account.'])->withInput();
        }
        if ($action === 'folder' && $folder === '') {
            return back()->withErrors(['folder' => 'Folder action ke liye folder name chahiye.'])->withInput();
        }
        MailFilter::query()->create([
            'account_id' => $account->id,
            'localpart' => $local,
            'domain' => $domain,
            'field' => $field,
            'needle' => $needle,
            'action' => $action,
            'folder' => $folder,
        ]);
        MailProvisioner::enqueueFilters($account);
        $account->recordEvent('mail.filter.queued', $local . '@' . $domain);
        Audit::log('mail.filter', 'info', 'account', $account->id, ['source' => $local . '@' . $domain, 'needle' => $needle]);

        return redirect()->route('email-filters.index')->with('success', 'Filter is queued.');
    }

    public function destroy(Request $request, MailFilter $filter): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($filter->account_id !== $account->id) {
            abort(403);
        }
        $src = $filter->source();
        $filter->delete();
        MailProvisioner::enqueueFilters($account);
        Audit::log('mail.filter.remove', 'warning', 'account', $account->id, ['source' => $src]);

        return redirect()->route('email-filters.index')->with('success', 'Filter is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'mailFilters']);
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
