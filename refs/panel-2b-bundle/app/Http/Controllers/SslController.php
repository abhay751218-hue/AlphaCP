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
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** cPanel SSL/TLS Status — AutoSSL (Let's Encrypt) + self-signed fallback. */
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
        $mode = (string) $request->input('mode', 'letsencrypt');
        if (! in_array($mode, ['selfsigned', 'letsencrypt'], true)) {
            $mode = 'letsencrypt';
        }

        $error = $this->queueIssue($account, $domain, $mode);
        if ($error !== null) {
            return back()->withErrors(['ssl' => $error]);
        }

        $label = $mode === 'letsencrypt' ? "Let's Encrypt" : 'self-signed';

        return redirect()->route('ssl.index')->with('success', "SSL '{$domain->domain}' queue me hai ({$label}).");
    }

    public function autossl(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['ssl' => 'Suspended/terminated account par AutoSSL nahi.']);
        }

        $queued = 0;
        foreach ($account->domains()->orderBy('id')->get() as $domain) {
            if (! $domain->ssl_autossl) {
                continue;
            }
            if ($this->queueIssue($account, $domain, 'letsencrypt') === null) {
                $queued++;
            }
        }
        $account->recordEvent('ssl.autossl.queued', (string) $queued);
        Audit::log('ssl.autossl', 'info', 'account', $account->id, ['queued' => $queued]);

        if ($queued === 0) {
            return redirect()->route('ssl.index')->with('success', 'AutoSSL: koi domain queue me nahi (include/redirect check).');
        }

        return redirect()->route('ssl.index')->with('success', "AutoSSL {$queued} domain(s) queue me.");
    }

    public function toggle(Request $request, Domain $domain): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($domain->account_id !== $account->id) {
            abort(403);
        }
        $next = ! $domain->ssl_autossl;
        $domain->forceFill(['ssl_autossl' => $next])->save();
        Audit::log('ssl.autossl.toggle', 'info', 'domain', $domain->id, [
            'domain' => $domain->domain,
            'ssl_autossl' => $next,
        ]);

        $state = $next ? 'include' : 'exclude';

        return redirect()->route('ssl.index')->with('success', "AutoSSL {$state}: {$domain->domain}");
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

    private function queueIssue(Account $account, Domain $domain, string $mode): ?string
    {
        if ($account->isTerminated() || $account->isSuspended()) {
            return 'Suspended/terminated account par SSL nahi.';
        }
        if ($domain->type === 'redirect') {
            return 'Redirect domain par SSL nahi.';
        }
        if (in_array((string) $domain->ssl_status, ['pending', 'removing'], true)) {
            return 'Is domain ka SSL task pehle se queue me hai.';
        }

        $domain->forceFill([
            'ssl_status' => 'pending',
            'ssl_issuer' => $mode === 'letsencrypt' ? 'letsencrypt' : 'selfsigned',
            'ssl_not_after' => $mode === 'letsencrypt' ? now()->addDays(90) : now()->addYear(),
            'ssl_last_error' => null,
        ])->save();

        $payload = [
            'username' => $account->username,
            'domain' => $domain->domain,
            'document_root' => $domain->document_root,
            'mode' => $mode,
        ];
        $email = strtolower(trim((string) $account->contact_email));
        if ($mode === 'letsencrypt' && $email !== '' && str_contains($email, '@')) {
            $payload['email'] = $email;
        }
        AccountProvisioner::enqueue($account, 'ssl.issue', $payload);
        $account->recordEvent('ssl.issue.queued', $domain->domain);
        Audit::log('ssl.issue', 'info', 'domain', $domain->id, [
            'domain' => $domain->domain,
            'mode' => $mode,
        ]);

        return null;
    }

    private function refreshSsl(Domain $domain): void
    {
        $tasks = DB::table('tasks')
            ->where('account_id', $domain->account_id)
            ->whereIn('type', ['ssl.issue', 'ssl.remove'])
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $task = null;
        foreach ($tasks as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            if (($payload['domain'] ?? '') === $domain->domain) {
                $task = $row;
                break;
            }
        }
        if ($task === null) {
            return;
        }

        $status = (string) $task->status;
        if ($status === 'failed') {
            $domain->forceFill([
                'ssl_status' => 'failed',
                'ssl_last_error' => substr((string) ($task->error ?? 'failed'), 0, 250),
            ])->save();

            return;
        }
        if ($status !== 'success') {
            return;
        }

        if ($task->type === 'ssl.issue') {
            $result = json_decode((string) ($task->result ?? '{}'), true) ?: [];
            $fill = [
                'ssl_status' => 'active',
                'ssl_last_error' => null,
            ];
            if (isset($result['issuer']) && is_string($result['issuer'])) {
                $fill['ssl_issuer'] = $result['issuer'];
            }
            if (isset($result['not_after']) && is_string($result['not_after'])) {
                $fill['ssl_not_after'] = $result['not_after'];
            }
            $domain->forceFill($fill)->save();
        }
        if ($task->type === 'ssl.remove') {
            $domain->forceFill([
                'ssl_status' => 'none',
                'ssl_issuer' => null,
                'ssl_not_after' => null,
                'ssl_last_error' => null,
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
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
