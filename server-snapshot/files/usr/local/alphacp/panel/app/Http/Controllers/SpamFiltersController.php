<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\SpamSetting;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Spam Filters — score + lists via paneld mail.spam. No pipes. */
class SpamFiltersController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $row = $account?->spamSetting;

        return view('spam-filters.index', [
            'account' => $account,
            'score' => (int) ($row?->required_score ?? 5),
            'blacklist' => $row?->blacklist ?? [],
            'whitelist' => $row?->whitelist ?? [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['dest' => 'Suspended/terminated account par spam filters nahi.']);
        }
        $data = $request->validate([
            'required_score' => ['required', 'integer', 'min:1', 'max:10'],
            'list' => ['nullable', 'in:black,white'],
            'dest' => ['nullable', 'string', 'max:190'],
        ]);
        $row = SpamSetting::query()->firstOrNew(['account_id' => $account->id]);
        $black = is_array($row->blacklist) ? $row->blacklist : [];
        $white = is_array($row->whitelist) ? $row->whitelist : [];
        if (($data['dest'] ?? '') !== '') {
            $dest = Mail::tryDest($data['dest']);
            if ($dest === null) {
                return back()->withErrors(['dest' => 'Dest email hona chahiye, pipe nahi.'])->withInput();
            }
            if (($data['list'] ?? 'black') === 'white') {
                $white[] = $dest;
                $white = array_values(array_unique($white));
            } else {
                $black[] = $dest;
                $black = array_values(array_unique($black));
            }
        }
        $row->required_score = (int) $data['required_score'];
        $row->blacklist = $black;
        $row->whitelist = $white;
        $row->save();
        MailProvisioner::enqueueSpam($account);
        $account->recordEvent('mail.spam.queued', 'score ' . $row->required_score);
        Audit::log('mail.spam', 'info', 'account', $account->id, ['score' => $row->required_score]);

        return redirect()->route('spam-filters.index')->with('success', 'Spam filters queue me hain.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'spamSetting']);
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
