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

    public const SCHEDULES = ['daily', 'weekly', 'monthly', 'disabled'];

    public static function normalizeSchedule(string $schedule): string
    {
        $schedule = strtolower(trim($schedule));
        if (!in_array($schedule, self::SCHEDULES, true)) {
            throw new TaskRejectedException('invalid backup schedule');
        }

        return $schedule;
    }

    public static function normalizeRetention(mixed $raw): int
    {
        if (is_int($raw)) {
            $n = $raw;
        } elseif (is_string($raw) && ctype_digit($raw)) {
            $n = (int) $raw;
        } else {
            throw new TaskRejectedException('invalid backup retention');
        }
        if ($n < 1 || $n > 365) {
            throw new TaskRejectedException('invalid backup retention');
        }

        return $n;
    }

    public static function configJson(string $schedule, int $retention): string
    {
        $schedule = self::normalizeSchedule($schedule);
        $retention = self::normalizeRetention($retention);
        $json = json_encode([
            'schedule' => $schedule,
            'retention' => $retention,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup config json encode failed');
        }

        return $json . "\n";
    }

    public const MODES = ['full', 'partial', 'account'];

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new TaskRejectedException('invalid backup restoration mode');
        }

        return $mode;
    }

    public static function normalizeRestoreUsername(string $username): string
    {
        $username = strtolower(trim($username));
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException('invalid backup restoration username');
        }

        return $username;
    }

    public static function restorationJson(string $mode, string $username): string
    {
        $mode = self::normalizeMode($mode);
        $username = self::normalizeRestoreUsername($username);
        $json = json_encode([
            'mode' => $mode,
            'username' => $username,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup restoration json encode failed');
        }

        return $json . "\n";
    }

    /**
     * @param  list<mixed> $raw
     * @return list<array{username: string}>
     */
    public static function sanitizeUsers(array $raw): array
    {
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many backup users (10 max)');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid backup user row at {$i}");
            }
            $username = self::normalizeRestoreUsername((string) ($row['username'] ?? ''));
            if (isset($seen[$username])) {
                throw new TaskRejectedException('duplicate backup user');
            }
            $seen[$username] = true;
            $out[] = ['username' => $username];
        }

        return $out;
    }

    /**
     * @param  list<array{username: string}> $rows
     */
    public static function usersJson(array $rows): string
    {
        $rows = self::sanitizeUsers($rows);
        $json = json_encode(['users' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup users json encode failed');
        }

        return $json . "\n";
    }

    public static function normalizeFiledirPath(string $path): string
    {
        $path = Files::normalizeRel($path);
        if ($path === '') {
            throw new TaskRejectedException('invalid backup filedir path');
        }

        return $path;
    }

    public static function filedirJson(string $username, string $path): string
    {
        $username = self::normalizeRestoreUsername($username);
        $path = self::normalizeFiledirPath($path);
        $json = json_encode([
            'username' => $username,
            'path' => $path,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup filedir json encode failed');
        }

        return $json . "\n";
    }

    public static function normalizeTransferSource(string $source): string
    {
        return Dns::normalizeDomain($source);
    }

    public static function transferJson(string $username, string $source): string
    {
        $username = self::normalizeRestoreUsername($username);
        $source = self::normalizeTransferSource($source);
        $json = json_encode([
            'username' => $username,
            'source' => $source,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup transfer json encode failed');
        }

        return $json . "\n";
    }

    public const CPANEL_ACTIONS = ['transfer', 'restore'];

    public static function normalizeCpanelAction(string $action): string
    {
        $action = strtolower(trim($action));
        if (!in_array($action, self::CPANEL_ACTIONS, true)) {
            throw new TaskRejectedException('invalid cPanel account action');
        }

        return $action;
    }

    public static function cpanelJson(string $username, string $action): string
    {
        $username = self::normalizeRestoreUsername($username);
        $action = self::normalizeCpanelAction($action);
        $json = json_encode([
            'username' => $username,
            'action' => $action,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup cpanel json encode failed');
        }

        return $json . "\n";
    }

    public const REVIEW_STATUSES = ['pending', 'ok', 'failed'];

    public static function normalizeReviewStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::REVIEW_STATUSES, true)) {
            throw new TaskRejectedException('invalid review status');
        }

        return $status;
    }

    public static function reviewJson(string $username, string $status): string
    {
        $username = self::normalizeRestoreUsername($username);
        $status = self::normalizeReviewStatus($status);
        $json = json_encode([
            'username' => $username,
            'status' => $status,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('backup review json encode failed');
        }

        return $json . "\n";
    }
}
