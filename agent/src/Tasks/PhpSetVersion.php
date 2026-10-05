<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * php.setVersion — move the account PHP-FPM pool to another MultiPHP version,
 * or (payload.domain diya ho to) sirf us domain ko apna pool + vhost socket do.
 *
 * @acp-task php.setVersion
 */
final class PhpSetVersion implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $php = (string) $payload['php_version'];
        $domain = isset($payload['domain']) && $payload['domain'] !== '' ? strtolower((string) $payload['domain']) : null;
        $err = AccountIdentity::username($username) ?? AccountIdentity::phpVersion($php);
        if ($err === null && $domain !== null) {
            $err = AccountIdentity::domain($domain);
        }
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

        if ($domain === null) {
            $os->setPhpVersion($username, $php);

            return [
                'username'    => $username,
                'php_version' => $php,
                'status'      => 'active',
            ];
        }

        try {
            $out = $os->setDomainPhp($username, $domain, $php);
        } catch (\RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username'    => $username,
            'php_version' => $out['php_version'],
            'domain'      => $domain,
            'pool'        => $out['pool'],
            'socket'      => $out['socket'],
            'vhosts'      => $out['vhosts'],
            'status'      => 'active',
        ];
    }
}
