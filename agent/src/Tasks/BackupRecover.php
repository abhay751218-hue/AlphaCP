<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\BackupArchiveStore;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * backup.recover — restore a checksum-verified home archive into the account.
 *
 * Destructive (it replaces the live home), therefore TaskRunner requires
 * `_confirm='backup.recover'` in the payload. Everything privileged happens in
 * BackupArchiveStore::restoreHome(): staged extraction, symlink/setuid audit,
 * ownership transfer and an atomic swap with automatic rollback.
 *
 * Mail and database contents are intentionally not claimed until S7/S8 manage
 * real mailboxes and databases on the host.
 *
 * @acp-task backup.recover
 */
final class BackupRecover implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = strtolower(trim((string) ($payload['username'] ?? '')));
        $usernameError = AccountIdentity::username($username);
        if ($usernameError !== null) {
            throw new TaskRejectedException($usernameError);
        }
        $archiveId = BackupArchiveStore::normalizeId((string) ($payload['archive_id'] ?? ''));
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.recover requires PathGuard roots');
        }

        $paths = AccountPaths::fromEnv();
        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), $paths, $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("Linux user '{$username}' is not an AlphaCP account");
        }

        $stateRoot = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        $store = new BackupArchiveStore(
            $ctx->cmd,
            new SafeFs($ctx->paths),
            $paths,
            $ctx->log,
            $stateRoot,
        );
        try {
            return $store->restoreHome($username, $archiveId);
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('home restore failed safely: ' . $e->getMessage());
        }
    }
}
