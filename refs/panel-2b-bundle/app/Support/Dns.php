<?php

declare(strict_types=1);

namespace App\Support;

/** Zone Editor name/type/value (agent re-validates). */
final class Dns
{
    public const TYPES = ['A', 'CNAME', 'MX', 'TXT'];
    public const TRACK_TYPES = ['A', 'CNAME', 'MX', 'NS', 'TXT', 'ALL'];
    public const TTLS = [60, 300, 3600, 14400, 86400];

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

    public static function tryTrackType(string $type): ?string
    {
        $type = strtoupper(trim($type));
        if (! in_array($type, self::TRACK_TYPES, true)) {
            return null;
        }

        return $type;
    }

    public static function tryTemplateName(string $name): ?string
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) !== 1) {
            return null;
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '|')) {
            return null;
        }

        return $name;
    }

    public static function tryTemplateBody(string $body): ?string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = trim($body);
        if ($body === '' || strlen($body) > 2000) {
            return null;
        }
        if (str_contains($body, '|') || str_contains($body, '..') || str_contains($body, '/')) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9 %._:@\n\t-]+$/', $body) !== 1) {
            return null;
        }

        return $body;
    }

    public static function tryTtl(string $ttl): ?int
    {
        $ttl = trim($ttl);
        if ($ttl === '' || ! ctype_digit($ttl)) {
            return null;
        }
        $n = (int) $ttl;
        if (! in_array($n, self::TTLS, true)) {
            return null;
        }

        return $n;
    }

    public static function tryForwardUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 255) {
            return null;
        }
        if (str_contains($url, '|') || str_contains($url, '..') || str_contains($url, '\\') || str_contains($url, '@')) {
            return null;
        }
        if (preg_match('#^https?://[a-z0-9](?:[a-z0-9.-]{0,189})(?:/[A-Za-z0-9._/-]{0,64})?$#', $url) !== 1) {
            return null;
        }

        return $url;
    }

    public static function tryForwardCode(string $code): ?int
    {
        $code = trim($code);
        if ($code === '' || ! ctype_digit($code)) {
            return null;
        }
        $n = (int) $code;
        if (! in_array($n, [301, 302], true)) {
            return null;
        }

        return $n;
    }
}
