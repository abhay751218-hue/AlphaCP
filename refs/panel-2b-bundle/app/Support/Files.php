<?php

declare(strict_types=1);

namespace App\Support;

/** Relative paths under the account home (agent re-validates). */
final class Files
{
    public const MAX_WRITE = 262144;

    public static function tryRel(string $rel): ?string
    {
        if (str_contains($rel, "\0")) {
            return null;
        }
        $rel = str_replace('\\', '/', $rel);
        $rel = trim($rel);
        $rel = ltrim($rel, '/');
        if ($rel === '' || $rel === '.') {
            return '';
        }
        $parts = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            if (preg_match('/^[A-Za-z0-9._@+-][A-Za-z0-9._@+\- ]{0,79}$/', $seg) !== 1) {
                return null;
            }
            $parts[] = $seg;
        }
        $out = implode('/', $parts);
        if (strlen($out) > 240) {
            return null;
        }

        return $out;
    }

    public static function parent(string $rel): string
    {
        $rel = self::tryRel($rel) ?? '';
        if ($rel === '' || ! str_contains($rel, '/')) {
            return '';
        }

        return substr($rel, 0, (int) strrpos($rel, '/'));
    }
}
