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

    public static function trySubject(string $subject): ?string
    {
        $subject = trim($subject);
        if ($subject === '' || strlen($subject) > 200 || strpbrk($subject, "\r\n|:;`$()\\/") !== false) {
            return null;
        }

        return $subject;
    }

    public static function tryBody(string $body): ?string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\r", "\n", $body);
        $body = trim($body);
        if ($body === '' || strlen($body) > 4000) {
            return null;
        }
        if (strpbrk($body, "\0|:;`$") !== false || str_contains($body, '$(')) {
            return null;
        }

        return $body;
    }

    public static function tryFilterField(string $field): ?string
    {
        $field = strtolower(trim($field));

        return in_array($field, ['from', 'subject', 'to'], true) ? $field : null;
    }

    public static function tryNeedle(string $needle): ?string
    {
        $needle = trim($needle);
        if ($needle === '' || strlen($needle) > 100 || strpbrk($needle, "\r\n|:;`$()\\/") !== false) {
            return null;
        }
        if (preg_match('/^[a-zA-Z0-9 .,_@+-]+$/', $needle) !== 1) {
            return null;
        }

        return $needle;
    }

    public static function tryFilterAction(string $action): ?string
    {
        $action = strtolower(trim($action));

        return in_array($action, ['discard', 'folder'], true) ? $action : null;
    }

    public const SPF = 'v=spf1 a mx ~all';
    public const DMARC = 'v=DMARC1; p=none;';
    public const DKIM_SELECTOR = 'default';

    /** @return array{domain: string, spf: string, dmarc: string, dkim_selector: string} */
    public static function recordsFor(string $domain): array
    {
        return [
            'domain' => $domain,
            'spf' => self::SPF,
            'dmarc' => self::DMARC,
            'dkim_selector' => self::DKIM_SELECTOR,
        ];
    }
}
