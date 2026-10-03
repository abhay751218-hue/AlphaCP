<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Allowlisted custom Apache MIME types (AddType). Content-Type only —
 * never a handler. PHP/CGI/SSI extensions and httpd-php MIME types fail closed.
 */
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
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many MIME mappings (50 max)');
        }
        $byExt = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid MIME mapping at {$i}");
            }
            $mime = self::normalizeMime((string) ($row['mime'] ?? ''));
            $ext = self::normalizeExt((string) ($row['ext'] ?? ''));
            $byExt[$ext] = ['mime' => $mime, 'ext' => $ext];
        }
        ksort($byExt, SORT_STRING);

        return array_values($byExt);
    }

    public static function normalizeExt(string $ext): string
    {
        $ext = strtolower(trim($ext));
        $ext = ltrim($ext, '.');
        if ($ext === '' || preg_match('/^[a-z0-9]{1,16}$/', $ext) !== 1) {
            throw new TaskRejectedException('invalid MIME extension');
        }
        if (in_array($ext, self::BLOCKED_EXT, true)) {
            throw new TaskRejectedException('blocked MIME extension: ' . $ext);
        }

        return $ext;
    }

    public static function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if (preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]{0,63}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,63}$/', $mime) !== 1) {
            throw new TaskRejectedException('invalid MIME type');
        }
        if (str_starts_with($mime, 'application/x-httpd-php')
            || in_array($mime, ['application/x-httpd-cgi', 'application/x-cgi', 'text/x-server-parsed-html'], true)
            || str_starts_with($mime, 'magnus-internal/')) {
            throw new TaskRejectedException('blocked MIME type: ' . $mime);
        }

        return $mime;
    }
}
