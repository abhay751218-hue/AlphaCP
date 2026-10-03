<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Privacy;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * privacy.set — Apache Basic Auth (Directory Privacy) for account folders.
 *
 * @acp-task privacy.set
 */
final class PrivacySet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $raw = $payload['entries'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('entries must be an array');
        }
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('privacy.set requires PathGuard roots');
        }
        try {
            $entries = Privacy::sanitize($raw);
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setPrivacy($username, $entries);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info('privacy updated for ' . $username . ' (' . count($written) . ')');

        return [
            'username' => $username,
            'paths'    => array_column($written, 'path'),
            'status'   => 'active',
        ];
    }
}
