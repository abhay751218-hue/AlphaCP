<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * domain.add — extra Apache vhost (addon / sub / parked / redirect).
 *
 * @acp-task domain.add
 */
final class DomainAdd implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $domain = strtolower((string) $payload['domain']);
        $type = (string) $payload['type'];
        $docroot = (string) $payload['document_root'];
        $err = AccountIdentity::username($username) ?? AccountIdentity::domain($domain);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if (!in_array($type, ['addon', 'sub', 'parked', 'redirect'], true)) {
            throw new TaskRejectedException('invalid domain type');
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('domain.add requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }

        if (in_array($type, ['addon', 'sub'], true)) {
            $os->ensureDocroot($username, $docroot);
        } elseif ($type !== 'redirect') {
            $os->assertDocrootInHome($username, $docroot);
        }

        $code = (int) ($payload['redirect_code'] ?? 301);
        $os->writeExtraVhost(
            $username,
            $domain,
            $type,
            $docroot,
            isset($payload['redirect_url']) ? (string) $payload['redirect_url'] : null,
            $code,
        );
        $os->reloadServices();
        $ctx->log->info("domain {$domain} ({$type}) added for {$username}");

        return [
            'username' => $username,
            'domain'   => $domain,
            'type'     => $type,
            'status'   => 'active',
        ];
    }
}
