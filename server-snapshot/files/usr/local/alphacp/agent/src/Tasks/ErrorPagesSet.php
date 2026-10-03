<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\ErrorPages;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * errorpages.set — custom 4xx/5xx HTML + Apache ErrorDocument snippet.
 *
 * @acp-task errorpages.set
 */
final class ErrorPagesSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $raw = $payload['pages'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('pages must be an object');
        }
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('errorpages.set requires PathGuard roots');
        }

        try {
            $pages = ErrorPages::sanitize($raw);
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setErrorPages($username, $pages);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info('error pages updated for ' . $username . ' (' . count($written) . ' codes)');

        return [
            'username' => $username,
            'pages'    => array_keys($written),
            'status'   => 'active',
        ];
    }
}
