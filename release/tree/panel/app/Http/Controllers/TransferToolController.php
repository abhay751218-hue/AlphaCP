<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\TransferTool;
use App\Support\Audit;
use App\Support\Backup;
use App\Support\BackupProvisioner;
use App\Support\CpanelArchives;
use App\Support\Paneld;
use App\Support\Dns;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM Transfer Tool — migrate a cPanel account onto this server from a cpmove
 * archive the operator placed on the box (`paneld backup.transfer`).
 *
 * The source FQDN is recorded in the job result for the audit trail; pulling
 * the archive straight off the old server is a later S10 step.
 */
class TransferToolController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('transfer-tool.index', [
            'row' => TransferTool::query()->orderByDesc('id')->first(),
            'archives' => CpanelArchives::candidates(),
            'pullTask' => Paneld::recentJobs(['backup.pull'], 1)->first(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:16'],
            'source' => ['required', 'string', 'max:190'],
            'archive_path' => ['required', 'string', 'max:255'],
            'sha256' => ['nullable', 'string', 'max:64'],
        ]);
        $username = Backup::tryUsername($data['username']);
        $source = Dns::tryDomain($data['source']);
        $archive = Backup::tryArchivePath($data['archive_path']);
        $sha256 = Backup::normalizeSha256((string) ($data['sha256'] ?? ''));
        if ($username === null || $source === null || $archive === null || $sha256 === null) {
            return back()->withErrors([
                'source' => 'Invalid transfer. Username 3–16 a-z/0-9; source FQDN; archive ka poora path (.tar/.tar.gz/.tgz); sha256 optional 64 hex. No pipe/path escape.',
            ])->withInput();
        }
        $account = Account::query()->where('username', $username)->first();
        if ($account === null) {
            return back()->withErrors([
                'username' => 'Pehle Accounts → Create Account se yeh account banao — transfer existing account ke home me import hota hai.',
            ])->withInput();
        }

        $row = TransferTool::query()->orderByDesc('id')->first();
        if ($row === null) {
            TransferTool::query()->create([
                'username' => $username,
                'source' => $source,
            ]);
        } else {
            $row->update([
                'username' => $username,
                'source' => $source,
            ]);
        }
        BackupProvisioner::enqueueTransfer($username, $source, $archive, (string) $sha256);
        Audit::log('backup.transfer', 'info', 'account', $account->id, [
            'username' => $username,
            'source' => $source,
            'archive' => $archive,
        ]);

        return redirect()->route('transfer-tool.index')->with('success', 'Transfer queue ho gaya — status Review Transfers and Restores par dekho.');
    }

    /**
     * Step 1 of a remote pull: ask the agent for the SSH host key fingerprint of
     * the old server. Downloads nothing — the operator compares the fingerprint
     * with something they trust and pins it before any bytes move.
     */
    public function probe(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'host'        => ['required', 'string', 'max:253'],
            'port'        => ['nullable', 'integer', 'min:1', 'max:65535'],
            'user'        => ['required', 'string', 'max:32'],
            'remote_path' => ['required', 'string', 'max:4096'],
        ]);
        $spec = Backup::tryRemoteSpec($data);
        if ($spec === null) {
            return back()->withErrors([
                'remote' => 'Host (FQDN ya IP), SSH user (a-z/0-9/_/-) aur remote path (poora, .. ke bina) theek do.',
            ])->withInput();
        }

        BackupProvisioner::enqueueRemoteProbe($spec);
        Audit::log('backup.pull', 'info', 'server', null, [
            'probe'       => true,
            'host'        => $spec['host'],
            'port'        => $spec['port'],
            'user'        => $spec['user'],
            'remote_path' => $spec['remote_path'],
        ]);

        return redirect()->route('transfer-tool.index')
            ->withInput()
            ->with('success', "Host key fingerprint ke liye task queue ho gaya ({$spec['host']}) — Result yahin neeche dikhega; use verify karke pin karo, phir pull karo.");
    }

    /**
     * Step 2: pull the archive over scp into the import drop dir. The host key
     * must be pinned (or the operator explicitly accepts the first key).
     */
    public function pull(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'host'             => ['required', 'string', 'max:253'],
            'port'             => ['nullable', 'integer', 'min:1', 'max:65535'],
            'user'             => ['required', 'string', 'max:32'],
            'remote_path'      => ['required', 'string', 'max:4096'],
            'auth'             => ['nullable', 'string', 'in:key,password'],
            'private_key'      => ['nullable', 'string', 'max:65536'],
            'password'         => ['nullable', 'string', 'max:1024'],
            'dest_name'        => ['nullable', 'string', 'max:120'],
            'sha256'           => ['nullable', 'string', 'max:64'],
            'host_fingerprint' => ['nullable', 'string', 'max:128'],
            'accept_host_key'  => ['nullable', 'boolean'],
            'max_kbps'         => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'overwrite'        => ['nullable', 'boolean'],
        ]);
        $spec = Backup::tryRemoteSpec($data);
        $fingerprint = Backup::tryFingerprint((string) ($data['host_fingerprint'] ?? ''));
        $destName = trim((string) ($data['dest_name'] ?? ''));
        $sha256 = Backup::normalizeSha256((string) ($data['sha256'] ?? ''));
        $auth = ($data['auth'] ?? 'key') === 'password' ? 'password' : 'key';
        if ($spec === null || $fingerprint === null || $sha256 === null) {
            return back()->withErrors([
                'remote' => 'Host/user/remote path theek nahi, ya fingerprint/sha256 galat format me hai.',
            ])->withInput();
        }
        if ($destName !== '' && Backup::tryDestName($destName) === null) {
            return back()->withErrors([
                'dest_name' => 'Destination name sirf .tar / .tar.gz / .tgz ho sakta hai.',
            ])->withInput();
        }
        if ($fingerprint === '' && ($data['accept_host_key'] ?? false) !== true) {
            return back()->withErrors([
                'host_fingerprint' => 'Pehle "Fingerprint lao" se host key verify karo (ya accept-host-key tick karo — kam safe).',
            ])->withInput();
        }
        if ($auth === 'password' && trim((string) ($data['password'] ?? '')) === '') {
            return back()->withErrors(['password' => 'Password auth chuna hai to password bhi do.'])->withInput();
        }
        if ($auth === 'key' && trim((string) ($data['private_key'] ?? '')) === '') {
            return back()->withErrors(['private_key' => 'Key auth ke liye private key paste karo.'])->withInput();
        }

        BackupProvisioner::enqueueRemotePull($spec, [
            'auth'             => $auth,
            'private_key'      => (string) ($data['private_key'] ?? ''),
            'password'         => (string) ($data['password'] ?? ''),
            'dest_name'        => $destName,
            'sha256'           => $sha256,
            'host_fingerprint' => $fingerprint,
            'accept_host_key'  => ($data['accept_host_key'] ?? false) === true,
            'max_kbps'         => (int) ($data['max_kbps'] ?? 0),
            'overwrite'        => ($data['overwrite'] ?? false) === true,
        ]);
        Audit::log('backup.pull', 'info', 'server', null, [
            'host'             => $spec['host'],
            'user'             => $spec['user'],
            'remote_path'      => $spec['remote_path'],
            'auth'             => $auth,
            'pinned'           => $fingerprint !== '',
        ]);

        return redirect()->route('transfer-tool.index')
            ->with('success', "Pull queue ho gaya — {$spec['user']}@{$spec['host']} se archive drop dir me aa jayega, phir yahin se import karo. Status: Job history.");
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Transfer Tool is a WHM tool.');
        }
    }
}
