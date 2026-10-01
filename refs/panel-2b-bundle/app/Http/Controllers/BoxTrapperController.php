<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BoxTrapperSetting;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel BoxTrapper — enabled + allowlist via paneld. No daemon, no pipe. */
class BoxTrapperController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $row = $account?->boxTrapperSetting;

        return view('boxtrapper.index', [
            'account' => $account,
            'enabled' => (bool) ($row?->enabled ?? false),
            'allowlist' => $row?->allowlist ?? [],
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['dest' => 'Cannot change BoxTrapper on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'enabled' => ['nullable', 'in:0,1'],
            'dest' => ['nullable', 'string', 'max:190'],
        ]);
        $row = BoxTrapperSetting::query()->firstOrNew(['account_id' => $account->id]);
        $allow = is_array($row->allowlist) ? $row->allowlist : [];
        if (($data['dest'] ?? '') !== '') {
            $dest = Mail::tryDest($data['dest']);
            if ($dest === null) {
                return back()->withErrors(['dest' => 'Dest must be an email, no pipe.'])->withInput();
            }
            $allow[] = $dest;
            $allow = array_values(array_unique($allow));
            if (count($allow) > 50) {
                return back()->withErrors(['dest' => 'Allowlist limit of 50 reached.']);
            }
        }
        $row->enabled = ($data['enabled'] ?? '0') === '1';
        $row->allowlist = $allow;
        $row->save();
        MailProvisioner::enqueueBoxtrapper($account);
        $account->recordEvent('mail.boxtrapper.queued', $row->enabled ? 'on' : 'off');
        Audit::log('mail.boxtrapper', 'info', 'account', $account->id, ['enabled' => $row->enabled]);

        return redirect()->route('boxtrapper.index')->with('success', 'BoxTrapper is queued.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'boxTrapperSetting']);
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
