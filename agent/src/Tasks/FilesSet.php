<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Files;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;
use RuntimeException;

/**
 * files.set — mkdir / write / delete / rename under the account home.
 *
 * @acp-task files.set
 */
final class FilesSet implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = (string) $payload['username'];
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        if ($ctx->paths === null) {
            throw new TaskRejectedException('files.set requires PathGuard roots');
        }
        try {
            $op = Files::normalizeOp((string) ($payload['op'] ?? ''));
            $rel = Files::normalizeRel((string) ($payload['path'] ?? ''));
        } catch (TaskRejectedException $e) {
            throw $e;
        }
        if ($rel === '') {
            throw new TaskRejectedException('refusing to mutate account home root');
        }

        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("linux user '{$username}' is not an AlphaCP account");
        }
        try {
            $out = match ($op) {
                'mkdir' => $os->mkdirFile($username, $rel),
                'write' => $os->writeFile($username, $rel, (string) ($payload['content'] ?? '')),
                'delete' => $os->deleteFile($username, $rel),
                'rename' => $os->renameFile($username, $rel, (string) ($payload['to'] ?? '')),
                default => throw new TaskRejectedException('invalid files op'),
            };
        } catch (RuntimeException $e) {
            throw new TaskRejectedException($e->getMessage());
        }
        $ctx->log->info("files.{$op} {$rel} for {$username}");

        return [
            'username' => $username,
            'op'       => $op,
            'path'     => $rel,
            'result'   => $out,
            'status'   => 'ok',
        ];
    }
}
