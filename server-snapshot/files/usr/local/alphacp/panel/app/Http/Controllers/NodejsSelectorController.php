<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Setup Node.js App" — D13: PM2-style app manager. Har app ek systemd
 * unit hai (alphacp-node-<user>-<app>, Restart=always, log file in home).
 * Create/start/stop/restart/remove sab root agent ke node.* tasks se.
 */
final class NodejsSelectorController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $types   = Paneld::taskTypes();
        $agentOk = in_array('terminal.run', $types, true);
        $appsOk  = in_array('node.list', $types, true);

        $nodeVersion = null;
        if ($agentOk) {
            $res = Paneld::run('terminal.run', ['command' => 'node -v'], 20);
            if (is_array($res) && ($res['status'] ?? '') === 'ok'
                && preg_match('/^v\d+\.\d+\.\d+/', trim((string) ($res['output'] ?? '')), $m) === 1) {
                $nodeVersion = $m[0];
            }
        }

        $apps = [];
        if ($appsOk && $account !== null && !$account->isSuspended() && !$account->isTerminated()) {
            $res = Paneld::run('node.list', ['username' => $account->username], 25);
            if (is_array($res) && is_array($res['apps'] ?? null)) {
                $apps = $res['apps'];
            }
        }

        return view('nodejs.index', [
            'account'     => $account,
            'agentOk'     => $agentOk,
            'appsOk'      => $appsOk,
            'nodeVersion' => $nodeVersion,
            'apps'        => $apps,
            'panelMode'   => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    /** D13 — Setup Node.js App (create + start). */
    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'name'  => ['required', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            'entry' => ['nullable', 'regex:/^[a-z0-9][a-z0-9._-]{0,30}\.(js|mjs|cjs)$/'],
            'port'  => ['required', 'integer', 'min:3000', 'max:3999'],
        ]);
        if (! in_array('node.setup', Paneld::taskTypes(), true)) {
            return back()->withErrors(['name' => 'Agent par node.setup task nahi — paneld update chahiye.']);
        }

        $payload = [
            'username' => $account->username,
            'name'     => $data['name'],
            'port'     => (int) $data['port'],
        ];
        if (($data['entry'] ?? '') !== '' && $data['entry'] !== null) {
            $payload['entry'] = $data['entry'];
        }
        $res = Paneld::run('node.setup', $payload, 60);
        if (! is_array($res) || ($res['app'] ?? '') === '') {
            return back()->withErrors(['name' => 'App setup fail — WHM Task Queue Monitor me error dekho.']);
        }

        Audit::log('node.app.setup', 'info', 'account', $account->id, [
            'app' => $data['name'], 'port' => (int) $data['port'],
        ]);
        $state = (string) ($res['state']['active'] ?? '');

        return redirect()->route('nodejs.index')->with('success',
            "App '{$data['name']}' ready (port {$data['port']}, state: {$state})"
            . (($res['sample_created'] ?? false) ? ' — sample entry file bana di gayi.' : '.'));
    }

    /** D13 — start/stop/restart/remove. */
    public function control(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'name'   => ['required', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            'action' => ['required', 'in:start,stop,restart,remove'],
        ]);
        if (! in_array('node.control', Paneld::taskTypes(), true)) {
            return back()->withErrors(['name' => 'Agent par node.control task nahi — paneld update chahiye.']);
        }

        $res = Paneld::run('node.control', [
            'username' => $account->username,
            'name'     => $data['name'],
            'action'   => $data['action'],
        ], 60);
        if (! is_array($res)) {
            return back()->withErrors(['name' => ucfirst($data['action']) . ' fail — Task Queue Monitor me error dekho.']);
        }

        Audit::log('node.app.control', 'info', 'account', $account->id, [
            'app' => $data['name'], 'action' => $data['action'],
        ]);

        return redirect()->route('nodejs.index')->with('success',
            "App '{$data['name']}': {$data['action']} ho gaya.");
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
        if ($account->isSuspended() || $account->isTerminated()) {
            abort(403, 'Account suspended/terminated.');
        }

        return $account;
    }
}
