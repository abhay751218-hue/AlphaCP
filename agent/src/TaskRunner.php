<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use Alphacp\Agent\Tasks\TaskContext;
use Alphacp\Agent\Tasks\TaskInterface;
use PDO;
use Throwable;

/**
 * Lifecycle of one task row:
 *
 *   queued ──claim──► running ──handler──► success
 *                        │
 *                        ├─ rejected  → failed (no retry) + audit event
 *                        └─ error     → retry while attempts < max_attempts, else failed
 */
final class TaskRunner
{
    public function __construct(
        private readonly PDO $db,
        private readonly array $registry,
        private readonly bool $verbose = false,
    ) {
    }

    /** Validate a task type + payload WITHOUT executing it. */
    public function validate(string $type, array $payload): array
    {
        $config = $this->registry[$type] ?? null;
        if (!is_array($config)) {
            throw new TaskRejectedException("unknown task type: {$type}");
        }

        $safety = (string) ($config['safety'] ?? '');
        if (!in_array($safety, ['readonly', 'mutating', 'destructive'], true)) {
            throw new TaskRejectedException("task '{$type}' has no valid safety class");
        }

        $errors = JsonSchema::validate((array) ($config['schema'] ?? []), $payload);
        if ($errors !== []) {
            throw new TaskRejectedException("payload invalid for '{$type}': " . implode('; ', $errors));
        }

        // Destructive tasks need a typed confirmation string in the payload.
        if ($safety === 'destructive') {
            $expected = (string) ($config['confirm'] ?? $type);
            if (($payload['_confirm'] ?? null) !== $expected) {
                throw new TaskRejectedException("destructive task '{$type}' requires _confirm='{$expected}'");
            }
        }

        return $config;
    }

    /** Execute an already-claimed (status=running) task row. */
    public function execute(array $task): void
    {
        $taskId = (int) $task['id'];
        $type   = (string) $task['type'];

        $log = new TaskLogger($this->db, $taskId, $this->verbose);
        $started = hrtime(true);

        try {
            $payload = $this->decodePayload($task['payload'] ?? '{}');
            $config  = $this->validate($type, $payload);
            $safety  = (string) $config['safety'];

            $handlerClass = (string) ($config['handler'] ?? '');
            if ($handlerClass === '' || !class_exists($handlerClass)) {
                throw new TaskRejectedException("task '{$type}' has no handler class");
            }

            /** @var TaskInterface $handler */
            $handler = new $handlerClass();

            $ctx = new TaskContext(
                log: $log,
                cmd: new CommandRunner((int) ($config['timeout'] ?? 30)),
                paths: isset($config['paths']) ? PathGuard::fromConfig($config) : null,
                taskId: $taskId,
                taskRow: $task,
            );

            $log->info("running {$type} (safety={$safety}, attempt {$task['attempts']})");
            $result = $handler->handle($payload, $ctx);
            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

            $this->db->prepare(
                'UPDATE tasks SET status = "success", result = ?, error = NULL,
                  finished_at = NOW(), duration_ms = ?, updated_at = NOW() WHERE id = ?'
            )->execute([json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $durationMs, $taskId]);

            $scrubbed = self::scrubSecrets($payload);
            if ($scrubbed !== $payload) {
                try {
                    $this->db->prepare('UPDATE tasks SET payload = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([json_encode($scrubbed, JSON_UNESCAPED_SLASHES), $taskId]);
                } catch (Throwable $e) {
                    $log->warning('could not scrub the stored payload: ' . $e->getMessage());
                }
            }

            $log->info("done in {$durationMs}ms");
            Db::audit('task.executed', 'agent', null, 'task', $taskId, 'info', [
                'type' => $type, 'safety' => $safety, 'duration_ms' => $durationMs, 'attempt' => (int) $task['attempts'],
            ]);
        } catch (TaskRejectedException $e) {
            $this->fail($taskId, $type, $e->getMessage(), $log, retry: false);
        } catch (Throwable $e) {
            $attempts    = (int) ($task['attempts'] ?? 1);
            $maxAttempts = (int) ($task['max_attempts'] ?? 1);
            $this->fail($taskId, $type, $e->getMessage(), $log, retry: $attempts < $maxAttempts);
        }
    }

    private function fail(int $taskId, string $type, string $message, TaskLogger $log, bool $retry): void
    {
        $log->error($message . ($retry ? ' — will retry' : ''));

        $this->db->prepare(
            'UPDATE tasks
                SET status = ?,
                    error = ?,
                    finished_at = ' . ($retry ? 'NULL' : 'NOW()') . ',
                    duration_ms = ' . ($retry ? 'NULL' : 'TIMESTAMPDIFF(MICROSECOND, started_at, NOW()) DIV 1000') . ',
                    updated_at = NOW(),
                    claimed_at = NULL
              WHERE id = ?'
        )->execute([$retry ? 'queued' : 'failed', $message, $taskId]);

        Db::audit('security.agent.rejected', 'agent', null, 'task', $taskId, $retry ? 'warning' : 'critical', [
            'type' => $type, 'error' => $message, 'retry' => $retry,
        ]);
    }

    /**
     * A finished task must not leave its secret in the queue.
     *
     * The panel sends a database-user password as part of the payload (that is
     * how the agent receives it at all); once the task has succeeded the stored
     * payload is rewritten with `***`, so a later DB dump or Task Queue view
     * does not expose a live MariaDB password. Failed tasks keep the payload so
     * a retry can still work.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function scrubSecrets(array $payload): array
    {
        if (isset($payload['password']) && is_string($payload['password'])
            && $payload['password'] !== '' && $payload['password'] !== '***') {
            $payload['password'] = '***';
        }
        // S10 remote pull: an inline SSH private key is just as sensitive as a
        // password — the job history must never keep it after the task finished.
        if (isset($payload['private_key']) && is_string($payload['private_key'])
            && $payload['private_key'] !== '' && $payload['private_key'] !== '***') {
            $payload['private_key'] = '***';
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new TaskRejectedException('payload is not a JSON object');
        }
        return $decoded;
    }

    /** Debug/CLI path: insert a synthetic row and run it immediately. */
    public function runImmediate(int $serverId, string $type, array $payload, string $source = 'cli'): int
    {
        $safety = (string) ($this->registry[$type]['safety'] ?? '');
        if ($safety === '') {
            throw new TaskRejectedException("unknown task type: {$type}");
        }

        $this->db->prepare(
            'INSERT INTO tasks (server_id, type, safety, payload, status, attempts, requested_src, started_at, claimed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, "running", 1, ?, NOW(), NOW(), NOW(), NOW())'
        )->execute([$serverId, $type, $safety, json_encode($payload, JSON_UNESCAPED_SLASHES), $source]);

        $id   = (int) $this->db->lastInsertId();
        $task = $this->db->query("SELECT * FROM tasks WHERE id = {$id}")->fetch();
        $this->execute($task);

        return $id;
    }
}
