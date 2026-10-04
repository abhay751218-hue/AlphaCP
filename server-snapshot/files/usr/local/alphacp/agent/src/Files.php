<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Account-home file paths. Relative only, no `..`, stays under /home/<user>.
 */
final class Files
{
    public const MAX_WRITE = 262144;
    public const MAX_LIST = 500;
    public const MAX_USAGE_NODES = 2000;
    public const MAX_USAGE_CHILDREN = 200;
    public const OPS = ['mkdir', 'write', 'delete', 'rename'];

    public static function normalizeRel(string $rel): string
    {
        if (str_contains($rel, "\0")) {
            throw new TaskRejectedException('null byte in path is not allowed');
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
                throw new TaskRejectedException('path escape (..) not allowed');
            }
            if (preg_match('/^[A-Za-z0-9._@+-][A-Za-z0-9._@+\- ]{0,79}$/', $seg) !== 1) {
                throw new TaskRejectedException('invalid path segment');
            }
            $parts[] = $seg;
        }
        $out = implode('/', $parts);
        if (strlen($out) > 240) {
            throw new TaskRejectedException('path too long');
        }

        return $out;
    }

    public static function resolve(string $home, string $rel): string
    {
        $home = rtrim($home, '/');
        $rel = self::normalizeRel($rel);
        $full = $rel === '' ? $home : $home . '/' . $rel;
        $canonical = PathGuard::canonicalize($full);
        if ($canonical !== $home && !str_starts_with($canonical, $home . '/')) {
            throw new TaskRejectedException('path outside account home');
        }

        return $canonical;
    }

    public static function normalizeOp(string $op): string
    {
        $op = strtolower(trim($op));
        if (!in_array($op, self::OPS, true)) {
            throw new TaskRejectedException('invalid files op');
        }

        return $op;
    }
}
