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
 * backup.extract — restore a previously published, checksum-verified home
 * archive back into the account home.
 *
 * Destructive: replaces current files, so it needs `_confirm` and the engine
 * keeps a pre-restore copy (`/home/.acp-prerestore-<user>-<stamp>`) that can be
 * moved back by hand. `path` restores a single subtree (cPanel File and
 * Directory Restoration); omit it to restore the whole home.
 *
 * @acp-task backup.extract
 */
final class BackupExtract implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = strtolower(trim((string) ($payload['username'] ?? '')));
        $usernameError = AccountIdentity::username($username);
        if ($usernameError !== null) {
            throw new TaskRejectedException($usernameError);
        }
        $archiveId = BackupArchiveStore::normalizeId((string) ($payload['archive_id'] ?? ''));
        $subPath = BackupArchiveStore::normalizeSubPath((string) ($payload['path'] ?? ''));
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.extract requires PathGuard roots');
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
            return $store->restoreHome($username, $archiveId, $subPath);
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('home restore failed safely: ' . $e->getMessage());
        }
    }
}
