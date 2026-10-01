<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Customer MySQL database names under the account prefix.
 * Agent never runs mysql/mysqladmin — JSON only (CREATE DATABASE later).
 */
final class Mysql
{
    public const MAX = 50;

    /**
     * @param  list<mixed> $raw
     * @return list<array{name: string, full: string}>
     */
    public static function sanitize(array $raw, string $username): array
    {
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many databases (50 max)');
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException('database row must be an object');
            }
            $name = self::normalizeName((string) ($row['name'] ?? ''));
            if (isset($seen[$name])) {
                throw new TaskRejectedException('duplicate database name');
            }
            $seen[$name] = true;
            $out[] = [
                'name' => $name,
                'full' => $username . '_' . $name,
            ];
        }

        return $out;
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $name) !== 1) {
            throw new TaskRejectedException('invalid database name');
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '|')) {
            throw new TaskRejectedException('database name path escape');
        }

        return $name;
    }

    /**
     * @param  list<array{name: string, full: string}> $rows
     */
    public static function databasesJson(array $rows): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new TaskRejectedException('database json encode failed');
        }

        return $json . "\n";
    }
}
