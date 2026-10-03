<?php

declare(strict_types=1);

namespace App\Support;

/** Database name suffix (agent re-validates + prefixes username_). */
final class Mysql
{
    public static function tryName(string $name): ?string
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $name) !== 1) {
            return null;
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '|')) {
            return null;
        }

        return $name;
    }

    public static function tryHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_contains($host, '..') || str_contains($host, '/') || str_contains($host, '|') || str_contains($host, ' ')) {
            return null;
        }
        if ($host === '%') {
            return $host;
        }
        if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/', $host) === 1) {
            return $host;
        }
        if (strlen($host) > 190 || preg_match(AccountIdentity::DOMAIN_PATTERN, $host) !== 1) {
            return null;
        }

        return $host;
    }
}
