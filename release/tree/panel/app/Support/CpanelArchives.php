<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Finds cPanel archives that already sit on this server, so the WHM transfer
 * pages can offer them instead of asking the operator to type a path.
 *
 * Only well-known migration drop directories are scanned, and only names that
 * match a cPanel archive are returned. Nothing is read from the archive here —
 * the agent re-validates the path before importing.
 */
final class CpanelArchives
{
    /** @var list<string> */
    public const PATTERNS = [
        '/home/cpmove-*.tar.gz',
        '/home/cpmove-*.tar',
        '/home/cpmove-*.tgz',
        '/home/backup-*.tar.gz',
        '/home/backup-*.tar',
        '/incoming/cpmove-*.tar.gz',
    ];

    /** @return list<array{path:string,size_mb:float}> */
    public static function candidates(int $limit = 25): array
    {
        $found = [];
        foreach (self::patterns() as $pattern) {
            $matches = @glob($pattern);
            if (! is_array($matches)) {
                continue;
            }
            foreach ($matches as $path) {
                if (! is_string($path) || ! is_file($path)) {
                    continue;
                }
                if (Backup::tryArchivePath($path) === null) {
                    continue;
                }
                $found[$path] = true;
            }
        }
        $paths = array_keys($found);
        sort($paths);

        return array_map(
            static function (string $path): array {
                $size = @filesize($path);

                return [
                    'path' => $path,
                    'size_mb' => is_int($size) ? round($size / 1048576, 1) : 0.0,
                ];
            },
            array_slice($paths, 0, $limit),
        );
    }

    /** @return list<string> */
    private static function patterns(): array
    {
        $home = rtrim((string) config('acp.home', '/usr/local/alphacp'), '/');

        return array_map(
            static fn (string $pattern): string => str_starts_with($pattern, '/incoming')
                ? $home . $pattern
                : $pattern,
            self::PATTERNS,
        );
    }
}
