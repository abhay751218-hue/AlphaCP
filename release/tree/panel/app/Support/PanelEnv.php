<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads /usr/local/alphacp/etc/panel.env — the ONE file the installer writes
 * for the panel (mode 0640 root:alphacp, so the web user can read it).
 *
 * Why this exists:
 *   etc/database.env is root-only (0600) because paneld runs as root.
 *   The panel runs as the unprivileged `alphacp` user, so it gets its own copy
 *   with just what it needs. Values are cached per request.
 *
 * Precedence: panel.env  →  database.env (dev machines)  →  app .env
 */
final class PanelEnv
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        $values = self::all();

        return $values[$key] ?? $default;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $home = rtrim((string) env('ACP_HOME', '/usr/local/alphacp'), '/');

        foreach (["{$home}/etc/panel.env", "{$home}/etc/database.env"] as $file) {
            if (! is_readable($file)) {
                // Loud on purpose: silently falling back to .env here ends with
                // "Access denied for user" during a customer install, and that
                // is a 30-minute debugging session we refuse to hand anyone.
                // database.env is root-only by design (it is the dev fallback),
                // so only complain about panel.env — the file we must be able to read.
                if (file_exists($file) && str_ends_with($file, 'panel.env')) {
                    error_log("AlphaCP: {$file} exists but is not readable by the panel user " .
                              "(expected root:alphacp, mode 0640) — fix: " .
                              "chown root:alphacp {$file} && chmod 0640 {$file}");
                }
                continue;
            }

            $values = [];
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $values[trim($k)] = trim($v, " \t\"'");
            }

            if ($values !== []) {
                return self::$cache = $values;
            }
        }

        return self::$cache = [];
    }
}
