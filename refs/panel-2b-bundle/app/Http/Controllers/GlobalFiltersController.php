<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\GlobalFilter;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Global Email Filters — account-wide contains-match via paneld. No pipes. */
class GlobalFiltersController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('global-filters.index', [
            'account' => $account,
            'rows' => $account?->globalFilters()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['needle' => 'Cannot change global filters on a suspended/terminated account.']);
        }
        if (MailProvisioner::gfilterLimitReached($account)) {
            return back()->withErrors(['needle' => 'Global filter limit of 50 reached.']);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'field' => ['required', 'string', 'max:16'],
            'needle' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'max:16'],
            'folder' => ['nullable', 'string', 'max:32'],
        ]);
        $domain = Mail::tryDomain($data['domain']);
        $field = Mail::tryFilterField($data['field']);
        $needle = Mail::tryNeedle($data['needle']);
        $action = Mail::tryFilterAction($data['action']);
        $folder = '';
        if ($action === 'folder') {
            $folder = Mail::tryLocal((string) ($data['folder'] ?? '')) ?? '';
        }
        $allowed = MailProvisioner::domainsFor($account);
        if ($domain === null || $field === null || $needle === null || $action === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['needle' => 'Invalid filter. No pipe/regex/shell. Domain must belong to this account.'])->withInput();
        }
        if ($action === 'folder' && $folder === '') {
            return back()->withErrors(['folder' => 'Folder action ke liye folder name chahiye.'])->withInput();
        }
        GlobalFilter::query()->create([
            'account_id' => $account->id,
            'domain' => $domain,
            'field' => $field,
            'needle' => $needle,
            'action' => $action,
            'folder' => $folder,
        ]);
        MailProvisioner::enqueueGlobalFilters($account);
        $account->recordEvent('mail.gfilter.queued', $domain . ':' . $needle);
        Audit::log('mail.gfilter', 'info', 'account', $account->id, ['domain' => $domain, 'needle' => $needle]);

        return redirect()->route('global-filters.index')->with('success', 'Global filter is queued.');
    }

    public function destroy(Request $request, GlobalFilter $global_filter): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($global_filter->account_id !== $account->id) {
            abort(403);
        }
        $needle = $global_filter->needle;
        $global_filter->delete();
        MailProvisioner::enqueueGlobalFilters($account);
        Audit::log('mail.gfilter.remove', 'warning', 'account', $account->id, ['needle' => $needle]);

        return redirect()->route('global-filters.index')->with('success', 'Global filter is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'globalFilters']);
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
