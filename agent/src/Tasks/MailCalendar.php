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
 * mail.calendar — calendar + contact names (JSON). No CalDAV/CardDAV daemon, no pipe.
 *
 * @acp-task mail.calendar
 */
final class MailCalendar implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mail.calendar requires PathGuard roots');
        }
        $rawCal = $payload['calendars'] ?? [];
        $rawCard = $payload['contacts'] ?? [];
        if (!is_array($rawCal) || !is_array($rawCard)) {
            throw new TaskRejectedException('calendars and contacts must be arrays');
        }
        $cfg = [
            'calendars' => Mail::sanitizeCalNames($rawCal, 'calendar'),
            'contacts' => Mail::sanitizeCalNames($rawCard, 'contact'),
        ];

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setCalendar($username, $cfg);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username'  => $username,
            'calendars' => count($written['calendars']),
            'contacts'  => count($written['contacts']),
            'status'    => 'ok',
        ];
    }
}
