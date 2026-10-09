<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\BindServer;
use Alphacp\Agent\Dns;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * dns.bind — real BIND9 zones (S9).
 *
 * Actions:
 *  - `status` : kya bind9 hai, named-checkconf/rndc kya kehte hain, kitne zone hain
 *  - `setup`  : idempotent provisioning (zone dir, managed options, include line,
 *               named restart) — har update par chalaya ja sakta hai
 *  - `write`  : ek domain ke records -> zone file, par `named-checkzone` ke baad hi
 *  - `remove` : zone file + named.conf clause hatao
 *  - `verify` : `dig @127.0.0.1 <domain> SOA/A/NS` — asli jawab, hamara daawa nahi
 *  - `list`   : kaunse zone is server par hain
 *  - `sync`   : har account ke zone.json se saare zones dobara likho (cPanel
 *               "Synchronize DNS Records") — pehli baar BIND setup bhi yahi karta hai
 *
 * Records ya to payload me aate hain, ya (username diya ho to) account ke
 * `~/etc/dns/zone.json` se padhe jate hain — dono raste `Dns::sanitize()` se
 * guzarte hain, to zone file me kabhi koi ajeeb cheez nahi likhi ja sakti.
 *
 * @acp-task dns.bind
 */
final class BindSetup implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $server = new BindServer($ctx->cmd, $ctx->log);

        try {
            $out = match ($action) {
                'status' => $server->status(),
                'setup'  => $server->setup(),
                'list'   => $this->listZones($server),
                'sync'   => $this->sync($server),
                'write'  => $this->write($server, $payload),
                'remove' => $this->remove($server, $payload),
                'verify' => $this->verify($server, $payload),
                default  => throw new TaskRejectedException(
                    "dns.bind action '{$action}' nahi chalega (status/setup/list/write/remove/verify/sync)"
                ),
            };
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('dns.bind fail (safe): ' . $e->getMessage());
        }

        return ['action' => $action] + $out;
    }

    /** @param array<string, mixed> $payload */
    private function write(BindServer $server, array $payload): array
    {
        $domain = Dns::normalizeDomain((string) ($payload['domain'] ?? ''));
        $records = $payload['records'] ?? null;
        if (!is_array($records) && trim((string) ($payload['username'] ?? '')) === '') {
            throw new TaskRejectedException(
                "domain '{$domain}' ke liye records bhejo ya username do — andaza se khali zone nahi banega"
            );
        }
        if (!is_array($records)) {
            $records = $this->recordsFromAccount($payload);
        }
        $ttl = (int) ($payload['ttl'] ?? BindServer::DEFAULT_TTL);
        if ($ttl < 60 || $ttl > 86400) {
            throw new TaskRejectedException('ttl 60 se 86400 ke beech hona chahiye');
        }

        return $server->writeZone($domain, $records, null, $ttl);
    }

    /** @param array<string, mixed> $payload */
    private function remove(BindServer $server, array $payload): array
    {
        $domain = Dns::normalizeDomain((string) ($payload['domain'] ?? ''));

        return $server->removeZone($domain);
    }

    /** @param array<string, mixed> $payload */
    private function verify(BindServer $server, array $payload): array
    {
        $domain = Dns::normalizeDomain((string) ($payload['domain'] ?? ''));

        return $server->verify($domain);
    }

    private function listZones(BindServer $server): array
    {
        $zones = $server->zoneFiles();

        return ['zones' => $zones, 'count' => count($zones)];
    }

    /**
     * `sync` — har account ke ~/etc/dns/zone.json se sab zones dobara likho.
     * Yehi cPanel ka "Synchronize DNS Records" hai: ek hi task me poora server.
     * Zone file tabhi badalti hai jab `named-checkzone` bole theek hai, to ek
     * kharaab account doosre account ki zone nahi bigaad sakta.
     *
     * @return array<string, mixed>
     */
    private function sync(BindServer $server): array
    {
        if (!is_dir($server->zoneDir()) || !$server->installed()) {
            // pehli baar: BIND provision karke aage badho
            $server->setup();
        }

        $root = AccountPaths::fromEnv()->accountsRoot;
        $written = [];
        $failed = [];
        $skipped = 0;
        $seen = [];

        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $username = basename($dir);
            if (AccountIdentity::username($username) !== null) {
                continue;   // system dir (root, ubuntu, ...)
            }
            $file = $dir . '/etc/dns/zone.json';
            if (is_link($file) || !is_file($file)) {
                $skipped++;
                continue;
            }
            $raw = @file_get_contents($file);
            $rows = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($rows) || $rows === []) {
                $skipped++;
                continue;
            }
            $domains = [];
            foreach ($rows as $row) {
                $candidate = strtolower(trim((string) (is_array($row) ? ($row['domain'] ?? '') : '')));
                if ($candidate !== '' && Dns::validDomain($candidate)) {
                    $domains[$candidate] = true;
                }
            }
            foreach (array_keys($domains) as $domain) {
                if (isset($seen[$domain]) || count($written) >= BindServer::MAX_ZONES) {
                    continue;
                }
                $seen[$domain] = true;
                try {
                    $out = $server->writeZone($domain, $rows);
                    $written[] = [
                        'domain'   => $domain,
                        'records'  => (int) ($out['records'] ?? 0),
                        'verified' => (bool) ($out['verified'] ?? false),
                    ];
                } catch (Throwable $e) {
                    // ek zone fail = doosre rukte nahi hain
                    $failed[] = ['domain' => $domain, 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'ok'      => $failed === [],
            'written' => $written,
            'failed'  => $failed,
            'skipped' => $skipped,
            'count'   => count($written),
        ];
    }

    /**
     * Account ke `~/etc/dns/zone.json` se records padho (panel wahi likhta hai).
     *
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private function recordsFromAccount(array $payload): array
    {
        $username = strtolower(trim((string) ($payload['username'] ?? '')));
        if ($username === '') {
            return [];
        }
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }

        $file = AccountPaths::fromEnv()->home($username) . '/etc/dns/zone.json';
        if (is_link($file) || !is_file($file)) {
            throw new TaskRejectedException("account {$username} ka zone.json nahi mila ({$file})");
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            throw new TaskRejectedException("account {$username} ka zone.json khali hai");
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
