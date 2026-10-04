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
        $this->rootFor($canonical);

        return $canonical;
    }

    /**
     * Like assert(), but ALSO refuses symlinks anywhere in the path (including
     * the last component).
     *
     * Why this exists: canonicalize() is lexical, so `~/loot` -> `/etc` (a
     * symlink a hosting customer can create themselves) would pass the lexical
     * check while the kernel follows it — and every agent write happens as
     * ROOT. That is a root file-write escape. Callers that touch the
     * filesystem must use this method (SafeFs does).
     */
    public function assertNoSymlink(string $path): string
    {
        return $this->assertChain($path, true);
    }

    /**
     * Like assertNoSymlink(), but only the parent components are checked, so
     * the caller may operate on a symlink itself (e.g. unlink/replace it).
     */
    public function assertNoSymlinkParents(string $path): string
    {
        return $this->assertChain($path, false);
    }

    private function assertChain(string $path, bool $includeLast): string
    {
        $canonical = $this->assert($path);
        $root = $this->rootFor($canonical);
        $relative = substr($canonical, strlen($root));
        if ($relative === '' || $relative === '/') {
            return $canonical; // the root itself was realpath()'d in the constructor
        }
        $segments = explode('/', ltrim($relative, '/'));
        $last = count($segments) - 1;
        $current = $root;
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= '/' . $segment;
            if (! $includeLast && $index === $last) {
                break;
            }
            if (is_link($current)) {
                throw new PathGuardException("symlink not allowed in path: {$current}");
            }
        }

        return $canonical;
    }

    private function rootFor(string $canonical): string
    {
        foreach ($this->roots as $root) {
            if ($canonical === $root || str_starts_with($canonical, $root . '/')) {
                return $root;
            }
        }

        throw new PathGuardException("path outside allowlisted roots: {$canonical}");
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
