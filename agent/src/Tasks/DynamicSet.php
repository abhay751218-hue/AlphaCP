<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Dns;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * dns.dynamic — Dynamic DNS hosts (JSON). No BIND rewrite. No public updater.
 *
 * @acp-task dns.dynamic
 */
final class DynamicSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.dynamic requires PathGuard roots');
        }
        $raw = $payload['hosts'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('hosts must be an array');
        }
        $rows = Dns::sanitizeDynamic($raw);

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setDynamic($username, $rows);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'hosts'    => count($written),
            'status'   => 'ok',
        ];
    }
}
