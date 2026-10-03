<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use RuntimeException;

/**
 * PathGuard-gated filesystem helpers for account provisioning.
 *
 * Handlers never call file_put_contents / mkdir on raw user input — every
 * path is canonicalised and checked against the task's allowlisted roots.
 *
 * Symlink safety (security fix 3 Oct): lexical canonicalisation alone is NOT
 * enough. A hosting customer can create `~/loot -> /etc` inside their own
 * home, and then the kernel would follow it — as root. Every method here
 * therefore also refuses symlinks in the path chain:
 *   - assertSafe()        : parent chain + the last component
 *   - assertSafeParents() : parent chain only (so a symlink itself can be
 *                           deleted/replaced, but never traversed through)
 */
final class SafeFs
{
    public function __construct(private readonly PathGuard $guard)
    {
    }

    /** Lexical check only — prefer assertSafe() for anything filesystem-touching. */
    public function assert(string $path): string
    {
        return $this->guard->assert($path);
    }

    /** Canonical path inside a root, with no symlinks anywhere in it. */
    public function assertSafe(string $path): string
    {
        return $this->guard->assertNoSymlink($path);
    }

    /** Canonical path inside a root; only parent components must be symlink-free. */
    public function assertSafeParents(string $path): string
    {
        return $this->guard->assertNoSymlinkParents($path);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->guard->assert($path));
    }

    public function isDir(string $path): bool
    {
        return is_dir($this->guard->assert($path));
    }

    public function isFile(string $path): bool
    {
        return is_file($this->guard->assert($path));
    }

    public function isLink(string $path): bool
    {
        return is_link($this->guard->assert($path));
    }

    public function mkdir(string $path, int $mode = 0750): string
    {
        $path = $this->guard->assertNoSymlink($path);
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException("mkdir failed: {$path}");
        }
        @chmod($path, $mode);
        return $path;
    }

    public function write(string $path, string $content, int $mode = 0644): string
    {
        $path = $this->guard->assertNoSymlink($path);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            $this->mkdir($dir, 0755);
        }
        $tmp = $dir . '/.acp-' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException("write failed: {$path}");
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("atomic replace failed: {$path}");
        }
        @chmod($path, $mode);
        return $path;
    }

    public function unlink(string $path): void
    {
        $path = $this->guard->assertNoSymlinkParents($path);
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    public function rename(string $from, string $to): void
    {
        $from = $this->guard->assertNoSymlinkParents($from);
        $to = $this->guard->assertNoSymlinkParents($to);
        if (!@rename($from, $to)) {
            throw new RuntimeException("rename failed: {$from} -> {$to}");
        }
    }

    public function symlink(string $target, string $link): void
    {
        $target = $this->guard->assertNoSymlink($target);
        $link = $this->guard->assertNoSymlinkParents($link);
        if (is_link($link) || file_exists($link)) {
            @unlink($link);
        }
        if (!@symlink($target, $link)) {
            throw new RuntimeException("symlink failed: {$link} -> {$target}");
        }
    }

    public function chmod(string $path, int $mode): void
    {
        @chmod($this->guard->assertNoSymlink($path), $mode);
    }

    public function read(string $path): string
    {
        $path = $this->guard->assertNoSymlink($path);
        if (!is_file($path)) {
            throw new RuntimeException("not a file: {$path}");
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("read failed: {$path}");
        }

        return $data;
    }

    /** @return list<string> */
    public function listNames(string $path): array
    {
        $path = $this->guard->assertNoSymlink($path);
        if (!is_dir($path)) {
            throw new RuntimeException("not a directory: {$path}");
        }
        $names = @scandir($path);
        if (!is_array($names)) {
            throw new RuntimeException("scandir failed: {$path}");
        }
        $out = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $out[] = $name;
        }

        return $out;
    }

    public function rmdir(string $path): void
    {
        $path = $this->guard->assertNoSymlinkParents($path);
        if (is_link($path)) {
            throw new RuntimeException("refusing to rmdir a symlink: {$path}");
        }
        if (!is_dir($path)) {
            return;
        }
        if (!@rmdir($path)) {
            throw new RuntimeException("rmdir failed (not empty?): {$path}");
        }
    }

    /**
     * Best-effort ownership. Missing users (tests / wasm) must not fail the task.
     */
    public function chownName(string $path, string $owner, ?string $group = null): void
    {
        $path = $this->guard->assertNoSymlink($path);
        if (function_exists('posix_getpwnam') && posix_getpwnam($owner) !== false) {
            @chown($path, $owner);
        }
        $group ??= $owner;
        if (function_exists('posix_getgrnam') && posix_getgrnam($group) !== false) {
            @chgrp($path, $group);
        }
    }
}
