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
}
