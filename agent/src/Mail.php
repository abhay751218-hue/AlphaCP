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
    public const MAX_FWD = 50;
    public const MAX_RESP = 50;

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

    /**
     * @param  list<mixed> $raw
     * @return list<array{local: string, domain: string, dest: string}>
     */
    public static function sanitizeForwards(array $raw): array
    {
        if (count($raw) > self::MAX_FWD) {
            throw new TaskRejectedException('too many forwarders (50 max)');
        }
        $bySrc = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid forwarder at {$i}");
            }
            $local = self::normalizeLocal((string) ($row['local'] ?? ''));
            $domain = self::normalizeDomain((string) ($row['domain'] ?? ''));
            $dest = self::normalizeDest((string) ($row['dest'] ?? ''));
            $src = $local . '@' . $domain;
            if ($src === $dest) {
                throw new TaskRejectedException('forwarder dest cannot equal source');
            }
            $bySrc[$src] = ['local' => $local, 'domain' => $domain, 'dest' => $dest];
        }
        ksort($bySrc);

        return array_values($bySrc);
    }

    public static function normalizeDest(string $dest): string
    {
        $dest = strtolower(trim($dest));
        if ($dest === '' || strpbrk($dest, "\r\n|:;`$()\\/") !== false) {
            throw new TaskRejectedException('forwarder dest must be an email (no pipe/shell)');
        }
        $at = strrpos($dest, '@');
        if ($at === false) {
            throw new TaskRejectedException('forwarder dest must be an email');
        }
        $local = substr($dest, 0, $at);
        $domain = substr($dest, $at + 1);
        if (preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $local) !== 1) {
            throw new TaskRejectedException('invalid forwarder dest local');
        }
        $err = AccountIdentity::domain($domain);
        if ($err !== null) {
            throw new TaskRejectedException('invalid forwarder dest domain');
        }

        return $local . '@' . $domain;
    }

    public static function aliasLine(array $row): string
    {
        return $row['local'] . '@' . $row['domain'] . ': ' . $row['dest'];
    }

    /**
     * @param  list<mixed> $raw
     * @return list<array{local: string, domain: string, subject: string, body: string, interval_h: int}>
     */
    public static function sanitizeResponders(array $raw): array
    {
        if (count($raw) > self::MAX_RESP) {
            throw new TaskRejectedException('too many autoresponders (50 max)');
        }
        $bySrc = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw new TaskRejectedException("invalid autoresponder at {$i}");
            }
            $local = self::normalizeLocal((string) ($row['local'] ?? ''));
            $domain = self::normalizeDomain((string) ($row['domain'] ?? ''));
            $subject = self::normalizeSubject((string) ($row['subject'] ?? ''));
            $body = self::normalizeBody((string) ($row['body'] ?? ''));
            $interval = self::normalizeInterval($row['interval_h'] ?? 24);
            $src = $local . '@' . $domain;
            $bySrc[$src] = [
                'local' => $local,
                'domain' => $domain,
                'subject' => $subject,
                'body' => $body,
                'interval_h' => $interval,
            ];
        }
        ksort($bySrc);

        return array_values($bySrc);
    }

    public static function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '' || strlen($subject) > 200 || strpbrk($subject, "\r\n|:;`$()\\/") !== false) {
            throw new TaskRejectedException('autoresponder subject invalid (no pipe/shell)');
        }

        return $subject;
    }

    public static function normalizeBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\r", "\n", $body);
        $body = trim($body);
        if ($body === '' || strlen($body) > 4000) {
            throw new TaskRejectedException('autoresponder body length');
        }
        if (strpbrk($body, "\0|:;`$") !== false || str_contains($body, '$(')) {
            throw new TaskRejectedException('autoresponder body must not contain pipe/shell');
        }

        return $body;
    }

    public static function normalizeInterval(mixed $hours): int
    {
        if (!is_int($hours) && !(is_string($hours) && preg_match('/^[0-9]+$/', $hours) === 1)) {
            throw new TaskRejectedException('invalid autoresponder interval');
        }
        $n = (int) $hours;
        if ($n < 0 || $n > 168) {
            throw new TaskRejectedException('autoresponder interval out of range');
        }

        return $n;
    }

    /**
     * @param  list<array{local: string, domain: string, subject: string, body: string, interval_h: int}> $rows
     */
    public static function respondJson(array $rows): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new TaskRejectedException('autoresponder json encode failed');
        }

        return $json . "\n";
    }
}
