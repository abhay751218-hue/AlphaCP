<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Git Version Control" — repos list/clone/pull/status, account-scoped
 * (`<home>/git/<dir>`).
 *
 * B1: pehle ye controller web-FPM se shell (Process facade) chalata tha jo
 * `proc_open` disabled hone ki wajah se HTTP 500 deta tha. Ab saara git kaam root
 * agent karta hai (`git.list` / `git.clone` / `git.pull` / `git.status`); panel sirf
 * queue karta hai ya `Paneld::run` se synchronous result dikhata hai (status page).
 */
final class GitController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $repos = [];
        $note = null;

        if ($account !== null && in_array('git.list', Paneld::taskTypes(), true)) {
            $res = Paneld::run('git.list', ['account' => $account->username], 10);
            $repos = is_array($res['repos'] ?? null) ? $res['repos'] : [];
            if ($res === null) {
                $note = 'Agent se repo list nahi mili (task timeout) — dobara try karo.';
            }
        } elseif ($account !== null) {
            $note = 'Agent par git tasks available nahi hain (agent update chahiye).';
        }

        return view('git.index', [
            'repos' => $repos,
            'note'  => $note,
        ]);
    }

    public function clone(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'url' => ['required', 'url', 'max:300'],
            'dir' => ['required', 'string', 'regex:/^[a-z0-9._-]{1,64}$/i'],
        ]);

        AccountProvisioner::enqueue($account, 'git.clone', [
            'account' => $account->username,
            'url'     => $data['url'],
            'dir'     => strtolower($data['dir']),
        ]);
        $account->recordEvent('git.clone.queued', ['dir' => $data['dir']]);
        Audit::log('git.clone', 'info', 'account', $account->id, ['dir' => $data['dir']]);

        return redirect()->route('git.index')->with('success', 'Clone queue me hai — thodi der me list me dikhega.');
    }

    public function pull(Request $request, string $dir): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $dir = strtolower($dir);

        AccountProvisioner::enqueue($account, 'git.pull', [
            'account' => $account->username,
            'dir'     => $dir,
        ]);
        $account->recordEvent('git.pull.queued', ['dir' => $dir]);
        Audit::log('git.pull', 'info', 'account', $account->id, ['dir' => $dir]);

        return redirect()->route('git.index')->with('success', "Pull queue me hai ({$dir}).");
    }

    public function status(Request $request, string $dir): View|RedirectResponse
    {
        $account = $this->requireAccount($request);
        $dir = strtolower($dir);

        $res = Paneld::run('git.status', ['account' => $account->username, 'dir' => $dir], 35);
        if ($res === null) {
            return redirect()->route('git.index')->with('error', "Status nahi mila ({$dir}) — repo maujood hai?");
        }

        return view('git.status', [
            'dir'    => $dir,
            'output' => implode("\n", $res['lines'] ?? []),
        ]);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount;
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
