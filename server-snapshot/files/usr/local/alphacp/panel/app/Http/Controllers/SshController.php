<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\ModuleCatalog;
use App\Support\Ssh;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel SSH Access — authorized_keys + nologin/bash via paneld ssh.set. */
class SshController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $ssh = is_array($account?->meta['ssh'] ?? null) ? $account->meta['ssh'] : [];
        $keys = Ssh::sanitize(is_array($ssh['keys'] ?? null) ? $ssh['keys'] : []);
        $shell = Ssh::tryShell((string) ($ssh['shell'] ?? 'nologin')) ?? 'nologin';
        $hasShell = (bool) ($account?->package?->HASSHELL ?? false);

        return view('ssh.index', [
            'account' => $account,
            'keys' => $keys,
            'shell' => $shell,
            'hasShell' => $hasShell,
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['pubkey' => 'Suspended/terminated account par SSH Access nahi.']);
        }
        $data = $request->validate([
            'pubkey' => ['required', 'string', 'max:9000'],
        ]);
        $parsed = Ssh::tryLine($data['pubkey']);
        if ($parsed === null) {
            return back()->withErrors(['pubkey' => 'Public key chahiye (ssh-ed25519 / ssh-rsa). Private key ya options nahi.'])->withInput();
        }
        $state = $this->state($account);
        $byId = [];
        foreach ($state['keys'] as $row) {
            $byId[$row['id']] = $row;
        }
        $byId[$parsed['id']] = $parsed;
        $keys = array_values($byId);
        if (count($keys) > Ssh::MAX_KEYS) {
            return back()->withErrors(['pubkey' => '20 SSH keys max.'])->withInput();
        }
        $this->persist($account, $keys, $state['shell']);

        return redirect()->route('ssh.index')->with('success', 'SSH key queue me hai.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'key_id' => ['required', 'string', 'max:32'],
        ]);
        $state = $this->state($account);
        $keep = [];
        foreach ($state['keys'] as $row) {
            if ($row['id'] !== $data['key_id']) {
                $keep[] = $row;
            }
        }
        $this->persist($account, $keep, $state['shell']);

        return redirect()->route('ssh.index')->with('success', 'SSH key hataane ke liye queue me hai.');
    }

    public function shell(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['shell' => 'Suspended/terminated account par SSH Access nahi.']);
        }
        $data = $request->validate([
            'shell' => ['required', 'string', 'max:16'],
        ]);
        $shell = Ssh::tryShell($data['shell']);
        if ($shell === null) {
            return back()->withErrors(['shell' => 'Shell sirf nologin ya bash.']);
        }
        $hasShell = (bool) ($account->package?->HASSHELL ?? false);
        if ($shell === 'bash' && ! $hasShell) {
            return back()->withErrors(['shell' => 'Is package me HASSHELL off hai.']);
        }
        $state = $this->state($account);
        $this->persist($account, $state['keys'], $shell);

        return redirect()->route('ssh.index')->with('success', 'SSH shell queue me hai.');
    }

    /**
     * @return array{keys: list<array{type: string, key: string, comment: string, id: string}>, shell: string}
     */
    private function state(Account $account): array
    {
        $ssh = is_array($account->meta['ssh'] ?? null) ? $account->meta['ssh'] : [];

        return [
            'keys' => Ssh::sanitize(is_array($ssh['keys'] ?? null) ? $ssh['keys'] : []),
            'shell' => Ssh::tryShell((string) ($ssh['shell'] ?? 'nologin')) ?? 'nologin',
        ];
    }

    /** @param list<array{type: string, key: string, comment: string, id?: string}> $keys */
    private function persist(Account $account, array $keys, string $shell): void
    {
        $clean = [];
        foreach (Ssh::sanitize($keys) as $row) {
            $clean[] = ['type' => $row['type'], 'key' => $row['key'], 'comment' => $row['comment']];
        }
        $meta = $account->meta ?? [];
        $meta['ssh'] = ['keys' => $clean, 'shell' => $shell];
        $account->forceFill(['meta' => $meta])->save();

        AccountProvisioner::enqueue($account, 'ssh.set', [
            'username' => $account->username,
            'keys' => $clean,
            'shell' => $shell,
        ]);
        $account->recordEvent('ssh.set.queued', $shell . ':' . count($clean));
        Audit::log('ssh.set', 'info', 'account', $account->id, ['keys' => count($clean), 'shell' => $shell]);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }
        $account = $request->user()->hostingAccount;
        $account?->loadMissing('package');

        return $account;
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
