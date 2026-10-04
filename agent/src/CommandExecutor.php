<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Array-exec runner used by task handlers.
 *
 * Production uses CommandRunner (binary allowlist + timeouts). Tests inject a
 * fake so account handlers can be proven without touching the real OS.
 */
interface CommandExecutor
{
    /**
     * @param  list<string> $argv full argv, argv[0] must be an absolute path
     * @param  string|null  $stdin raw script on stdin (small SQL, no secrets in argv)
     * @param  string|null  $stdinFile file streamed to stdin in chunks (large SQL dumps;
     *                      never loaded into memory)
     */
    public function run(array $argv, ?int $timeout = null, ?string $stdin = null, ?string $stdinFile = null): CommandResult;
}
