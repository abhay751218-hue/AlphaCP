<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BlockedIp;
use App\Support\Audit;
use App\Support\Firewall;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel IP Blocker — account-level IP deny (ufw/iptables). */
final class IpBlockerController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $rows    = $account !== null
            ? BlockedIp::query()->where('account_id', $account->id)->orderBy('ip')->get()
            : collect();

        return view('ip-blocker.index', [
            'account'   => $account,
            'rows'      => $rows,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'ip'   => ['required', 'ip'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        if (BlockedIp::query()->where('account_id', $account->id)->where('ip', $data['ip'])->exists()) {
            return back()->withErrors(['ip' => 'Ye IP pehle se blocked hai.'])->withInput();
        }

        BlockedIp::query()->create([
            'account_id' => $account->id,
            'ip'         => $data['ip'],
            'note'       => (string) ($data['note'] ?? ''),
        ]);

        Firewall::block($data['ip']);
        Audit::log('ipblocker.add', 'info', 'account', $account->id, ['ip' => $data['ip']]);

        return redirect()->route('ip-blocker.index')->with('success', 'IP blocked.');
    }

    public function destroy(Request $request, BlockedIp $blockedIp): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $blockedIp->account_id !== (int) $account->id) {
            abort(404);
        }

        Firewall::unblock($blockedIp->ip);
        $blockedIp->delete();
        Audit::log('ipblocker.del', 'info', 'account', $account->id, ['ip' => $blockedIp->ip]);

        return redirect()->route('ip-blocker.index')->with('success', 'IP unblocked.');
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
