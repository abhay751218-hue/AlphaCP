<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use PDO;
use Throwable;

/**
 * Command line surface of paneld. Deliberately tiny: the real work happens
 * in Daemon/TaskRunner; this class only parses argv and prints.
 */
final class Cli
{
    public static function main(array $argv): int
    {
        $args = array_slice($argv, 1);

        if ($args === [] || in_array($args[0], ['-h', '--help'], true)) {
            self::usage();
            return 0;
        }

        try {
            return match ($args[0]) {
                '--version'            => self::version(),
                '--status'             => self::status(),
                '--selftest'           => self::selftest(),
                '--once'               => self::once(),
                '--daemon'             => self::daemon(),
                '--run'                => self::runImmediate(array_slice($args, 1)),
                '--tasks'              => self::listTasks(),
                '--recover-stale'      => self::recoverStale(),
                default                => self::unknown($args[0]),
            };
        } catch (Throwable $e) {
            fwrite(STDERR, 'paneld: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    // -------------------------------------------------------------------------
    //  commands
    // -------------------------------------------------------------------------

    private static function version(): int
    {
        fwrite(STDOUT, 'paneld ' . ACP_AGENT_VERSION . ' (php ' . PHP_VERSION . ")\n");
        return 0;
    }

    /** Machine-readable snapshot consumed by `alphacp status` / the panel. */
    private static function status(): int
    {
        $db     = Db::conn();
        $env    = Db::env();
        $server = (int) $env['server_id'];

        $counts = [];
        $stmt = $db->prepare('SELECT status, COUNT(*) AS n FROM tasks WHERE server_id = ? GROUP BY status');
        $stmt->execute([$server]);
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        $last = $db->prepare('SELECT id, type, status, duration_ms, finished_at FROM tasks WHERE server_id = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$server]);

        $payload = [
            'agent_version' => ACP_AGENT_VERSION,
            'pid'           => getmypid(),
            'server_id'     => $server,
            'db'            => 'ok',
            'queue'         => [
                'queued'  => $counts['queued']  ?? 0,
                'running' => $counts['running'] ?? 0,
                'failed'  => $counts['failed']  ?? 0,
                'success' => $counts['success'] ?? 0,
            ],
            'last_task'     => $last->fetch() ?: null,
            'task_types'    => array_keys(acp_task_registry()),
        ];

        fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    }

    /** Environment + registry sanity, no queue writes. */
    private static function selftest(): int
    {
        $fail = 0;
        $check = static function (string $label, callable $fn) use (&$fail): void {
            try {
                $value = $fn();
                fwrite(STDOUT, sprintf("[ OK ] %-42s %s\n", $label, is_scalar($value) ? (string) $value : ''));
            } catch (Throwable $e) {
                $fail++;
                fwrite(STDOUT, sprintf("[FAIL] %-42s %s\n", $label, $e->getMessage()));
            }
        };

        $check('database.env readable', static fn (): string => ACP_HOME . '/etc/database.env');
        $check('DB connection', static function (): string {
            $v = Db::conn()->query('SELECT VERSION()')->fetchColumn();
            return 'server ' . $v;
        });
        $check('tasks table', static function (): string {
            $n = Db::conn()->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
            return $n . ' rows';
        });
        $check('task registry', static function (): string {
            $r = acp_task_registry();
            if ($r === []) {
                throw new \RuntimeException('registry is empty');
            }
            return count($r) . ' task types';
        });
        $check('registry entries valid', static function (): string {
            foreach (acp_task_registry() as $type => $cfg) {
                foreach (['handler', 'safety', 'schema', 'description'] as $key) {
                    if (!isset($cfg[$key])) {
                        throw new \RuntimeException("{$type} is missing '{$key}'");
                    }
                }
                if (!class_exists((string) $cfg['handler'])) {
                    throw new \RuntimeException("{$type}: handler class not found");
                }
            }
            return 'all good';
        });
        $check('PathGuard blocks escapes', static function (): string {
            $guard = new PathGuard([ACP_HOME]);
            try {
                $guard->assert('/etc/shadow');
                throw new \RuntimeException('ESCAPE WAS NOT BLOCKED — investigate!');
            } catch (PathGuardException) {
                return 'escape blocked';
            }
        });
        $check('command allowlist blocks /bin/sh', static function (): string {
            try {
                (new CommandRunner(5))->run(['/bin/sh', '-c', 'id']);
                throw new \RuntimeException('ALLOWLIST FAILED — investigate!');
            } catch (\RuntimeException $e) {
                return str_contains($e->getMessage(), 'allowlist') ? 'blocked' : $e->getMessage();
            }
        });

        fwrite(STDOUT, $fail === 0 ? "\nSELFTEST PASSED\n" : "\nSELFTEST FAILED ({$fail})\n");
        return $fail === 0 ? 0 : 1;
    }

    private static function once(): int
    {
        $verbose = true;
        $runner  = new TaskRunner(Db::conn(), acp_task_registry(), $verbose);
        $daemon  = new Daemon(Db::conn(), $runner, (int) Db::env()['server_id'], 1000, $verbose);

        $ran = $daemon->tick();
        fwrite(STDOUT, $ran ? "processed 1 task\n" : "queue empty — nothing to do\n");
        return 0;
    }

    private static function daemon(): int
    {
        $runner = new TaskRunner(Db::conn(), acp_task_registry(), false);
        $daemon = new Daemon(Db::conn(), $runner, (int) Db::env()['server_id'], 1000, false);
        return $daemon->runForever();
    }

    /** TSV: type <TAB> safety <TAB> description — consumed by the alphacp CLI. */
    private static function listTasks(): int
    {
        foreach (acp_task_registry() as $type => $cfg) {
            fwrite(STDOUT, sprintf(
                "%s\t%s\t%s\n",
                $type,
                (string) ($cfg['safety'] ?? '?'),
                (string) ($cfg['description'] ?? ''),
            ));
        }
        return 0;
    }

    private static function recoverStale(): int
    {
        $runner = new TaskRunner(Db::conn(), acp_task_registry(), true);
        $daemon = new Daemon(Db::conn(), $runner, (int) Db::env()['server_id']);
        $n = $daemon->recoverStale();
        fwrite(STDOUT, "recovered {$n} stale task(s)\n");
        return 0;
    }

    /** paneld --run <type> [json] — synchronous, verbose, still audited. */
    private static function runImmediate(array $args): int
    {
        if ($args === []) {
            fwrite(STDERR, "usage: paneld --run <task.type> ['{\"json\":\"payload\"}']\n");
            return 2;
        }

        $type    = $args[0];
        $payload = [];
        if (isset($args[1])) {
            $payload = json_decode($args[1], true);
            if (!is_array($payload)) {
                fwrite(STDERR, "--run: payload must be a JSON object\n");
                return 2;
            }
        }

        $runner = new TaskRunner(Db::conn(), acp_task_registry(), true);
        $id     = $runner->runImmediate((int) Db::env()['server_id'], $type, $payload, 'cli');

        $row = Db::conn()->prepare('SELECT status, result, error, duration_ms FROM tasks WHERE id = ?');
        $row->execute([$id]);
        $task = $row->fetch() ?: [];

        fwrite(STDOUT, "\n" . json_encode([
            'task_id'     => $id,
            'status'      => $task['status'] ?? 'unknown',
            'duration_ms' => $task['duration_ms'] ?? null,
            'result'      => isset($task['result']) ? json_decode((string) $task['result'], true) : null,
            'error'       => $task['error'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return ($task['status'] ?? '') === 'success' ? 0 : 1;
    }

    private static function unknown(string $arg): int
    {
        fwrite(STDERR, "paneld: unknown option '{$arg}'\n\n");
        self::usage();
        return 2;
    }

    private static function usage(): void
    {
        fwrite(STDOUT, <<<TXT
        paneld — AlphaCP privileged task agent (v%s)

        Usage:
          paneld --daemon            run forever (systemd unit: paneld.service)
          paneld --once              claim & run at most one task, then exit
          paneld --status            JSON health snapshot
          paneld --selftest          verify env/db/registry/guards
          paneld --recover-stale     requeue tasks stuck in 'running'
          paneld --tasks             TSV list of allowlisted tasks (type/safety/desc)
          paneld --run TYPE [JSON]   run one task now, verbose (debug)
          paneld --version

        Tasks live in config/tasks.php (allowlist). Anything not listed
        there is refused — permanently.

        TXT, ACP_AGENT_VERSION);
    }
}
