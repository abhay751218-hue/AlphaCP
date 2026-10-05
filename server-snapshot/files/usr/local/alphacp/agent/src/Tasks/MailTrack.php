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
 * mail.track — ~/etc/mail/track.json + ASLI exim mainlog se delivery trace (cPanel #19).
 * Ab sirf JSON nahi: agar mail server configured hai to log khud jawab deta hai
 * (kab aayi, kahan pahunchi, defer/fail hui ya nahi). No pipe, no shell.
 *
 * @acp-task mail.track
 */
final class MailTrack implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mail.track requires PathGuard roots');
        }
        $query = Mail::normalizeDest((string) ($payload['query'] ?? ''));

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $hits = $os->track($username, $query);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        // ASLI delivery trace: exim ka mainlog khud batata hai ki mail kahan pahunchi
        // (cPanel #19). Mail server configured na ho to jhoothi report nahi — wajah batao.
        $trace = ['ok' => false, 'error' => 'mail server configured nahi hai — pehle mail.server setup chalao'];
        if (MailServer::isConfigured()) {
            try {
                $trace = (new MailServer($ctx->cmd, $ctx->log))->track($query);
            } catch (TaskRejectedException $e) {
                $trace = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'username' => $username,
            'query'    => $query,
            'hits'     => $hits,
            'log'      => $trace,
            'status'   => 'ok',
        ];
    }
}
