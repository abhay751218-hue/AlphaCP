<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\Ssh;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * ssh.set — authorized_keys + optional nologin/bash under the account home.
 *
 * @acp-task ssh.set
 */
final class SshSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('ssh.set requires PathGuard roots');
        }
        $raw = $payload['keys'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('keys must be an array');
        }
        try {
            $keys = Ssh::sanitizeKeys($raw);
            $shell = null;
            if (array_key_exists('shell', $payload) && $payload['shell'] !== null && $payload['shell'] !== '') {
                $shell = Ssh::normalizeShell((string) $payload['shell']);
            }
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setSsh($username, $keys, $shell);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }

        return [
            'username' => $username,
            'keys'     => count($written['keys']),
            'shell'    => $written['shell'],
            'status'   => 'ok',
        ];
    }
}
