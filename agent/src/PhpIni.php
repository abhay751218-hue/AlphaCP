<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Allowlisted MultiPHP INI keys. Unknown keys / hostile values never reach
 * the PHP-FPM pool (no auto_prepend_file, open_basedir, disable_functions…).
 */
final class PhpIni
{
    /** @var array<string, string> key => kind */
    public const KEYS = [
        'display_errors'        => 'flag',
        'log_errors'            => 'flag',
        'allow_url_fopen'       => 'flag',
        'short_open_tag'        => 'flag',
        'max_execution_time'    => 'int',
        'max_input_time'        => 'int',
        'max_input_vars'        => 'int',
        'memory_limit'          => 'size',
        'post_max_size'         => 'size',
        'upload_max_filesize'   => 'size',
        'date.timezone'         => 'tz',
        'error_reporting'       => 'reporting',
        'session.gc_maxlifetime'=> 'int',
        'default_charset'       => 'charset',
    ];

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function sanitize(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !isset(self::KEYS[$key])) {
                throw new TaskRejectedException('unknown php.ini key: ' . (string) $key);
            }
            if (!is_scalar($value)) {
                throw new TaskRejectedException("invalid value for {$key}");
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            if (strpbrk($text, "\r\n\0") !== false) {
                throw new TaskRejectedException("newline not allowed in {$key}");
            }
            $out[$key] = self::normalize($key, $text);
        }
        return $out;
    }

    public static function normalize(string $key, string $value): string
    {
        $kind = self::KEYS[$key];
        return match ($kind) {
            'flag' => self::flag($key, $value),
            'int' => self::intVal($key, $value),
            'size' => self::size($key, $value),
            'tz' => self::timezone($key, $value),
            'reporting' => self::reporting($key, $value),
            'charset' => self::charset($key, $value),
            default => throw new TaskRejectedException("unknown kind for {$key}"),
        };
    }

    public static function poolLine(string $key, string $value): string
    {
        if (self::KEYS[$key] === 'flag') {
            $flag = in_array(strtolower($value), ['1', 'on'], true) ? 'on' : 'off';
            return "php_admin_flag[{$key}] = {$flag}";
        }
        return "php_admin_value[{$key}] = {$value}";
    }

    /**
     * @return array<string, string>
     */
    public static function parseFile(string $body): array
    {
        $raw = [];
        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
                $raw[$key] = $val;
        }
        $out = [];
        foreach ($raw as $key => $val) {
            if (!isset(self::KEYS[$key])) {
                continue;
            }
            try {
                $out[$key] = self::normalize($key, $val);
            } catch (TaskRejectedException) {
                continue;
            }
        }
        return $out;
    }

    public static function renderFile(array $directives): string
    {
        $lines = ['; AlphaCP MultiPHP INI — managed file, do not edit by hand'];
        foreach ($directives as $key => $value) {
            $lines[] = $key . ' = ' . $value;
        }
        return implode("\n", $lines) . "\n";
    }

    private static function flag(string $key, string $value): string
    {
        $v = strtolower($value);
        if (!in_array($v, ['on', 'off', '1', '0'], true)) {
            throw new TaskRejectedException("{$key} must be On or Off");
        }
        return in_array($v, ['on', '1'], true) ? 'On' : 'Off';
    }

    private static function intVal(string $key, string $value): string
    {
        if (!preg_match('/^[0-9]{1,7}$/', $value)) {
            throw new TaskRejectedException("{$key} must be an integer");
        }
        $n = (int) $value;
        $max = match ($key) {
            'max_execution_time', 'max_input_time' => 300,
            'max_input_vars' => 10000,
            'session.gc_maxlifetime' => 2592000,
            default => 86400,
        };
        $min = $key === 'max_input_vars' ? 1000 : 0;
        if ($n < $min || $n > $max) {
            throw new TaskRejectedException("{$key} out of range ({$min}-{$max})");
        }
        return (string) $n;
    }

    private static function size(string $key, string $value): string
    {
        if (!preg_match('/^([0-9]{1,5})([KMG])$/i', $value, $m)) {
            throw new TaskRejectedException("{$key} must look like 256M");
        }
        $n = (int) $m[1];
        $unit = strtoupper($m[2]);
        $bytes = $n * match ($unit) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };
        $max = $key === 'memory_limit' ? 1024 * 1024 * 1024 : 512 * 1024 * 1024;
        $min = $key === 'memory_limit' ? 32 * 1024 * 1024 : 1024 * 1024;
        if ($bytes < $min || $bytes > $max) {
            throw new TaskRejectedException("{$key} out of range");
        }
        return $n . $unit;
    }

    private static function timezone(string $key, string $value): string
    {
        if ($value !== 'UTC' && !preg_match('#^[A-Za-z]+/[A-Za-z0-9_+\\-]+$#', $value)) {
            throw new TaskRejectedException("{$key} is not a valid timezone");
        }
        if (strlen($value) > 60) {
            throw new TaskRejectedException("{$key} too long");
        }
        return $value;
    }

    private static function reporting(string $key, string $value): string
    {
        $ok = [
            'E_ALL',
            'E_ALL & ~E_NOTICE',
            'E_ALL & ~E_DEPRECATED',
            'E_ERROR',
        ];
        if (in_array($value, $ok, true) || preg_match('/^[0-9]{1,10}$/', $value)) {
            return $value;
        }
        throw new TaskRejectedException("{$key} not in allowlist");
    }

    private static function charset(string $key, string $value): string
    {
        if (!in_array($value, ['UTF-8', 'ISO-8859-1'], true)) {
            throw new TaskRejectedException("{$key} must be UTF-8 or ISO-8859-1");
        }
        return $value;
    }
}
