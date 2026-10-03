<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Directory Privacy (cPanel). Apache Basic Auth for folders under the account home.
 * Agent never accepts plaintext passwords — bcrypt htpasswd hashes only.
 */
final class Privacy
{
    public const MAX = 20;
    public const MAX_USERS = 20;

    /**
     * @param  list<mixed> $raw
     * @return list<array{path: string, realm: string, slug: string, users: list<array{name: string, hash: string}>}>
     */
    public static function sanitize(array $raw): array
    {
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many privacy folders (20 max)');
        }
        $byPath = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid privacy entry at {$i}");
            }
            $path = Files::normalizeRel((string) ($row['path'] ?? ''));
            if ($path === '') {
                throw new TaskRejectedException('cannot protect account home root');
            }
            $realm = self::normalizeRealm((string) ($row['realm'] ?? 'Protected'));
            $usersRaw = $row['users'] ?? [];
            if (!is_array($usersRaw)) {
                throw new TaskRejectedException("invalid users for {$path}");
            }
            if (count($usersRaw) > self::MAX_USERS) {
                throw new TaskRejectedException('too many privacy users (20 max)');
            }
            $users = [];
            foreach ($usersRaw as $u) {
                if (!is_array($u)) {
                    throw new TaskRejectedException('invalid privacy user');
                }
                $name = self::normalizeUser((string) ($u['name'] ?? ''));
                $hash = self::normalizeHash((string) ($u['hash'] ?? ''));
                $users[$name] = ['name' => $name, 'hash' => $hash];
            }
            if ($users === []) {
                throw new TaskRejectedException("privacy folder {$path} needs at least one user");
            }
            ksort($users);
            $byPath[$path] = [
                'path'  => $path,
                'realm' => $realm,
                'slug'  => self::slug($path),
                'users' => array_values($users),
            ];
        }
        ksort($byPath);

        return array_values($byPath);
    }

    public static function slug(string $path): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/', '-', $path));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'dir';
        }

        return substr($slug, 0, 80);
    }

    public static function normalizeUser(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $name) !== 1) {
            throw new TaskRejectedException('invalid privacy username');
        }

        return $name;
    }

    public static function normalizeHash(string $hash): string
    {
        $hash = trim($hash);
        if (preg_match('/^\$2[ayb]\$[0-9]{2}\$[A-Za-z0-9.\/]{53}$/', $hash) !== 1) {
            throw new TaskRejectedException('privacy hash must be bcrypt');
        }

        return $hash;
    }

    public static function normalizeRealm(string $realm): string
    {
        $realm = trim($realm);
        if ($realm === '') {
            $realm = 'Protected';
        }
        if (preg_match('/^[A-Za-z0-9 ._@+-]{1,64}$/', $realm) !== 1) {
            throw new TaskRejectedException('invalid privacy realm');
        }

        return $realm;
    }
}
