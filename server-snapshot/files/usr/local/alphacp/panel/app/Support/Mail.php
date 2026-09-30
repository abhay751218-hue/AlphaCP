<?php

declare(strict_types=1);

namespace App\Support;

/** Virtual mailbox local-part / domain / bcrypt hash (agent re-validates). */
final class Mail
{
    public const MAX = 50;

    public static function tryLocal(string $local): ?string
    {
        $local = strtolower(trim($local));
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,30}[a-z0-9])?$/', $local) !== 1) {
            return null;
        }
        if (str_contains($local, '..')) {
            return null;
        }

        return $local;
    }

    public static function tryDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if (strlen($domain) > 190 || preg_match(AccountIdentity::DOMAIN_PATTERN, $domain) !== 1) {
            return null;
        }

        return $domain;
    }

    public static function hashPassword(string $password): ?string
    {
        if (strlen($password) < 8 || strlen($password) > 72) {
            return null;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (! is_string($hash) || ! str_starts_with($hash, '$2y$')) {
            return null;
        }

        return $hash;
    }

    public static function tryDest(string $dest): ?string
    {
        $dest = strtolower(trim($dest));
        if ($dest === '' || strpbrk($dest, "\r\n|:;`$()\\/") !== false) {
            return null;
        }
        $at = strrpos($dest, '@');
        if ($at === false) {
            return null;
        }
        $local = substr($dest, 0, $at);
        $domain = self::tryDomain(substr($dest, $at + 1));
        if (preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $local) !== 1 || $domain === null) {
            return null;
        }

        return $local . '@' . $domain;
    }
}
