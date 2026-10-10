<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * php.setVersion — move the account PHP-FPM pool to another MultiPHP version.
 *
 * @acp-task php.setVersion
 */
final class PhpSetVersion implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $php = (string) $payload['php_version'];
        $err = AccountIdentity::username($username) ?? AccountIdentity::phpVersion($php);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('php.setVersion requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        $os->setPhpVersion($username, $php);

        return [
            'username'    => $username,
            'php_version' => $php,
            'status'      => 'active',
        ];
    }
}
