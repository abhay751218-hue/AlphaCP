<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\RemotePull;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * backup.pull — fetch a cPanel archive from another server over SSH (scp) into
 * the import drop dir, so a migration does not start with "copy the tarball by
 * hand and hope it lands somewhere the agent may read".
 *
 * Two-step by design:
 *   1. `probe: true`   → returns the host key fingerprint, downloads nothing.
 *   2. normal pull     → host key must match that fingerprint (or the admin
 *                        explicitly accepts the first key, which is logged).
 *
 * Nothing here touches an account: it only writes one file into the drop dir,
 * and it never runs the archive through tar — the existing import/restore tasks
 * do that, with all of their own guards.
 *
 * @acp-task backup.pull
 */
final class BackupPull implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        // Drop dir = ${ACP_HOME}/incoming (the same dir the panel lists and the
        // updater creates). It sits inside the task's allowlisted root, so the
        // archive the agent writes here is also readable by the import tasks.
        $stateRoot = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        $dropRoot = rtrim((string) (getenv('ACP_IMPORT_DIR') ?: ''), '/');
        if ($dropRoot === '') {
            $dropRoot = $stateRoot . '/incoming';
        }

        $remote = new RemotePull($ctx->cmd, $ctx->log, $dropRoot);

        // ------------------------------------------------------------- probe ---
        if (($payload['probe'] ?? false) === true) {
            $probed = $remote->probe(
                (string) ($payload['host'] ?? ''),
                (int) ($payload['port'] ?? 22),
            );

            return [
                'probe' => true,
                'host' => $probed['host'],
                'port' => $probed['port'],
                'key_type' => $probed['key_type'],
                'fingerprint' => $probed['fingerprint'],
                'message' => "{$probed['host']} ka {$probed['key_type']} fingerprint: {$probed['fingerprint']} "
                    . '— isay verify karke host_fingerprint ke saath pull karo',
            ];
        }

        // -------------------------------------------------------------- pull ---
        try {
            $got = $remote->pull([
                'host' => (string) ($payload['host'] ?? ''),
                'port' => (int) ($payload['port'] ?? 22),
                'user' => (string) ($payload['user'] ?? ''),
                'remote_path' => (string) ($payload['remote_path'] ?? ''),
                'auth' => (string) ($payload['auth'] ?? 'key'),
                'private_key' => (string) ($payload['private_key'] ?? ''),
                'key_path' => (string) ($payload['key_path'] ?? ''),
                'password' => (string) ($payload['password'] ?? ''),
                'dest_name' => (string) ($payload['dest_name'] ?? ''),
                'sha256' => (string) ($payload['sha256'] ?? ''),
                'host_fingerprint' => (string) ($payload['host_fingerprint'] ?? ''),
                'accept_host_key' => ($payload['accept_host_key'] ?? false) === true,
                'max_kbps' => (int) ($payload['max_kbps'] ?? 0),
                'overwrite' => ($payload['overwrite'] ?? false) === true,
            ]);
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('remote pull failed safely: ' . $e->getMessage());
        }

        $ctx->log->info(
            "backup.pull: {$got['user']}@{$got['host']}:{$got['port']} se {$got['name']} "
            . "({$got['bytes']} bytes) drop dir me aa gaya"
        );

        return [
            'path' => $got['path'],
            'name' => $got['name'],
            'bytes' => $got['bytes'],
            'sha256' => $got['sha256'],
            'host' => $got['host'],
            'port' => $got['port'],
            'user' => $got['user'],
            'key_type' => $got['key_type'],
            'fingerprint' => $got['fingerprint'],
            'auth' => $got['auth'],
            'duration_ms' => $got['duration_ms'],
            'message' => "archive drop dir me hai: {$got['path']} — ab Transfer/Restore page se import karo",
        ];
    }
}
