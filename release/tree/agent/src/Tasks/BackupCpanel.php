<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\Backup;
use Alphacp\Agent\BackupArchiveStore;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * backup.cpanel — import a cPanel account archive (`cpmove-<user>.tar.gz` or a
 * legacy `backup-*.tar.gz`) into an existing AlphaCP account.
 *
 * Destructive: it replaces the account home, so it needs `_confirm` and the
 * engine keeps a pre-restore copy (`/home/.acp-prerestore-<user>-<stamp>`).
 * Only the `homedir` subtree is imported; MySQL dumps, DNS zones, mail and
 * cPanel userdata are reported (never extracted) until the later S10 steps.
 *
 * @acp-task backup.cpanel
 */
final class BackupCpanel implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.cpanel requires PathGuard roots');
        }
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));
        $action = Backup::normalizeCpanelAction((string) ($payload['action'] ?? ''));
        $archivePath = trim((string) ($payload['archive_path'] ?? ''));
        $sha256 = trim((string) ($payload['sha256'] ?? ''));
        if ($archivePath === '') {
            throw new TaskRejectedException('backup.cpanel needs the server path of a cPanel archive');
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
            return $store->importCpanelHome($username, $archivePath, $sha256, $action);
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('cPanel import failed safely: ' . $e->getMessage());
        }
    }
}
