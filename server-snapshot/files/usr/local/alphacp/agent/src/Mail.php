<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Virtual mailboxes under the account home. Agent never accepts plaintext
 * passwords — bcrypt hashes only (Dovecot {BLF-CRYPT}).
 */
final class Mail
{
    public const MAX = 50;

    /**
     * @param  list<mixed> $raw
     * @return list<array{local: string, domain: string, hash: string, quota_mb: int}>
     */
    public static function sanitize(array $raw): array
    {
        if (count($raw) > self::MAX) {
            throw new TaskRejectedException('too many mailboxes (50 max)');
        }
        $byAddr = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid mailbox at {$i}");
            }
            $local = self::normalizeLocal((string) ($row['local'] ?? ''));
            $domain = self::normalizeDomain((string) ($row['domain'] ?? ''));
            $hash = self::normalizeHash((string) ($row['hash'] ?? ''));
            $quota = self::normalizeQuota($row['quota_mb'] ?? 0);
            $byAddr[$local . '@' . $domain] = [
                'local'    => $local,
                'domain'   => $domain,
                'hash'     => $hash,
                'quota_mb' => $quota,
            ];
        }
        ksort($byAddr);

        return array_values($byAddr);
    }

    public static function normalizeLocal(string $local): string
    {
        $local = strtolower(trim($local));
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,30}[a-z0-9])?$/', $local) !== 1) {
            throw new TaskRejectedException('invalid mailbox local part');
        }
        if (str_contains($local, '..')) {
            throw new TaskRejectedException('mailbox local path escape');
        }

        return $local;
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $err = AccountIdentity::domain($domain);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }

        return $domain;
    }

    public static function normalizeHash(string $hash): string
    {
        $hash = trim($hash);
        if (str_starts_with($hash, '{BLF-CRYPT}')) {
            $hash = substr($hash, strlen('{BLF-CRYPT}'));
        }
        if (preg_match('/^\$2[ayb]\$[0-9]{2}\$[A-Za-z0-9.\/]{53}$/', $hash) !== 1) {
            throw new TaskRejectedException('mailbox hash must be bcrypt');
        }

        return $hash;
    }

    public static function normalizeQuota(mixed $quota): int
    {
        if (!is_int($quota) && !(is_string($quota) && preg_match('/^-?[0-9]+$/', $quota) === 1)) {
            throw new TaskRejectedException('invalid mailbox quota');
        }
        $n = (int) $quota;
        if ($n < -1 || $n > 102400) {
            throw new TaskRejectedException('mailbox quota out of range');
        }

        return $n;
    }

    public static function passwdLine(array $row, int $uid, int $gid, string $home): string
    {
        $addr = $row['local'] . '@' . $row['domain'];
        $maildir = $home . '/mail/' . $row['domain'] . '/' . $row['local'];
        $extra = '';
        if ($row['quota_mb'] > 0) {
            $extra = 'userdb_quota_rule=*:storage=' . $row['quota_mb'] . 'M';
        }

        return $addr . ':{BLF-CRYPT}' . $row['hash'] . ':' . $uid . ':' . $gid . '::' . $maildir . '::' . $extra;
    }
}
