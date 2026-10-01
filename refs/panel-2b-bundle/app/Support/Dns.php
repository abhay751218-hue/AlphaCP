<?php

declare(strict_types=1);

namespace App\Support;

/** Zone Editor name/type/value (agent re-validates). */
final class Dns
{
    public const TYPES = ['A', 'CNAME', 'MX', 'TXT'];

    public static function tryDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if (strlen($domain) > 190 || preg_match(AccountIdentity::DOMAIN_PATTERN, $domain) !== 1) {
            return null;
        }

        return $domain;
    }

    public static function tryName(string $name): ?string
    {
        $name = strtolower(trim($name));
        if ($name === '@' || $name === '*') {
            return $name;
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,61}[a-z0-9])?$/', $name) !== 1) {
            return null;
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '|')) {
            return null;
        }

        return $name;
    }

    public static function tryType(string $type): ?string
    {
        $type = strtoupper(trim($type));
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        return $type;
    }

    public static function tryValue(string $type, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255) {
            return null;
        }
        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, '|') || str_contains($value, '..') || str_contains($value, '/')) {
            return null;
        }
        if ($type === 'A') {
            if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/', $value) !== 1) {
                return null;
            }

            return $value;
        }
        if ($type === 'CNAME' || $type === 'MX') {
            return self::tryDomain($value);
        }
        if (preg_match('/^[A-Za-z0-9 .,_:+?=\\-]{1,255}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
