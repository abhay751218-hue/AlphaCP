<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\MimeTypes;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * mime.set — Apache AddType mappings for the account vhost.
 *
 * @acp-task mime.set
 */
final class MimeTypesSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $raw = $payload['mappings'] ?? [];
        if (!is_array($raw)) {
            throw new TaskRejectedException('mappings must be an array');
        }
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('mime.set requires PathGuard roots');
        }

        try {
            $mappings = MimeTypes::sanitize($raw);
        } catch (TaskRejectedException $e) {
            throw $e;
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $written = $os->setMimeTypes($username, $mappings);
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info('mime types updated for ' . $username . ' (' . count($written) . ')');

        return [
            'username' => $username,
            'mappings' => $written,
            'status'   => 'active',
        ];
    }
}
