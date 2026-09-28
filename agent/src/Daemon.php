<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use PDO;

/**
 * paneld daemon: claims queued tasks and executes them, forever.
 *
 * Claim query uses SELECT ... FOR UPDATE SKIP LOCKED so multiple agents
 * (central + nodes in Step 15) never run the same task twice.
 */
final class Daemon
{
    private bool $stop = false;

    public function __construct(
        private readonly PDO $db,
        private readonly TaskRunner $runner,
        private readonly int $serverId,
        private readonly int $pollMs = 1000,
        private readonly bool $verbose = false,
    ) {
    }

    /** Handle SIGTERM/SIGINT gracefully when pcntl is available. */
    public function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return; // systemd will still kill us; claim recovery covers the rest
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                fwrite(STDOUT, "signal received — finishing current task then exiting\n");
                $this->stop = true;
            });
        }
    }

    /**
     * Requeue tasks stuck in `running` (agent crashed / was killed mid-task).
     * Returns number of recovered rows.
     */
    public function recoverStale(int $minutes = 20): int
    {
        $stmt = $this->db->prepare(
            'UPDATE tasks
                SET status = IF(attempts < max_attempts, "queued", "failed"),
                    error = CONCAT(COALESCE(error, ""), "[stale claim recovered]"),
                    claimed_at = NULL, finished_at = NOW(), updated_at = NOW()
              WHERE status = "running" AND claimed_at < (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->execute([$minutes]);
        $n = $stmt->rowCount();

        if ($n > 0) {
            Db::audit('task.stale_recovered', 'agent', null, null, null, 'warning', ['count' => $n, 'older_than_min' => $minutes]);
        }
        return $n;
    }

    /** Claim + execute one task. Returns true if something ran. */
    public function tick(): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM tasks
                  WHERE server_id = ? AND status = "queued" AND attempts < max_attempts
                  ORDER BY priority ASC, id ASC
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute([$this->serverId]);
            $task = $stmt->fetch();

            if ($task === false) {
                $this->db->commit();
                return false;
            }

            $this->db->prepare(
                'UPDATE tasks SET status = "running", attempts = attempts + 1,
                  claimed_at = NOW(), started_at = NOW(), updated_at = NOW() WHERE id = ?'
            )->execute([$task['id']]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $task['attempts'] = (int) $task['attempts'] + 1;
        if ($this->verbose) {
            fwrite(STDOUT, "claimed task #{$task['id']} ({$task['type']})\n");
        }

        $this->runner->execute($task);
        return true;
    }

    /** @return int exit code */
    public function runForever(): int
    {
        $this->installSignalHandlers();
        $recovered = $this->recoverStale();
        $this->logStart($recovered);

        $lastHeartbeat = 0.0;
        while (!$this->stop) {
            try {
                if ($this->tick()) {
                    continue; // drain the queue before sleeping
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, 'daemon error: ' . $e->getMessage() . "\n");
                usleep(2_000_000);
                continue;
            }

            if (microtime(true) - $lastHeartbeat > 30) {
                $this->heartbeat();
                $lastHeartbeat = microtime(true);
            }

            usleep($this->pollMs * 1000);
        }

        return 0;
    }

    private function heartbeat(): void
    {
        try {
            $this->db->prepare('UPDATE servers SET last_heartbeat_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute([$this->serverId]);
        } catch (\Throwable) {
            // heartbeat is best-effort
        }
    }

    private function logStart(int $recovered): void
    {
        Db::audit('agent.started', 'agent', null, 'server', $this->serverId, 'info', [
            'version' => ACP_AGENT_VERSION, 'pid' => getmypid(), 'stale_recovered' => $recovered,
        ]);
        fwrite(STDOUT, sprintf(
            "paneld v%s started (pid %d, server_id=%d, poll=%dms, recovered=%d)\n",
            ACP_AGENT_VERSION,
            getmypid(),
            $this->serverId,
            $this->pollMs,
            $recovered,
        ));
    }
}
