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

    /** @var list<string> */
    public const SCHEDULES = ['daily', 'weekly', 'monthly'];

    /** @var list<string> */
    public const DESTINATIONS = ['local', 'remote'];

    public const MIN_RETENTION_DAYS = 1;
    public const MAX_RETENTION_DAYS = 3650;

    public static function trySchedule(string $schedule): ?string
    {
        $schedule = strtolower(trim($schedule));
        if (! in_array($schedule, self::SCHEDULES, true)) {
            return null;
        }

        return $schedule;
    }

    public static function tryDestination(string $destination): ?string
    {
        $destination = strtolower(trim($destination));
        if (! in_array($destination, self::DESTINATIONS, true)) {
            return null;
        }

        return $destination;
    }

    public static function tryRetentionDays(string $days): ?int
    {
        $days = trim($days);
        if ($days === '' || ! ctype_digit($days)) {
            return null;
        }
        $n = (int) $days;
        if ($n < self::MIN_RETENTION_DAYS || $n > self::MAX_RETENTION_DAYS) {
            return null;
        }

        return $n;
    }

    public static function tryEnabled(string $enabled): ?bool
    {
        $enabled = strtolower(trim($enabled));
        if (! in_array($enabled, ['on', 'off', 'yes', 'no', '1', '0'], true)) {
            return null;
        }

        return in_array($enabled, ['on', 'yes', '1'], true);
    }

    /** IPv4 or FQDN — used for the remote backup host. */
    public static function tryRemoteHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 190) {
            return null;
        }
        if (str_contains($host, '..') || str_contains($host, '/') || str_contains($host, '|') || str_contains($host, '@')) {
            return null;
        }
        if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)(?:\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)){3}$/', $host) === 1) {
            return $host;
        }
        if (preg_match(AccountIdentity::DOMAIN_PATTERN, $host) !== 1) {
            return null;
        }

        return $host;
    }

    public static function tryRemoteUser(string $user): ?string
    {
        $user = strtolower(trim($user));
        if ($user === '') {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,31}$/', $user) !== 1) {
            return null;
        }
        if (str_contains($user, '..') || str_contains($user, '/') || str_contains($user, '|')) {
            return null;
        }

        return $user;
    }

    /** Remote path stays relative — no leading slash, no traversal, no pipe. */
    public static function tryRemotePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > 190) {
            return null;
        }
        if (str_contains($path, '..') || str_contains($path, '|') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return null;
        }
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,189}$#', $path) !== 1) {
            return null;
        }

        return $path;
    }
}
