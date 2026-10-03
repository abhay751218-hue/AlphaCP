<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Mail;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * mail.forward — email forwarders (aliases file under account home). No pipes.
 *
 * @acp-task mail.forward
 */
final class MailForward implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mail.forward requires PathGuard roots');
        }
        $raw = $payload['forwards'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('forwards must be an array');
        }
        try {
            $rows = Mail::sanitizeForwards($raw);
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setForwards($username, $rows);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'forwards' => count($written),
            'status'   => 'ok',
        ];
    }
}
