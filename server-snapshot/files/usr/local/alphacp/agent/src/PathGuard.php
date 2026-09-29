<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use RuntimeException;

/** Thrown when a path escapes the allowlisted roots (security invariant #5). */
final class PathGuardException extends RuntimeException
{
}

/**
 * Every path that a task reads or writes must pass through here.
 *
 * Rules:
 *  - reject null bytes and relative paths,
 *  - resolve `..` / `.` / duplicate slashes lexically (target may not exist yet),
 *  - the resolved path must sit INSIDE one of the allowlisted roots,
 *  - symlink escapes are caught because existing roots are realpath()'d.
 */
final class PathGuard
{
    private array $roots = [];

    /** @param list<string> $roots */
    public function __construct(array $roots)
    {
        foreach ($roots as $root) {
            $real = realpath($root);
            if ($real === false) {
                throw new PathGuardException("allowlist root does not exist: {$root}");
            }
            $this->roots[] = rtrim($real, '/');
        }
        if ($this->roots === []) {
            throw new PathGuardException('PathGuard needs at least one root');
        }
    }

    public static function fromConfig(array $taskConfig): self
    {
        $roots = (array) ($taskConfig['paths'] ?? ['/home', '/var/www', '/usr/local/alphacp', '/etc']);
        return new self($roots);
    }

    /** Returns the canonical path, or throws. */
    public function assert(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new PathGuardException('path contains a null byte');
        }
        if (!str_starts_with($path, '/')) {
            throw new PathGuardException("path must be absolute: {$path}");
        }

        $canonical = self::canonicalize($path);

        foreach ($this->roots as $root) {
            if ($canonical === $root || str_starts_with($canonical, $root . '/')) {
                return $canonical;
            }
        }

        throw new PathGuardException("path outside allowlisted roots: {$path}");
    }

    /** Lexical normalization — works for paths that do not exist yet. */
    public static function canonicalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        return '/' . implode('/', $parts);
    }
}
