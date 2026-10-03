<?php

declare(strict_types=1);

namespace App\Support;

/** Directory Privacy entries (agent re-validates hashes/paths). */
final class Privacy
{
    public const MAX = 20;
    public const MAX_USERS = 20;

    /**
     * @param  list<mixed> $raw
     * @return list<array{path: string, realm: string, users: list<array{name: string, hash: string}>}>
     */
    public static function sanitize(array $raw): array
    {
        $byPath = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $path = Files::tryRel((string) ($row['path'] ?? ''));
            $realm = self::tryRealm((string) ($row['realm'] ?? 'Protected'));
            if ($path === null || $path === '' || $realm === null) {
                continue;
            }
            $users = [];
            foreach (is_array($row['users'] ?? null) ? $row['users'] : [] as $u) {
                if (! is_array($u)) {
                    continue;
                }
                $name = self::tryUser((string) ($u['name'] ?? ''));
                $hash = self::tryHash((string) ($u['hash'] ?? ''));
                if ($name === null || $hash === null) {
                    continue;
                }
                $users[$name] = ['name' => $name, 'hash' => $hash];
            }
            if ($users === []) {
                continue;
            }
            ksort($users);
            $byPath[$path] = [
                'path' => $path,
                'realm' => $realm,
                'users' => array_values($users),
            ];
        }
        ksort($byPath);

        return array_values($byPath);
    }

    public static function tryUser(string $name): ?string
    {
        $name = trim($name);

        return preg_match('/^[A-Za-z0-9._-]{1,32}$/', $name) === 1 ? $name : null;
    }

    public static function tryHash(string $hash): ?string
    {
        $hash = trim($hash);

        return preg_match('/^\$2[ayb]\$[0-9]{2}\$[A-Za-z0-9.\/]{53}$/', $hash) === 1 ? $hash : null;
    }

    public static function tryRealm(string $realm): ?string
    {
        $realm = trim($realm);
        if ($realm === '') {
            $realm = 'Protected';
        }

        return preg_match('/^[A-Za-z0-9 ._@+-]{1,64}$/', $realm) === 1 ? $realm : null;
    }

    public static function hashPassword(string $password): ?string
    {
        if (strlen($password) < 5 || strlen($password) > 72) {
            return null;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (! is_string($hash)) {
            return null;
        }

        return self::tryHash($hash);
    }
}
