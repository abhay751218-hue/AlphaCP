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
}
