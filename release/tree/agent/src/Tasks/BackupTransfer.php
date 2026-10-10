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
 * backup.transfer — WHM Transfer Tool: migrate a cPanel account onto this
 * server from a cPanel archive the operator placed on the box.
 *
 * Real work now: the local archive is imported into the account home with the
 * same verified pipeline as backup.cpanel. The source FQDN is recorded in the
 * job result for the audit trail; an authenticated pull directly from the old
 * server (SSH/API) is still a later S10 step.
 *
 * @acp-task backup.transfer
 */
final class BackupTransfer implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('backup.transfer requires PathGuard roots');
        }
        $username = Backup::normalizeRestoreUsername((string) ($payload['username'] ?? ''));
        $source = Backup::normalizeTransferSource((string) ($payload['source'] ?? ''));
        $archivePath = trim((string) ($payload['archive_path'] ?? ''));
        $sha256 = trim((string) ($payload['sha256'] ?? ''));
        if ($archivePath === '') {
            throw new TaskRejectedException('backup.transfer needs the server path of a cPanel archive');
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
            $result = $store->importCpanelHome($username, $archivePath, $sha256, 'transfer');
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('cPanel transfer failed safely: ' . $e->getMessage());
        }
        $result['source'] = $source;

        return $result;
    }
}
