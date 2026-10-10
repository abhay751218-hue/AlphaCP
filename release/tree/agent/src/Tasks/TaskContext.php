<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\CommandExecutor;
use Alphacp\Agent\PathGuard;
use Alphacp\Agent\TaskLogger;

/**
 * Everything a handler is allowed to touch: a logger, an array-exec runner
 * and (optionally) a PathGuard built from the task's allowlisted roots.
 * Handlers get NO direct PDO, NO shell, NO filesystem helpers of their own.
 */
final readonly class TaskContext
{
    public function __construct(
        public TaskLogger $log,
        public CommandExecutor $cmd,
        public ?PathGuard $paths = null,
        public ?int $taskId = null,
        public ?array $taskRow = null,
    ) {
    }
}
