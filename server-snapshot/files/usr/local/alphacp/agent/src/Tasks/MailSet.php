<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Mail;
use Alphacp\Agent\MailServer;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * mail.set — virtual mailboxes (passwd-file + Maildir) under the account home.
 *
 * @acp-task mail.set
 */
final class MailSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mail.set requires PathGuard roots');
        }
        $raw = $payload['mailboxes'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('mailboxes must be an array');
        }
        try {
            $boxes = Mail::sanitize($raw);
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setMail($username, $boxes);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username'  => $username,
            'mailboxes' => count($written),
            'mail_sync' => MailServer::syncIfConfigured($ctx->cmd, $ctx->log),
            'status'    => 'ok',
        ];
    }
}
