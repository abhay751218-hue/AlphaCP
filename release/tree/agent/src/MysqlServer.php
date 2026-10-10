<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * The ONLY place the agent talks to MariaDB/MySQL.
 *
 * Design rules (mirrors docs/03-security-matrix.md, S8 "real db.* tasks"):
 *  - SQL always travels on **stdin** (or a streamed stdinFile for dumps) — never
 *    in argv, so a password or an identifier can never leak into `ps`/logs.
 *  - every identifier is validated + backtick-quoted, every literal is
 *    single-quoted with embedded quotes doubled — no string is ever concatenated
 *    raw into a statement.
 *  - the client binary comes from ACP_MYSQL_CLIENT (default /usr/bin/mariadb) and
 *    must be an absolute path inside CommandRunner's allowlist.
 *  - a failing client surfaces as a clean TaskRejectedException that carries NO
 *    SQL fragment (the detail goes to the task log instead).
 *
 * The class was referenced by DbTask, the Db handlers, CpanelMysql and
 * BackupArchiveStore, but the file never reached the server — so every real
 * db task (create, drop, user management, list, restore) died with
 * "Class MysqlServer not found". This restores it.
 */
final class MysqlServer
{
    /** Password length window enforced before a password ever reaches SQL. */
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 64;

    /** Default timeout (seconds) for one DDL/DML round-trip. */
    private const SQL_TIMEOUT = 120;

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    // ------------------------------------------------------------------
    //  Static helpers (pure, no I/O) — used by the Db* handlers directly.
    // ------------------------------------------------------------------

