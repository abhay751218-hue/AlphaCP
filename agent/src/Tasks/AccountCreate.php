<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * account.create — Linux user, home, Apache vhost, PHP-FPM pool, quota.
 * Multi-step with compensating rollback. Idempotent if the user is ours.
 *
 * @acp-task account.create
 */
final class AccountCreate implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $domain = strtolower((string) $payload['domain']);
        $this->assertIdentity($username, $domain);

        $phpVersion = (string) ($payload['php_version'] ?? AccountPaths::detectPhpVersion());
        if (AccountIdentity::phpVersion($phpVersion) !== null) {
            throw new TaskRejectedException('invalid php_version');
        }

        $os = $this->os($ctx, $phpVersion);
        $done = [];

        try {
            $os->createUser($username, $domain, (string) $payload['shadow_hash']);
            $done[] = 'user';

            $home = $os->ensureHome($username, $domain);
            $done[] = 'home';

            $os->writeLiveVhost($username, $domain);
            $done[] = 'vhost';

            $os->writePool($username);
            $done[] = 'pool';

            $quotaMb = (int) ($payload['quota_mb'] ?? -1);
            $quota = $os->setQuota($username, $quotaMb);
            $done[] = 'quota';

            $os->reloadServices();
            $done[] = 'reload';
        } catch (Throwable $e) {
            $ctx->log->error('create failed, rollback: ' . $e->getMessage());
            $this->rollback($os, $username, $done, $ctx);
            throw $e;
        }

        $ctx->log->info("account {$username} ready");

        return [
            'username'    => $username,
            'domain'      => $domain,
            'home'        => $home,
            'php_version' => $phpVersion,
            'quota'       => $quota,
            'steps'       => $done,
        ];
    }

    private function assertIdentity(string $username, string $domain): void
    {
        $userErr = AccountIdentity::username($username);
        if ($userErr !== null) {
            throw new TaskRejectedException($userErr);
        }
        $domainErr = AccountIdentity::domain($domain);
        if ($domainErr !== null) {
            throw new TaskRejectedException($domainErr);
        }
    }

    private function os(TaskContext $ctx, string $phpVersion): AccountOs
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('account.create requires PathGuard roots');
        }
        return new AccountOs(
            $ctx->cmd,
            new SafeFs($ctx->paths),
            AccountPaths::fromEnv($phpVersion),
            $ctx->log,
        );
    }

    /** @param list<string> $done */
    private function rollback(AccountOs $os, string $username, array $done, TaskContext $ctx): void
    {
        $done = array_reverse($done);
        foreach ($done as $step) {
            try {
                match ($step) {
                    'reload', 'quota' => $os->setQuota($username, 0),
                    'pool' => $os->removePool($username),
                    'vhost' => $os->removeVhost($username),
                    'home', 'user' => $os->deleteUser($username),
                    default => null,
                };
            } catch (Throwable $e) {
                $ctx->log->warning("rollback {$step} failed: " . $e->getMessage());
            }
        }
        try {
            $os->reloadServices();
        } catch (Throwable $e) {
            $ctx->log->warning('rollback reload failed: ' . $e->getMessage());
        }
    }
}
