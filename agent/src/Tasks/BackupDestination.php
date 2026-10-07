<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\RemoteDestination;
use Alphacp\Agent\RemotePull;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * backup.destination — remote backup destinations (S10).
 *
 * Actions:
 *  - `list`   : saved destinations (no network, no secrets in the result)
 *  - `save`   : create/update a destination; host key must be pinned (or the
 *               first key explicitly accepted — logged loudly). Key auth
 *               generates a fresh ed25519 key and returns its public key, so
 *               the admin can install it on the backup server.
 *  - `test`   : connect, write+read+delete a probe file in the remote dir
 *  - `push`   : upload one archive from our backup store and verify sha256
 *               on the far side (atomic `.part` → `mv`)
 *  - `browse` : list the archives already sitting on the destination
 *  - `remove` : forget the destination and shred its key/password
 *
 * Secrets (private key, password) never appear in argv, logs or the result —
 * they live in 0600 files under `etc/backup-keys` and are only referenced.
 *
 * @acp-task backup.destination
 */
final class BackupDestination implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $stateRoot = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        if ($stateRoot === '' || $stateRoot === '/' || is_link($stateRoot)) {
            throw new TaskRejectedException('invalid agent state root');
        }
        $store = new RemoteDestination($ctx->cmd, $ctx->log, $stateRoot);

        try {
            $out = match ($action) {
                'list'   => $this->listAll($store),
                'save'   => $this->save($store, $payload),
                'test'   => $this->test($store, $payload),
                'push'   => $this->push($store, $payload),
                'browse' => $this->browse($store, $payload),
                'remove' => $this->remove($store, $payload),
                default  => throw new TaskRejectedException(
                    "backup.destination action '{$action}' nahi chalega (list/save/test/push/browse/remove)"
                ),
            };
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('backup destination fail (safe): ' . $e->getMessage());
        }

        return ['action' => $action] + $out;
    }

    /** @param array<string, mixed> $payload */
    private function listAll(RemoteDestination $store): array
    {
        return ['destinations' => $store->all(), 'count' => count($store->all())];
    }

    /** @param array<string, mixed> $payload */
    private function save(RemoteDestination $store, array $payload): array
    {
        $name = RemoteDestination::assertName((string) ($payload['name'] ?? ''));
        $destination = $store->save($payload);
        $this->forget($destination);

        return ['destination' => $destination, 'saved' => true];
    }

    /** @param array<string, mixed> $payload */
    private function test(RemoteDestination $store, array $payload): array
    {
        $name = RemoteDestination::assertName((string) ($payload['name'] ?? ''));

        return $store->test($name);
    }

    /** @param array<string, mixed> $payload */
    private function push(RemoteDestination $store, array $payload): array
    {
        $name = RemoteDestination::assertName((string) ($payload['name'] ?? ''));
        $archive = trim((string) ($payload['archive_path'] ?? ''));
        if ($archive === '') {
            throw new TaskRejectedException('archive_path do — kaunsa archive bhejna hai');
        }
        // Path pehle, destination baad me: galat path ko "store ke bahar" milna
        // chahiye, na ki "destination nahi mili".
        $stateRoot = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        RemoteDestination::assertArchivePath($archive, $stateRoot);

        return $store->push($name, $archive);
    }

    /** @param array<string, mixed> $payload */
    private function browse(RemoteDestination $store, array $payload): array
    {
        $name = RemoteDestination::assertName((string) ($payload['name'] ?? ''));

        return $store->browse($name);
    }

    /** @param array<string, mixed> $payload */
    private function remove(RemoteDestination $store, array $payload): array
    {
        $name = RemoteDestination::assertName((string) ($payload['name'] ?? ''));

        return $store->remove($name);
    }

    /** Belt and braces: never let a secret-ish key leak into the task result. */
    private function forget(array &$destination): void
    {
        unset($destination['password'], $destination['private_key'], $destination['key_path'], $destination['password_path']);
    }
}
