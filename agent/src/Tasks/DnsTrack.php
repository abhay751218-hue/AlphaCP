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
 * dns.track — search jailed zone/dynamic JSON by FQDN. No dig, no BIND.
 *
 * @acp-task dns.track
 */
final class DnsTrack implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('dns.track requires PathGuard roots');
        }
        $query = Dns::normalizeDomain((string) ($payload['query'] ?? ''));
        $type = Dns::normalizeTrackType((string) ($payload['type'] ?? 'ALL'));

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $hits = $os->trackDns($username, $query, $type);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'query'    => $query,
            'type'     => $type,
            'hits'     => $hits,
            'status'   => 'ok',
        ];
    }
}
