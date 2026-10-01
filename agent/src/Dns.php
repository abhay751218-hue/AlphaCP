<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Customer DNS records under the account home.
 * Agent never rewrites BIND — JSON only (named later).
 */
final class Dns
{
    public const MAX = 50;
    public const TYPES = ['A', 'CNAME', 'MX', 'TXT'];

    /**
     * @param  list<mixed> $raw
     * @return list<array{domain: string, name: string, type: string, value: string}>
     */
    public static function sanitize(array $raw): array
    {
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many dns records (50 max)');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException('dns row must be an object');
            }
            $domain = self::normalizeDomain((string) ($row['domain'] ?? ''));
            $name = self::normalizeName((string) ($row['name'] ?? ''));
            $type = self::normalizeType((string) ($row['type'] ?? ''));
            $value = self::normalizeValue($type, (string) ($row['value'] ?? ''));
            $key = $domain . '|' . $name . '|' . $type;
            if (isset($seen[$key])) {
                throw new TaskRejectedException('duplicate dns record');
            }
            $seen[$key] = true;
            $out[] = [
                'domain' => $domain,
                'name' => $name,
                'type' => $type,
                'value' => $value,
            ];
        }

        return $out;
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $err = AccountIdentity::domain($domain);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }

        return $domain;
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '@' || $name === '*') {
            return $name;
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,61}[a-z0-9])?$/', $name) !== 1) {
            throw new TaskRejectedException('invalid dns name');
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '|')) {
            throw new TaskRejectedException('dns name path escape');
        }

        return $name;
    }

    public static function normalizeType(string $type): string
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, self::TYPES, true)) {
            throw new TaskRejectedException('invalid dns type');
        }

        return $type;
    }

    public static function normalizeValue(string $type, string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255) {
            throw new TaskRejectedException('invalid dns value');
        }
        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, '|') || str_contains($value, '..') || str_contains($value, '/')) {
            throw new TaskRejectedException('dns value path escape');
        }
        if ($type === 'A') {
            if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/', $value) !== 1) {
                throw new TaskRejectedException('invalid A record value');
            }

            return $value;
        }
        if ($type === 'CNAME' || $type === 'MX') {
            $err = AccountIdentity::domain(strtolower($value));
            if ($err !== null) {
                throw new TaskRejectedException('invalid dns value');
            }

            return strtolower($value);
        }
        if (preg_match('/^[A-Za-z0-9 .,_:+?=\\-]{1,255}$/', $value) !== 1) {
            throw new TaskRejectedException('invalid TXT value');
        }

        return $value;
    }

    /**
     * @param  list<array{domain: string, name: string, type: string, value: string}> $rows
     */
    public static function zoneJson(array $rows): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new TaskRejectedException('dns json encode failed');
        }

        return $json . "\n";
    }
}
