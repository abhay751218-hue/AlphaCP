<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use PDO;

/**
 * Writes task progress to `task_logs` (streamed to the panel UI later)
 * and optionally echoes to stdout for `paneld --once` debugging.
 */
final class TaskLogger
{
    public function __construct(
        private readonly PDO $db,
        private readonly ?int $taskId = null,
        private readonly bool $echo = false,
    ) {
    }

    public function debug(string $line): void
    {
        $this->write('debug', $line);
    }

    public function info(string $line): void
    {
        $this->write('info', $line);
    }

    public function warning(string $line): void
    {
        $this->write('warning', $line);
    }

    public function error(string $line): void
    {
        $this->write('error', $line);
    }

    public function write(string $level, string $line): void
    {
        if ($this->echo) {
            fwrite(STDOUT, sprintf("[%-7s] %s\n", strtoupper($level), $line));
        }

        if ($this->taskId === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare('INSERT INTO task_logs (task_id, level, line) VALUES (?, ?, ?)');
            $clipped = function_exists('mb_substr') ? mb_substr($line, 0, 4000) : substr($line, 0, 4000);
            $stmt->execute([$this->taskId, $level, $clipped]);
        } catch (\Throwable $e) {
            // logging must never kill a task
            fwrite(STDERR, "task_log write failed: {$e->getMessage()}\n");
        }
    }
}
