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

    public const MAX_DYNAMIC = 20;

    /**
     * @param  list<mixed> $raw
     * @return list<array{domain: string, name: string, token: string, ip: string}>
     */
    public static function sanitizeDynamic(array $raw): array
    {
        if (count($raw) > self::MAX_DYNAMIC) {
            throw new TaskRejectedException('too many dynamic dns hosts (20 max)');
        }
        $out = [];
        $seen = [];
        $tokens = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException('dynamic dns row must be an object');
            }
            $domain = self::normalizeDomain((string) ($row['domain'] ?? ''));
            $name = self::normalizeDynamicName((string) ($row['name'] ?? ''));
            $token = self::normalizeToken((string) ($row['token'] ?? ''));
            $ip = self::normalizeDynamicIp((string) ($row['ip'] ?? ''));
            $key = $domain . '|' . $name;
            if (isset($seen[$key])) {
                throw new TaskRejectedException('duplicate dynamic dns host');
            }
            if (isset($tokens[$token])) {
                throw new TaskRejectedException('duplicate dynamic dns token');
            }
            $seen[$key] = true;
            $tokens[$token] = true;
            $out[] = [
                'domain' => $domain,
                'name' => $name,
                'token' => $token,
                'ip' => $ip,
            ];
        }

        return $out;
    }

    public static function normalizeDynamicName(string $name): string
    {
        $name = self::normalizeName($name);
        if ($name === '@' || $name === '*') {
            throw new TaskRejectedException('invalid dynamic dns name');
        }

        return $name;
    }

    public static function normalizeToken(string $token): string
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new TaskRejectedException('invalid dynamic dns token');
        }

        return $token;
    }

    public static function normalizeDynamicIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        if (preg_match('/^(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/', $ip) !== 1) {
            throw new TaskRejectedException('invalid dynamic dns ip');
        }
        if (str_contains($ip, '|') || str_contains($ip, '/') || str_contains($ip, '..')) {
            throw new TaskRejectedException('dynamic dns ip path escape');
        }

        return $ip;
    }

    /**
     * @param  list<array{domain: string, name: string, token: string, ip: string}> $rows
     */
    public static function dynamicJson(array $rows): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new TaskRejectedException('dns json encode failed');
        }

        return $json . "\n";
    }

    public const TRACK_TYPES = ['A', 'CNAME', 'MX', 'NS', 'TXT', 'ALL'];

    public static function normalizeTrackType(string $type): string
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, self::TRACK_TYPES, true)) {
            throw new TaskRejectedException('invalid dns track type');
        }

        return $type;
    }

    /**
     * @return list<array{source: string, domain: string, name: string, type: string, value: string}>
     */
    public static function filterTrack(string $query, string $type, string $zoneJson, string $dynamicJson): array
    {
        $query = self::normalizeDomain($query);
        $type = self::normalizeTrackType($type);
        $out = [];
        $zone = json_decode($zoneJson, true);
        if (is_array($zone)) {
            foreach ($zone as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $hit = self::matchTrackRow('zone', $query, $type, $row);
                if ($hit !== null) {
                    $out[] = $hit;
                }
            }
        }
        $dyn = json_decode($dynamicJson, true);
        if (is_array($dyn)) {
            foreach ($dyn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $mapped = [
                    'domain' => (string) ($row['domain'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => 'A',
                    'value' => (string) ($row['ip'] ?? ''),
                ];
                $hit = self::matchTrackRow('dynamic', $query, $type, $mapped);
                if ($hit !== null) {
                    $out[] = $hit;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed> $row
     * @return array{source: string, domain: string, name: string, type: string, value: string}|null
     */
    private static function matchTrackRow(string $source, string $query, string $wantType, array $row): ?array
    {
        try {
            $domain = self::normalizeDomain((string) ($row['domain'] ?? ''));
            $name = self::normalizeName((string) ($row['name'] ?? ''));
            $type = self::normalizeType((string) ($row['type'] ?? 'A'));
            $value = self::normalizeValue($type, (string) ($row['value'] ?? ''));
        } catch (TaskRejectedException $e) {
            return null;
        }
        if ($wantType !== 'ALL' && $type !== $wantType) {
            return null;
        }
        $fqdn = $name === '@' ? $domain : $name . '.' . $domain;
        if ($query !== $domain && $query !== $fqdn) {
            return null;
        }

        return [
            'source' => $source,
            'domain' => $domain,
            'name' => $name,
            'type' => $type,
            'value' => $value,
        ];
    }

    public static function hostnameJson(string $hostname, string $ip): string
    {
        $hostname = self::normalizeDomain($hostname);
        $ip = self::normalizeValue('A', $ip);
        $json = json_encode(['hostname' => $hostname, 'ip' => $ip], JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new TaskRejectedException('dns json encode failed');
        }

        return $json . "\n";
    }

    public const MAX_TEMPLATES = 10;

    /**
     * @param  list<mixed> $raw
     * @return list<array{name: string, body: string}>
     */
    public static function sanitizeTemplates(array $raw): array
    {
        if (count($raw) > self::MAX_TEMPLATES) {
            throw new TaskRejectedException('too many zone templates (10 max)');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException('template row must be an object');
            }
            $name = self::normalizeTemplateName((string) ($row['name'] ?? ''));
            $body = self::normalizeTemplateBody((string) ($row['body'] ?? ''));
            if (isset($seen[$name])) {
                throw new TaskRejectedException('duplicate zone template');
            }
            $seen[$name] = true;
            $out[] = ['name' => $name, 'body' => $body];
        }

        return $out;
    }

    public static function normalizeTemplateName(string $name): string
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) !== 1) {
            throw new TaskRejectedException('invalid zone template name');
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '|')) {
            throw new TaskRejectedException('zone template name path escape');
        }

        return $name;
    }

    public static function normalizeTemplateBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = trim($body);
        if ($body === '' || strlen($body) > 2000) {
            throw new TaskRejectedException('invalid zone template body');
        }
        if (str_contains($body, '|') || str_contains($body, '..') || str_contains($body, '/')) {
            throw new TaskRejectedException('zone template body path escape');
        }
        if (preg_match('/^[A-Za-z0-9 %._:@\\n\\t-]+$/', $body) !== 1) {
            throw new TaskRejectedException('invalid zone template body');
        }

        return $body;
    }

    /**
     * @param  list<array{name: string, body: string}> $rows
     */
    public static function templatesJson(array $rows): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new TaskRejectedException('dns json encode failed');
        }

        return $json . "\n";
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
