<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\MailServer;
use Alphacp\Agent\TaskRejectedException;
use Throwable;

/**
 * mail.server — Exim4 + Dovecot ko asli mailboxes ke saath chalana (S7).
 *
 * Panel/agent mailboxes ko pehle se likhte hain (bcrypt + Maildir), par koi
 * daemon unhe padhta hi nahi tha. Ye task wo pul hai:
 *  - `setup`  : config likho (backup + validate + restore on failure) aur
 *               exim4/dovecot chalu karo — har update par chalaya ja sakta hai
 *  - `sync`   : har account ke ~/etc/mail se aggregate files dobara banao
 *               (naya mailbox / forwarder turant kaam kare, restart ki zaroorat nahi)
 *  - `verify` : `exim4 -bt <address>` (asli routing) + `doveadm user <address>`
 *               (asli mailbox) — hamara daawa nahi, daemons ka jawab
 *  - `status` : versions, config valid hai ya nahi, kitne domains/mailboxes
 *  - `list`   : kaunse mailbox is server par hain
 *
 * @acp-task mail.server
 */
final class MailServerSetup implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $server = new MailServer($ctx->cmd, $ctx->log);

        try {
            $out = match ($action) {
                'status' => $server->status(),
                'setup'  => $server->setup(),
                'sync'   => $server->syncFiles(),
                'list'   => $this->listMailboxes($server),
                'verify'        => $server->verify((string) ($payload['address'] ?? '')),
                'deliverability' => $server->deliverability(
                    trim((string) ($payload['username'] ?? '')) === '' ? null : strtolower(trim((string) $payload['username']))
                ),
                default  => throw new TaskRejectedException(
                    "mail.server action '{$action}' nahi chalega (status/setup/sync/list/verify)"
                ),
            };
        } catch (TaskRejectedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TaskRejectedException('mail.server fail (safe): ' . $e->getMessage());
        }

        return ['action' => $action] + $out;
    }

    /** @return array<string, mixed> */
    private function listMailboxes(MailServer $server): array
    {
        $boxes = $server->mailboxes();
        sort($boxes);

        return ['mailboxes' => $boxes, 'count' => count($boxes)];
    }
}
