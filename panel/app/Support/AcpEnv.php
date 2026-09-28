<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Loads AlphaCP server facts (written by the installers) into the app env.
 *
 * Source of truth on a real server:
 *   /usr/local/alphacp/etc/database.env  (DB creds + ACP_SERVER_ID)
 *   /usr/local/alphacp/etc/install.env   (installer version, OS, PHP versions)
 *
 * We never duplicate credentials in the panel's own .env — the installer-owned
 * files stay the single source of truth (same files paneld reads).
 */
final class AcpEnv
{
    private static bool $loaded = false;

    public static function home(): string
    {
        $home = getenv('ACP_HOME');
        if ($home === false || $home === '') {
            $home = $_ENV['ACP_HOME'] ?? $_SERVER['ACP_HOME'] ?? null;
        }
        if ($home === null || $home === '') {
            // last resort: ACP_HOME= in the panel's own .env (used by dev setups)
            $home = self::fromDotEnv('ACP_HOME');
        }
        if ($home === null || $home === '') {
            $home = '/usr/local/alphacp';
        }

        return rtrim((string) $home, '/');
    }

    /** Read one key from the panel's .env (no side effects, no Dotenv dependency). */
    private static function fromDotEnv(string $key): ?string
    {
        $file = dirname(__DIR__, 2) . '/.env';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), $key . '=')) {
                $value = trim(explode('=', $line, 2)[1], " \t\"'");
                return $value === '' ? null : $value;
            }
        }
        return null;
    }

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $home = self::home();

        $db = self::parse($home . '/etc/database.env');
        if ($db !== []) {
            self::set('ACP_DB_HOST', $db['ACP_DB_HOST'] ?? '127.0.0.1');
            self::set('ACP_DB_PORT', $db['ACP_DB_PORT'] ?? '3306');
            self::set('ACP_DB_NAME', $db['ACP_DB_NAME'] ?? 'alphacp');
            self::set('ACP_DB_USER', $db['ACP_DB_USER'] ?? 'alphacp');
            self::set('ACP_DB_PASS', $db['ACP_DB_PASS'] ?? '');
            self::set('ACP_SERVER_ID', $db['ACP_SERVER_ID'] ?? '1');

            // Feed Laravel's default MySQL connection.
            self::set('DB_CONNECTION', 'mysql');
            self::set('DB_HOST', $db['ACP_DB_HOST'] ?? '127.0.0.1');
            self::set('DB_PORT', $db['ACP_DB_PORT'] ?? '3306');
            self::set('DB_DATABASE', $db['ACP_DB_NAME'] ?? 'alphacp');
            self::set('DB_USERNAME', $db['ACP_DB_USER'] ?? 'alphacp');
            self::set('DB_PASSWORD', $db['ACP_DB_PASS'] ?? '');
        }

        $install = self::parse($home . '/etc/install.env');
        if ($install !== []) {
            self::set('ACP_INSTALLER_VERSION', $install['ACP_INSTALLER_VERSION'] ?? 'unknown');
            self::set('ACP_OS', $install['ACP_OS'] ?? 'unknown');
            self::set('ACP_PHP_PRIMARY', $install['ACP_PHP_PRIMARY'] ?? '8.3');
        }

        self::set('ACP_HOME', $home);
        self::set('ACP_PANEL_VERSION', '0.3.0');
    }

    /** @return array<string,string> */
    private static function parse(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v, " \t\"'");
        }
        return $out;
    }

    /** Set in getenv(), $_ENV and $_SERVER so Laravel's env() sees it everywhere. */
    private static function set(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }
}
