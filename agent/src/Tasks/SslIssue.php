<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * ssl.issue — Let's Encrypt (certbot webroot) or self-signed + Apache :443 vhost.
 *
 * @acp-task ssl.issue
 */
final class SslIssue implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $domain = strtolower((string) $payload['domain']);
        $docroot = (string) $payload['document_root'];
        $mode = (string) ($payload['mode'] ?? 'letsencrypt');
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $err = AccountIdentity::username($username) ?? AccountIdentity::domain($domain);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if (!in_array($mode, ['selfsigned', 'letsencrypt'], true)) {
            throw new TaskRejectedException('invalid ssl mode');
        }
        if ($email !== '' && !str_contains($email, '@')) {
            throw new TaskRejectedException('invalid email');
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('ssl.issue requires PathGuard roots');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }

        try {
            $issued = $mode === 'letsencrypt'
                ? $os->issueLetsEncrypt($username, $domain, $docroot, $email)
                : $os->issueSelfSigned($username, $domain);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $os->writeSslVhost($username, $domain, $docroot, $issued['cert'], $issued['key']);
        $os->reloadServices();
        $ctx->log->info("ssl {$domain} issued ({$issued['issuer']})");

        return [
            'username'  => $username,
            'domain'    => $domain,
            'issuer'    => $issued['issuer'],
            'not_after' => $issued['not_after'],
            'cert'      => $issued['cert'],
            'status'    => 'active',
        ];
    }
}
