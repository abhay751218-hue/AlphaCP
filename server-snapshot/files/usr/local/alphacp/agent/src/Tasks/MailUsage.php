<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * mail.usage — folder-wise size under ~/mail. No purge, no symlink follow.
 *
 * @acp-task mail.usage
 */
final class MailUsage implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mail.usage requires PathGuard roots');
        }
        try {
            $rel = Files::normalizeRel((string) ($payload['path'] ?? ''));
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $usage = $os->mailUsage($username, $rel);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username'  => $username,
            'path'      => $rel,
            'bytes'     => $usage['bytes'],
            'truncated' => $usage['truncated'],
            'entries'   => $usage['entries'],
            'status'    => 'ok',
        ];
    }
}
