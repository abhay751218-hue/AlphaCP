<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Allowlisted Apache AddHandler mappings. PHP/proxy/fcgi handlers fail closed.
 */
final class Handlers
{
    public const MAX = 50;

    /** @var list<string> */
    public const ALLOWED = [
        'cgi-script',
        'server-parsed',
        'imap-file',
        'type-map',
        'default-handler',
        'send-as-is',
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
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many handler mappings (50 max)');
        }
        $byExt = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid handler mapping at {$i}");
            }
            $handler = self::normalizeHandler((string) ($row['handler'] ?? ''));
            $ext = self::normalizeExt((string) ($row['ext'] ?? ''));
            $byExt[$ext] = ['handler' => $handler, 'ext' => $ext];
        }
        ksort($byExt, SORT_STRING);

        return array_values($byExt);
    }

    public static function normalizeExt(string $ext): string
    {
        $ext = strtolower(trim($ext));
        $ext = ltrim($ext, '.');
        if ($ext === '' || preg_match('/^[a-z0-9]{1,16}$/', $ext) !== 1) {
            throw new TaskRejectedException('invalid handler extension');
        }
        if (in_array($ext, self::BLOCKED_EXT, true)) {
            throw new TaskRejectedException('blocked handler extension: ' . $ext);
        }

        return $ext;
    }

    public static function normalizeHandler(string $handler): string
    {
        $handler = strtolower(trim($handler));
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $handler) !== 1) {
            throw new TaskRejectedException('invalid Apache handler');
        }
        if (!in_array($handler, self::ALLOWED, true)) {
            throw new TaskRejectedException('blocked Apache handler: ' . $handler);
        }

        return $handler;
    }
}
