<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Autoresponder;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Autoresponders — vacation reply via paneld mail.autorespond. No pipes. */
class AutorespondersController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('autoresponders.index', [
            'account' => $account,
            'rows' => $account?->autoresponders()->orderBy('id')->get() ?? collect(),
            'domains' => $account ? MailProvisioner::domainsFor($account) : [],
            'maxResp' => $account?->package?->formatLimit('MAXRESP') ?? '—',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['localpart' => 'Suspended/terminated account par autoresponder nahi.']);
        }
        if (MailProvisioner::respLimitReached($account)) {
            return back()->withErrors(['localpart' => 'Package MAXRESP limit poori.']);
        }
        $data = $request->validate([
            'localpart' => ['required', 'string', 'max:32'],
            'domain' => ['required', 'string', 'max:190'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'interval_h' => ['nullable', 'integer', 'min:0', 'max:168'],
        ]);
        $local = Mail::tryLocal($data['localpart']);
        $domain = Mail::tryDomain($data['domain']);
        $subject = Mail::trySubject($data['subject']);
        $body = Mail::tryBody($data['body']);
        $interval = (int) ($data['interval_h'] ?? 24);
        $allowed = MailProvisioner::domainsFor($account);
        if ($local === null || $domain === null || $subject === null || $body === null || ! in_array($domain, $allowed, true)) {
            return back()->withErrors(['localpart' => 'Invalid source/subject/body. Pipe/shell nahi. Domain is account ka hona chahiye.'])->withInput();
        }
        $exists = Autoresponder::query()->where('account_id', $account->id)->where('localpart', $local)->where('domain', $domain)->exists();
        if ($exists) {
            return back()->withErrors(['localpart' => 'Is address ka autoresponder pehle se hai.'])->withInput();
        }
        Autoresponder::query()->create([
            'account_id' => $account->id,
            'localpart' => $local,
            'domain' => $domain,
            'subject' => $subject,
            'body' => $body,
            'interval_h' => $interval,
        ]);
        MailProvisioner::enqueueResponders($account);
        $account->recordEvent('mail.autorespond.queued', $local . '@' . $domain);
        Audit::log('mail.autorespond', 'info', 'account', $account->id, ['source' => $local . '@' . $domain]);

        return redirect()->route('autoresponders.index')->with('success', 'Autoresponder queue me hai.');
    }

    public function destroy(Request $request, Autoresponder $autoresponder): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($autoresponder->account_id !== $account->id) {
            abort(403);
        }
        $src = $autoresponder->source();
        $autoresponder->delete();
        MailProvisioner::enqueueResponders($account);
        Audit::log('mail.autorespond.remove', 'warning', 'account', $account->id, ['source' => $src]);

        return redirect()->route('autoresponders.index')->with('success', 'Autoresponder hataane ke liye queue me hai.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains', 'autoresponders']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'Is login ka hosting account nahi hai.');
        }

        return $account;
    }
}
