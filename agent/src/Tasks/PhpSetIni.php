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

        $domain = isset($payload['domain']) && $payload['domain'] !== '' ? strtolower((string) $payload['domain']) : null;
        $phpVersion = isset($payload['php_version']) && $payload['php_version'] !== '' ? (string) $payload['php_version'] : null;
        if ($domain !== null) {
            $cerr = AccountIdentity::domain($domain) ?? ($phpVersion === null ? 'php_version is required for a per-domain INI' : AccountIdentity::phpVersion($phpVersion));
            if ($cerr !== null) {
                throw new TaskRejectedException($cerr);
            }
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $domain === null
                ? $os->setIni($username, $directives)
                : $os->setDomainIni($username, $domain, (string) $phpVersion, $directives);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info('php.ini updated for ' . ($domain ?? $username) . ' (' . count($written) . ' keys)');

        return [
            'username'   => $username,
            'domain'     => $domain,
            'directives' => $written,
            'status'     => 'active',
        ];
    }
}
