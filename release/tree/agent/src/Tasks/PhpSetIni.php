<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\PhpIni;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * php.setIni — write allowlisted MultiPHP INI into the account FPM pool.
 *
 * @acp-task php.setIni
 */
final class PhpSetIni implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $raw = $payload['directives'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('directives must be an object');
        }
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('php.setIni requires PathGuard roots');
        }

        try {
            $directives = PhpIni::sanitize($raw);
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setIni($username, $directives);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info("php.ini updated for {$username} (" . count($written) . ' keys)');

        return [
            'username'   => $username,
            'directives' => $written,
            'status'     => 'active',
        ];
    }
}
