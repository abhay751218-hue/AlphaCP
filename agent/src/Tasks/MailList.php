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
 * mail.list — Exim distribution lists (static subscribers + owner). No shell/pipe.
 *
 * @acp-task mail.list
 */
final class MailList implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mail.list requires PathGuard roots');
        }
        $raw = $payload['lists'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('lists must be an array');
        }
        $rows = Mail::sanitizeLists($raw);

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setLists($username, $rows);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        $subscribers = array_sum(array_map(static fn (array $row): int => count($row['members']), $written));

        return [
            'username'   => $username,
            'lists'      => count($written),
            'subscribers'=> $subscribers,
            'mail_sync'  => MailServer::syncIfConfigured($ctx->cmd, $ctx->log),
            'status'     => 'ok',
        ];
    }
}