    /**
     * Build the account-prefixed object name `<user>_<suffix>` (cPanel style).
     *
     * @param  string $username hosting account (already validated by AccountIdentity)
     * @param  string $suffix   customer-chosen suffix (already validated by DbTask::suffix)
     * @param  string $label    human name used in the rejection message
     */
    public static function accountName(string $username, string $suffix, string $label): string
    {
        $name = strtolower(trim($username)) . '_' . strtolower(trim($suffix));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) !== 1) {
            throw new TaskRejectedException("invalid {$label}: only letters/numbers/_ , 2-64 chars, letter first");
        }

        return $name;
    }

    /** Backtick-quote a validated SQL identifier (backticks inside are doubled). */
    public static function quoteIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new TaskRejectedException('invalid SQL identifier');
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Single-quote a SQL literal, doubling any embedded single quote.
     *
     * NUL / CR / LF are refused outright — a literal must stay on one line so it
     * can never smuggle a second statement past a line-based scanner.
     *
     * @param  string $label human name used in the rejection message
     */
    public static function literal(string $value, string $label = ''): string
    {
        $what = $label !== '' ? $label : 'value';
        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new TaskRejectedException("invalid {$what}: control characters are not allowed");
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** `'user'@'host'` account clause (both sides quoted as literals). */
    public static function account(string $user, string $host): string
    {
        return self::literal($user, 'user') . '@' . self::literal($host, 'host');
    }

    /**
     * Validate a customer database password and return it unchanged.
     *
     * Refused: wrong length, single quote, backslash, any control character.
     * The panel never generates such a password, so refusing is fail-closed and
     * keeps the value safe to embed through literal().
     */
    public static function password(string $password): string
    {
        $len = strlen($password);
        if ($len < self::PASSWORD_MIN || $len > self::PASSWORD_MAX) {
            throw new TaskRejectedException(
                'database password must be ' . self::PASSWORD_MIN . '-' . self::PASSWORD_MAX . ' characters'
            );
        }
        if (str_contains($password, "'") || str_contains($password, '\\')) {
            throw new TaskRejectedException('database password may not contain a quote or a backslash');
        }
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($password[$i]);
            if ($ord < 32 || $ord === 127) {
                throw new TaskRejectedException('database password may not contain control characters');
            }
        }

        return $password;
    }

    // ------------------------------------------------------------------
    //  Instance methods — the real conversation with the client.
    // ------------------------------------------------------------------

    /** Run a SQL script on stdin; throw a clean rejection if the client fails. */
    public function sql(string $sql): void
    {
        $this->run($sql);
    }

    /** Does database `<db>` already exist on this server? */
    public function databaseExists(string $database): bool
    {
        $out = $this->run(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '
            . self::literal($database, 'database') . ';'
        );

        return trim($out) !== '';
    }

    /** Does MariaDB user `<user>@<host>` already exist? */
    public function userExists(string $user, string $host = 'localhost'): bool
    {
        $out = $this->run(
            'SELECT User FROM mysql.user WHERE User = ' . self::literal($user, 'user')
            . ' AND Host = ' . self::literal($host, 'host') . ';'
        );

        return trim($out) !== '';
    }

    /**
     * Every database owned by the account (`<username>_*`), sorted.
     *
     * @return list<string>
     */
    public function accountDatabases(string $username): array
    {
        $out = $this->run('SHOW DATABASES;');
        $prefix = strtolower($username) . '_';
        $found = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with(strtolower($line), $prefix)) {
                $found[] = $line;
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Every MariaDB user row owned by the account, with the databases each can
     * reach. Shape: [{user, host, databases: [..]}, ...].
     *
     * @return list<array{user: string, host: string, databases: list<string>}>
     */
    public function accountUsers(string $username): array
    {
        $out = $this->run(
            'SELECT User, Host FROM mysql.user WHERE User LIKE '
            . self::literal(strtolower($username) . '_%', 'user') . ';'
        );
        $prefix = strtolower($username) . '_';
        $users = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\t/', $line) ?: [];
            $user = strtolower(trim((string) ($parts[0] ?? '')));
            $host = trim((string) ($parts[1] ?? 'localhost'));
            if ($user === '' || !str_starts_with($user, $prefix)) {
                continue;
            }
            $users[] = [
                'user'      => $user,
                'host'      => $host !== '' ? $host : 'localhost',
                'databases' => $this->grantedDatabases($user, $host !== '' ? $host : 'localhost'),
            ];
        }

        return $users;
    }

    /**
     * Databases on which `<user>@<host>` holds ALL PRIVILEGES (parsed from
     * SHOW GRANTS; the USAGE line and any non-account grant are ignored).
     *
     * @return list<string>
     */
    public function grantedDatabases(string $user, string $host = 'localhost'): array
    {
        $out = $this->run('SHOW GRANTS FOR ' . self::account($user, $host) . ';');
        $found = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            if (preg_match('/GRANT ALL PRIVILEGES ON `([A-Za-z0-9_]+)`\.\*/i', $line, $m) === 1) {
                $found[] = $m[1];
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Create `<db>` (utf8mb4) if it is missing.
     *
     * @return bool true when this call created it, false when it already existed
     */
    public function createDatabase(string $database): bool
    {
        if ($this->databaseExists($database)) {
            return false;
        }
        $this->sql(
            'CREATE DATABASE IF NOT EXISTS ' . self::quoteIdentifier($database)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
        );

        return true;
    }

    /**
     * Stream a prepared .sql dump into the client (stdinFile — never buffered in
     * PHP). The dump is already sanitised + carries its own `USE` line.
     */
    public function importFile(string $path, int $timeout): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new TaskRejectedException('prepared SQL dump is missing');
        }
        $res = $this->cmd->run([$this->client(), '-N', '-B'], $timeout, null, $path);
        if (!$res->ok()) {
            $this->logFailure('MariaDB import failed', $res);

            throw new TaskRejectedException('MariaDB import failed');
        }
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /** Absolute client binary (ACP_MYSQL_CLIENT, default mariadb). */
    private function client(): string
    {
        $env = getenv('ACP_MYSQL_CLIENT');
        $client = is_string($env) && trim($env) !== '' ? trim($env) : '/usr/bin/mariadb';
        if ($client === '' || $client[0] !== '/') {
            throw new TaskRejectedException('ACP_MYSQL_CLIENT must be an absolute path');
        }

        return $client;
    }

    /** Send one SQL script on stdin and return the client's stdout. */
    private function run(string $sql, int $timeout = self::SQL_TIMEOUT): string
    {
        $res = $this->cmd->run([$this->client(), '-N', '-B'], $timeout, $sql);
        if (!$res->ok()) {
            $this->logFailure('MariaDB command failed', $res);

            throw new TaskRejectedException('MariaDB command failed');
        }

        return $res->stdout;
    }

    /** Record the client's stderr in the task log — never in the exception. */
    private function logFailure(string $message, CommandResult $res): void
    {
        $detail = trim((string) preg_replace('/\s+/', ' ', $res->stderr));
        if ($detail === '') {
            $detail = 'exit ' . $res->exitCode . ($res->timedOut ? ' (timeout)' : '');
        }
        $this->log->error($message . ': ' . substr($detail, 0, 200));
    }
}
