<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Customer backup job list under the account home.
 * Agent never runs tar/shell — JSON only (restore later).
 */
final class Backup
{
    public const MAX = 10;
    public const KINDS = ['home', 'mail', 'mysql'];

    /**
     * @param  list<mixed> $raw
     * @return list<array{kind: string, path: string}>
     */
    public static function sanitize(array $raw): array
    {
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many backup jobs (10 max)');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid backup row at {$i}");
            }
            $kind = self::normalizeKind((string) ($row['kind'] ?? ''));
            $rawPath = (string) ($row['path'] ?? '');
            if ($kind !== 'home') {
                if (trim($rawPath) !== '') {
                    throw new TaskRejectedException('path only allowed for home backups');
                }
                $path = '';
            } else {
                $path = Files::normalizeRel($rawPath);
            }
            $key = $kind . '|' . $path;
            if (isset($seen[$key])) {
                throw new TaskRejectedException('duplicate backup job');
            }
            $seen[$key] = true;
            $out[] = [
                'kind' => $kind,
                'path' => $path,
            ];
        }

        return $out;
    }

    public static function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::KINDS, true)) {
            throw new TaskRejectedException('invalid backup kind');
        }

        return $kind;
    }

    /**
     * @param  list<array{kind: string, path: string}> $jobs
     */
    public static function backupJson(array $jobs): string
    {
        $jobs = self::sanitize($jobs);
        $json = json_encode(['jobs' => $jobs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup json encode failed');
        }

        return $json . "\n";
    }
}
