<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Backup;
use Alphacp\Agent\BackupArchiveStore;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * db.restore — MySQL part of a cPanel import (S10): restore the `mysql/*.sql`
 * dumps of a cpmove/legacy archive into real MariaDB databases named
 * `<account>_<suffix>`.
 *
 * The archive is never trusted: same guards as the home import (allowlisted
 * path, sha256, hostile-member scan) plus a per-dump sanitiser — any dump that
 * tries to select another database or touch the filesystem is refused before a
 * single statement runs. Dumps are streamed to the client (nothing is buffered
 * in PHP) and the staging directory is always removed.
 *
 * @acp-task db.restore
 */
final class DbRestore implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('db.restore requires PathGuard roots');
        }
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));
        $action = Backup::normalizeCpanelAction((string) ($payload['action'] ?? 'restore'));
        $archivePath = trim((string) ($payload['archive_path'] ?? ''));
        $sha256 = trim((string) ($payload['sha256'] ?? ''));
        if ($archivePath === '') {
            throw new TaskRejectedException('db.restore needs the server path of a cPanel archive');
        }

        $only = [];
        if (isset($payload['only'])) {
            if (!is_array($payload['only'])) {
                throw new TaskRejectedException('db.restore only must be a list of database names');
            }
            foreach ($payload['only'] as $suffix) {
                $only[] = (string) $suffix;
            }
        }

        $paths = AccountPaths::fromEnv();
        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), $paths, $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("Linux user '{$username}' is not an AlphaCP account; create the account first");
        }

        $store = new BackupArchiveStore(
            $ctx->cmd,
            new SafeFs($ctx->paths),
            $paths,
            $ctx->log,
            rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/'),
        );
        try {
            return $store->importCpanelMysql($username, $archivePath, $sha256, $action, $only);
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('cPanel MySQL restore failed safely: ' . $e->getMessage());
        }
    }
}
