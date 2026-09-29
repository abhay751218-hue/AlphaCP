<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Domain;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\DomainProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel SSL/TLS Status — self-signed certs via paneld ssl.issue. */
class SslController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        if ($account !== null) {
            DomainProvisioner::seedMain($account);
            foreach ($account->domains as $domain) {
                DomainProvisioner::refresh($domain);
                $this->refreshSsl($domain);
            }
            $account->refresh()->load('domains');
        }

        return view('ssl.index', [
            'account' => $account,
            'domains' => $account?->domains()->orderBy('domain')->get() ?? collect(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function issue(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($domain->account_id !== $account->id) {
            abort(403);
        }
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['ssl' => 'Suspended/terminated account par SSL nahi.']);
        }
        if ($domain->type === 'redirect') {
            return back()->withErrors(['ssl' => 'Redirect domain par SSL nahi.']);
        }

        $domain->forceFill([
            'ssl_status' => 'pending',
            'ssl_issuer' => 'selfsigned',
            'ssl_not_after' => now()->addYear(),
        ])->save();

        AccountProvisioner::enqueue($account, 'ssl.issue', [
            'username' => $account->username,
            'domain' => $domain->domain,
            'document_root' => $domain->document_root,
            'mode' => 'selfsigned',
        ]);
        $account->recordEvent('ssl.issue.queued', $domain->domain);
        Audit::log('ssl.issue', 'info', 'domain', $domain->id, ['domain' => $domain->domain]);

        return redirect()->route('ssl.index')->with('success', "SSL '{$domain->domain}' queue me hai (self-signed).");
    }

    public function destroy(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($domain->account_id !== $account->id) {
            abort(403);
        }
        $domain->forceFill(['ssl_status' => 'removing'])->save();
        AccountProvisioner::enqueue($account, 'ssl.remove', [
            'username' => $account->username,
            'domain' => $domain->domain,
        ]);
        Audit::log('ssl.remove', 'warning', 'domain', $domain->id, ['domain' => $domain->domain]);

        return redirect()->route('ssl.index')->with('success', "SSL '{$domain->domain}' hataane ke liye queue me hai.");
    }

    private function refreshSsl(Domain $domain): void
    {
        $task = \Illuminate\Support\Facades\DB::table('tasks')
            ->where('account_id', $domain->account_id)
            ->whereIn('type', ['ssl.issue', 'ssl.remove'])
            ->orderByDesc('id')
            ->first();
        if ($task === null || (string) $task->status !== 'success') {
            return;
        }
        $payload = json_decode((string) $task->payload, true) ?: [];
        if (($payload['domain'] ?? '') !== $domain->domain) {
            return;
        }
        if ($task->type === 'ssl.issue') {
            $domain->forceFill(['ssl_status' => 'active'])->save();
        }
        if ($task->type === 'ssl.remove' && $domain->ssl_status === 'removing') {
            $domain->forceFill([
                'ssl_status' => 'none',
                'ssl_issuer' => null,
                'ssl_not_after' => null,
            ])->save();
        }
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }
        return $request->user()->hostingAccount?->load('domains');
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
