<?php

declare(strict_types=1);

namespace App\Support;

/** Allowlisted Apache AddHandler mappings (agent re-validates). */
final class Handlers
{
    public const MAX = 50;

    /** @var array<string, string> */
    public const ALLOWED = [
        'cgi-script' => 'CGI script',
        'server-parsed' => 'Server-parsed (SSI)',
        'imap-file' => 'Imagemap',
        'type-map' => 'Type map',
        'default-handler' => 'Default handler (static)',
        'send-as-is' => 'Send as-is',
    ];

    /** @var list<string> */
    public const BLOCKED_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
        'htaccess', 'htpasswd', 'conf',
    ];

    /**
     * @param  list<mixed> $raw
     * @return list<array{handler: string, ext: string}>
     */
    public static function sanitize(array $raw): array
    {
        $byExt = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $handler = self::tryHandler((string) ($row['handler'] ?? ''));
            $ext = self::tryExt((string) ($row['ext'] ?? ''));
            if ($handler === null || $ext === null) {
                continue;
            }
            $byExt[$ext] = ['handler' => $handler, 'ext' => $ext];
        }
        ksort($byExt, SORT_STRING);

        return array_values($byExt);
    }

    public static function tryExt(string $ext): ?string
    {
        $ext = strtolower(trim($ext));
        $ext = ltrim($ext, '.');
        if ($ext === '' || preg_match('/^[a-z0-9]{1,16}$/', $ext) !== 1) {
            return null;
        }
        if (in_array($ext, self::BLOCKED_EXT, true)) {
            return null;
        }

        return $ext;
    }

    public static function tryHandler(string $handler): ?string
    {
        $handler = strtolower(trim($handler));
        if (! isset(self::ALLOWED[$handler])) {
            return null;
        }

        return $handler;
    }
}
