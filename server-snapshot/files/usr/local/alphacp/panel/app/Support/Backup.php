<?php

declare(strict_types=1);

namespace App\Support;

/** Backup job kind allowlist (agent re-validates). No tar/shell. */
final class Backup
{
    public const MAX = 10;

    /** @var list<string> */
    public const KINDS = ['home', 'mail', 'mysql'];

    /** @var list<string> */
    public const ACTIONS = ['backup', 'restore'];

    /** @var list<string> */
    public const SCOPES = ['full', 'home', 'mail', 'mysql'];

    /** @var list<string> */
    public const SCHEDULES = ['daily', 'weekly', 'monthly', 'disabled'];

    /** @var list<string> */
    public const MODES = ['full', 'partial', 'account'];

    /** @var list<string> */
    public const CPANEL_ACTIONS = ['transfer', 'restore'];

    /** @var list<string> */
    public const REVIEW_STATUSES = ['pending', 'ok', 'failed'];

    public static function tryKind(string $kind): ?string
    {
        $kind = strtolower(trim($kind));
        if (! in_array($kind, self::KINDS, true)) {
            return null;
        }

        return $kind;
    }

    public static function tryAction(string $action): ?string
    {
        $action = strtolower(trim($action));
        if (! in_array($action, self::ACTIONS, true)) {
            return null;
        }

        return $action;
    }

    public static function tryScope(string $scope): ?string
    {
        $scope = strtolower(trim($scope));
        if (! in_array($scope, self::SCOPES, true)) {
            return null;
        }

        return $scope;
    }

    public static function trySchedule(string $schedule): ?string
    {
        $schedule = strtolower(trim($schedule));
        if (! in_array($schedule, self::SCHEDULES, true)) {
            return null;
        }

        return $schedule;
    }

    public static function tryRetention(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        if ($n < 1 || $n > 365) {
            return null;
        }

        return $n;
    }

    public static function tryMode(string $mode): ?string
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, self::MODES, true)) {
            return null;
        }

        return $mode;
    }

    public static function tryUsername(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        if (preg_match(AccountIdentity::USERNAME_PATTERN, $raw) !== 1) {
            return null;
        }
        if (AccountIdentity::isReserved($raw)) {
            return null;
        }

        return $raw;
    }

    public static function tryCpanelAction(string $action): ?string
    {
        $action = strtolower(trim($action));
        if (! in_array($action, self::CPANEL_ACTIONS, true)) {
            return null;
        }

        return $action;
    }

    public static function tryReviewStatus(string $status): ?string
    {
        $status = strtolower(trim($status));
        if (! in_array($status, self::REVIEW_STATUSES, true)) {
            return null;
        }

        return $status;
    }

    /**
     * A cPanel archive the operator placed on this server.
     *
     * Import is a server-side file operation (a real cpmove archive is far
     * bigger than any PHP upload limit), so the panel only accepts an absolute
     * path — and the agent re-validates it against its own allowlisted roots
     * before a single byte is read.
     */
    public static function tryArchivePath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 255) {
            return null;
        }
        if (str_contains($raw, "\0") || str_contains($raw, '|') || str_contains($raw, chr(92))) {
            return null;
        }
        if (! str_starts_with($raw, '/')) {
            return null;
        }
        if (preg_match('#^/[A-Za-z0-9._/-]+$#', $raw) !== 1) {
            return null;
        }
        foreach (explode('/', $raw) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }
        if (preg_match('/\.(tar|tar\.gz|tgz)$/i', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /** '' stays empty; a valid sha256 comes back lowercased; anything else is null (invalid). */
    public static function normalizeSha256(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }

        return preg_match('/^[a-f0-9]{64}$/', $raw) === 1 ? $raw : null;
    }
}
