<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * domain.remove — drop extra vhost. Docroot files stay (cPanel-like).
 *
 * @acp-task domain.remove
 */
final class DomainRemove implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $domain = strtolower((string) $payload['domain']);
        $err = AccountIdentity::username($username) ?? AccountIdentity::domain($domain);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('domain.remove requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        $os->removeExtraVhost($username, $domain);
        $os->reloadServices();
        $ctx->log->info("domain {$domain} removed for {$username}");

        return [
            'username' => $username,
            'domain'   => $domain,
            'status'   => 'removed',
        ];
    }
}
