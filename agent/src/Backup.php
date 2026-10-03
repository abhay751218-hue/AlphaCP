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

    public const ACTIONS = ['backup', 'restore'];
    public const SCOPES = ['full', 'home', 'mail', 'mysql'];

    public static function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new TaskRejectedException('invalid backup wizard action');
        }

        return $action;
    }

    public static function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, self::SCOPES, true)) {
            throw new TaskRejectedException('invalid backup wizard scope');
        }

        return $scope;
    }

    public static function wizardJson(string $action, string $scope): string
    {
        $action = self::normalizeAction($action);
        $scope = self::normalizeScope($scope);
        $json = json_encode([
            'action' => $action,
            'scope' => $scope,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup wizard json encode failed');
        }

        return $json . "\n";
    }

    /**
     * @param  list<mixed> $raw
     * @return list<array{path: string}>
     */
    public static function sanitizeRestore(array $raw): array
    {
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many restore paths (10 max)');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid restore row at {$i}");
            }
            $path = Files::normalizeRel((string) ($row['path'] ?? ''));
            if ($path === '') {
                throw new TaskRejectedException('restore path required');
            }
            if (isset($seen[$path])) {
                throw new TaskRejectedException('duplicate restore path');
            }
            $seen[$path] = true;
            $out[] = ['path' => $path];
        }

        return $out;
    }

    /**
     * @param  list<array{path: string}> $rows
     */
    public static function restoreJson(array $rows): string
    {
        $rows = self::sanitizeRestore($rows);
        $json = json_encode(['paths' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('restore json encode failed');
        }

        return $json . "\n";
    }

    public const SCHEDULES = ['daily', 'weekly', 'monthly'];
    public const DESTINATIONS = ['local', 'remote'];
    public const MIN_RETENTION_DAYS = 1;
    public const MAX_RETENTION_DAYS = 3650;

    public static function normalizeSchedule(string $schedule): string
    {
        $schedule = strtolower(trim($schedule));
        if (!in_array($schedule, self::SCHEDULES, true)) {
            throw new TaskRejectedException('invalid backup schedule');
        }

        return $schedule;
    }

    public static function normalizeDestination(string $destination): string
    {
        $destination = strtolower(trim($destination));
        if (!in_array($destination, self::DESTINATIONS, true)) {
            throw new TaskRejectedException('invalid backup destination');
        }

        return $destination;
    }

    public static function normalizeRetention(mixed $days): int
    {
        if (is_int($days)) {
            $n = $days;
        } elseif (is_string($days) && ctype_digit($days)) {
            $n = (int) $days;
        } else {
            throw new TaskRejectedException('invalid backup retention');
        }
        if ($n < self::MIN_RETENTION_DAYS || $n > self::MAX_RETENTION_DAYS) {
            throw new TaskRejectedException('invalid backup retention');
        }

        return $n;
    }

    public static function normalizeRemoteHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 190) {
            throw new TaskRejectedException('invalid backup remote host');
        }
        if (str_contains($host, '..') || str_contains($host, '/') || str_contains($host, '|') || str_contains($host, '@')) {
            throw new TaskRejectedException('backup remote host path escape');
        }
        if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)){3}$/', $host) === 1) {
            return $host;
        }
        $err = AccountIdentity::domain($host);
        if ($err !== null) {
            throw new TaskRejectedException('invalid backup remote host');
        }

        return $host;
    }

    public static function normalizeRemoteUser(string $user): string
    {
        $user = strtolower(trim($user));
        if ($user === '' || preg_match('/^[a-z][a-z0-9._-]{0,31}$/', $user) !== 1) {
            throw new TaskRejectedException('invalid backup remote user');
        }
        if (str_contains($user, '..') || str_contains($user, '/') || str_contains($user, '|')) {
            throw new TaskRejectedException('backup remote user path escape');
        }

        return $user;
    }

    public static function normalizeRemotePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > 190) {
            throw new TaskRejectedException('invalid backup remote path');
        }
        if (str_contains($path, '..') || str_contains($path, '|') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            throw new TaskRejectedException('backup remote path escape');
        }
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,189}$#', $path) !== 1) {
            throw new TaskRejectedException('invalid backup remote path');
        }

        return $path;
    }

    /**
     * @return array{enabled: bool, schedule: string, retention_days: int, destination: string, remote_host: string, remote_user: string, remote_path: string}
     */
    public static function sanitizeConfig(array $raw): array
    {
        $enabled = $raw['enabled'] ?? false;
        if (is_string($enabled)) {
            $enabled = in_array(strtolower(trim($enabled)), ['on', 'yes', '1', 'true'], true);
        }
        $schedule = self::normalizeSchedule((string) ($raw['schedule'] ?? ''));
        $retention = self::normalizeRetention($raw['retention_days'] ?? null);
        $destination = self::normalizeDestination((string) ($raw['destination'] ?? ''));
        $remoteHost = '';
        $remoteUser = '';
        $remotePath = '';
        if ($destination === 'remote') {
            $remoteHost = self::normalizeRemoteHost((string) ($raw['remote_host'] ?? ''));
            $remoteUser = self::normalizeRemoteUser((string) ($raw['remote_user'] ?? ''));
            $remotePath = self::normalizeRemotePath((string) ($raw['remote_path'] ?? ''));
        }

        return [
            'enabled'        => (bool) $enabled,
            'schedule'       => $schedule,
            'retention_days' => $retention,
            'destination'    => $destination,
            'remote_host'    => $remoteHost,
            'remote_user'    => $remoteUser,
            'remote_path'    => $remotePath,
        ];
    }

    /**
     * @param  array<string, mixed> $raw
     */
    public static function configJson(array $raw): string
    {
        $config = self::sanitizeConfig($raw);
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup config json encode failed');
        }

        return $json . "\n";
    }
}
