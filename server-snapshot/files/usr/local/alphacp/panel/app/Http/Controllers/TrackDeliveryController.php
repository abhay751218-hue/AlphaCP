<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Track Delivery — search jailed track.json via paneld. No Exim log. */
class TrackDeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('track-delivery.index', [
            'account' => $account,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['query' => 'Cannot change tracking on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'query' => ['required', 'string', 'max:190'],
        ]);
        $query = Mail::tryDest($data['query']);
        if ($query === null) {
            return back()->withErrors(['query' => 'Query must be an email, no pipe/shell.'])->withInput();
        }
        MailProvisioner::enqueueTrack($account, $query);
        $account->recordEvent('mail.track.queued', $query);
        Audit::log('mail.track', 'info', 'account', $account->id, ['query' => $query]);

        return redirect()->route('track-delivery.index')->with('success', 'Track search is queued. Exim mainlog later.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
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
