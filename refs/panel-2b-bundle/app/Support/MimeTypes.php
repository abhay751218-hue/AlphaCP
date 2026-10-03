<?php

declare(strict_types=1);

namespace App\Support;

/** Custom Apache MIME mappings (agent re-validates). */
final class MimeTypes
{
    public const MAX = 50;

    /** @var list<string> */
    public const BLOCKED_EXT = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'shtml', 'stm',
        'htaccess', 'htpasswd', 'conf',
    ];

    /**
     * @param  list<mixed> $raw
     * @return list<array{mime: string, ext: string}>
     */
    public static function sanitize(array $raw): array
    {
        $byExt = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mime = self::tryMime((string) ($row['mime'] ?? ''));
            $ext = self::tryExt((string) ($row['ext'] ?? ''));
            if ($mime === null || $ext === null) {
                continue;
            }
            $byExt[$ext] = ['mime' => $mime, 'ext' => $ext];
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

    public static function tryMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        if (preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]{0,63}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,63}$/', $mime) !== 1) {
            return null;
        }
        if (str_starts_with($mime, 'application/x-httpd-php')
            || in_array($mime, ['application/x-httpd-cgi', 'application/x-cgi', 'text/x-server-parsed-html'], true)
            || str_starts_with($mime, 'magnus-internal/')) {
            return null;
        }

        return $mime;
    }
}
