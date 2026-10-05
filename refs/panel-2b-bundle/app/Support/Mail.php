<?php

declare(strict_types=1);

namespace App\Support;

/** Virtual mailbox local-part / domain / bcrypt hash (agent re-validates). */
final class Mail
{
    public const MAX = 50;
    public const MAX_LIST_MEMBERS = 200;

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

    public static function tryPassword(string $password): ?string
    {
        if (strlen($password) < 8 || strlen($password) > 72) {
            return null;
        }
        if (strpbrk($password, "\r\n|:;`$()\\/") !== false) {
            return null;
        }

        return $password;
    }

    public static function hashPassword(string $password): ?string
    {
        if (self::tryPassword($password) === null) {
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

    public static function tryCalName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64 || strpbrk($name, "\r\n|:;`$()\\/") !== false) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._+-]{0,63}$/', $name) !== 1) {
            return null;
        }

        return $name;
    }

    public static function tryClient(string $client): ?string
    {
        $client = strtolower(trim($client));

        return in_array($client, ['roundcube', 'horde'], true) ? $client : null;
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

    /**
     * CSV: local,domain,password[,quota]  OR  email,password
     *
     * @param  list<string> $allowedDomains
     * @return list<array{local: string, domain: string, password: string, quota_mb: int}>|null
     */
    public static function parseImport(string $csv, array $allowedDomains): ?array
    {
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        if (str_starts_with($csv, "\u{FEFF}")) {
            $csv = substr($csv, strlen("\u{FEFF}"));
        }
        if ($csv === '' || strlen($csv) > 32000) {
            return null;
        }
        $rows = [];
        foreach (explode("\n", $csv) as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if (! is_array($cols) || $cols === []) {
                return null;
            }
            $cols = array_map(static fn ($c): string => is_string($c) ? trim($c) : '', $cols);
            if ($i === 0 && in_array(strtolower((string) ($cols[0] ?? '')), ['local', 'email', 'address'], true)) {
                continue;
            }
            $parsed = self::parseImportRow($cols);
            if ($parsed === null || ! in_array($parsed['domain'], $allowedDomains, true)) {
                return null;
            }
            $key = $parsed['local'] . '@' . $parsed['domain'];
            if (array_key_exists($key, $rows)) {
                return null;
            }
            $rows[$key] = $parsed;
            if (count($rows) > self::MAX) {
                return null;
            }
        }
        if ($rows === []) {
            return null;
        }

        return array_values($rows);
    }

    /**
     * @param  list<string> $cols
     * @return array{local: string, domain: string, password: string, quota_mb: int}|null
     */
    private static function parseImportRow(array $cols): ?array
    {
        $n = count($cols);
        $local = null;
        $domain = null;
        $password = null;
        $quota = 0;
        if ($n === 2) {
            $addr = self::tryDest($cols[0]);
            $password = self::tryPassword($cols[1]);
            if ($addr === null || $password === null) {
                return null;
            }
            $at = strrpos($addr, '@');
            if ($at === false) {
                return null;
            }
            $local = substr($addr, 0, $at);
            $domain = substr($addr, $at + 1);
        } elseif ($n === 3 || $n === 4) {
            $local = self::tryLocal($cols[0]);
            $domain = self::tryDomain($cols[1]);
            $password = self::tryPassword($cols[2]);
            if ($n === 4) {
                if ($cols[3] === '' || preg_match('/^-?[0-9]+$/', $cols[3]) !== 1) {
                    return null;
                }
                $quota = (int) $cols[3];
                if ($quota < -1 || $quota > 102400) {
                    return null;
                }
            }
        } else {
            return null;
        }
        if ($local === null || $domain === null || $password === null) {
            return null;
        }
        foreach ($cols as $cell) {
            if ($cell !== '' && str_contains('=+-@|', $cell[0])) {
                return null;
            }
        }

        return [
            'local' => $local,
            'domain' => $domain,
            'password' => $password,
            'quota_mb' => $quota,
        ];
    }

    public static function tryRoutingMode(string $mode): ?string
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['auto', 'local', 'backup', 'remote'], true)) {
            return null;
        }

        return $mode;
    }
}
