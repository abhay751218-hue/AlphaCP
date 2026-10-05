<?php

declare(strict_types=1);

namespace App\Support;

/** Dynamic DNS host/token/IP (agent re-validates). */
final class DynamicDns
{
    public static function tryName(string $name): ?string
    {
        $name = Dns::tryName($name);
        if ($name === null || $name === '@' || $name === '*') {
            return null;
        }

        return $name;
    }

    public static function tryIp(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/', $ip) !== 1) {
            return null;
        }
        if (str_contains($ip, '|') || str_contains($ip, '/') || str_contains($ip, '..')) {
            return null;
        }

        return $ip;
    }

    public static function tryToken(string $token): ?string
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        return $token;
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
