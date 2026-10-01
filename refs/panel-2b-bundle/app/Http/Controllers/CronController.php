<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CronJob;
use App\Support\Audit;
use App\Support\CronProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Cron Jobs — jailed crontab via paneld cron.set. */
class CronController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);

        return view('cron.index', [
            'account' => $account,
            'jobs' => $account?->cronJobs()->orderBy('id')->get() ?? collect(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['command' => 'Cannot change cron on a suspended/terminated account.']);
        }
        if (! CronProvisioner::featureAllowed($account->load('package.featureList'))) {
            return back()->withErrors(['command' => 'Cron is disabled on this package.']);
        }
        if (CronProvisioner::limitReached($account)) {
            return back()->withErrors(['command' => 'Package MAXCRON limit reached.']);
        }

        $data = $request->validate([
            'minute' => ['required', 'string', 'max:40', 'regex:' . CronProvisioner::FIELD],
            'hour' => ['required', 'string', 'max:40', 'regex:' . CronProvisioner::FIELD],
            'day' => ['required', 'string', 'max:40', 'regex:' . CronProvisioner::FIELD],
            'month' => ['required', 'string', 'max:40', 'regex:' . CronProvisioner::FIELD],
            'weekday' => ['required', 'string', 'max:40', 'regex:' . CronProvisioner::FIELD],
            'command' => ['required', 'string', 'max:500'],
        ]);
        if (strpbrk($data['command'], "\r\n") !== false) {
            return back()->withErrors(['command' => 'Newlines are not allowed in the command.'])->withInput();
        }

        CronJob::query()->create([
            'account_id' => $account->id,
            'minute' => $data['minute'],
            'hour' => $data['hour'],
            'day' => $data['day'],
            'month' => $data['month'],
            'weekday' => $data['weekday'],
            'command' => $data['command'],
            'enabled' => true,
            'status' => 'pending',
        ]);
        CronProvisioner::enqueue($account);
        $account->recordEvent('cron.set.queued', $data['command']);
        Audit::log('cron.add', 'info', 'account', $account->id, ['command' => $data['command']]);

        return redirect()->route('cron.index')->with('success', 'Cron job is queued.');
    }

    public function destroy(Request $request, CronJob $cron): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($cron->account_id !== $account->id) {
            abort(403);
        }
        $cron->delete();
        CronProvisioner::enqueue($account);
        Audit::log('cron.remove', 'warning', 'account', $account->id, ['cron_id' => $cron->id]);

        return redirect()->route('cron.index')->with('success', 'Cron job is queued for removal.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }
        return $request->user()->hostingAccount?->load(['package.featureList', 'cronJobs']);
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
