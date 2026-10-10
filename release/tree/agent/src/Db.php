<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database access for the agent.
 *
 * Credentials live in ACP_HOME/etc/database.env (mode 0600, written by the
 * installer). We never hardcode credentials and never log the password.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** @return array{host:string,port:string,name:string,user:string,pass:string,server_id:int} */
    public static function env(): array
    {
        static $env = null;
        if ($env !== null) {
            return $env;
        }

        $file = ACP_HOME . '/etc/database.env';
        if (!is_file($file)) {
            throw new RuntimeException("Missing {$file} — run installer/step2-install.sh first.");
        }

        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $values[trim($k)] = trim($v, " \t\"'");
        }

        foreach (['ACP_DB_HOST', 'ACP_DB_PORT', 'ACP_DB_NAME', 'ACP_DB_USER', 'ACP_DB_PASS'] as $key) {
            if (!isset($values[$key]) || $values[$key] === '') {
                throw new RuntimeException("database.env: missing {$key}");
            }
        }

        return $env = [
            'host'      => $values['ACP_DB_HOST'],
            'port'      => $values['ACP_DB_PORT'],
            'name'      => $values['ACP_DB_NAME'],
            'user'      => $values['ACP_DB_USER'],
            'pass'      => $values['ACP_DB_PASS'],
            'server_id' => (int) ($values['ACP_SERVER_ID'] ?? 1),
        ];
    }

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $env = self::env();
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['host'], $env['port'], $env['name']);

        try {
            self::$pdo = new PDO($dsn, $env['user'], $env['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Never leak the password in the message.
            throw new RuntimeException('DB connection failed: ' . $e->getMessage());
        }

        return self::$pdo;
    }

    /** @param array<string|int, mixed> $params */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Write an immutable audit row. Audit failures must never break a task,
     * so callers use this best-effort (exceptions swallowed + logged to stderr).
     *
     * @param array<string, mixed> $meta
     */
    public static function audit(
        string $action,
        string $actorType = 'agent',
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        string $severity = 'info',
        array $meta = []
    ): void {
        try {
            self::query(
                'INSERT INTO audit_logs (actor_type, actor_id, action, target_type, target_id, severity, meta, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$actorType, $actorId, $action, $targetType, $targetId, $severity,
                    $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, "audit write failed ({$action}): {$e->getMessage()}\n");
        }
    }
}
