<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * ssl.remove — drop :443 vhost. Cert files stay under ~/ssl.
 *
 * @acp-task ssl.remove
 */
final class SslRemove implements TaskInterface
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
            throw new TaskRejectedException('ssl.remove requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        $os->removeSslVhost($username, $domain);
        $os->reloadServices();
        $ctx->log->info("ssl {$domain} removed for {$username}");

        return [
            'username' => $username,
            'domain'   => $domain,
            'status'   => 'removed',
        ];
    }
}
