<?php

declare(strict_types=1);

namespace App\Support;

/** SSH public keys (agent re-validates). Private keys never stored. */
final class Ssh
{
    public const MAX_KEYS = 20;
    public const MAX_BLOB = 8192;
    public const TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
    ];
    public const SHELLS = ['nologin', 'bash'];

    /**
     * @param  list<mixed> $raw
     * @return list<array{type: string, key: string, comment: string, id: string}>
     */
    public static function sanitize(array $raw): array
    {
        $byBlob = [];
        foreach ($raw as $row) {
            $parsed = null;
            if (is_string($row)) {
                $parsed = self::tryLine($row);
            } elseif (is_array($row)) {
                $parsed = self::tryFields(
                    (string) ($row['type'] ?? ''),
                    (string) ($row['key'] ?? ''),
                    (string) ($row['comment'] ?? ''),
                );
            }
            if ($parsed === null) {
                continue;
            }
            $byBlob[$parsed['type'] . ' ' . $parsed['key']] = $parsed;
        }
        $out = array_values($byBlob);

        return array_slice($out, 0, self::MAX_KEYS);
    }

    /** @return array{type: string, key: string, comment: string, id: string}|null */
    public static function tryLine(string $line): ?array
    {
        $line = str_replace("\0", '', $line);
        $line = trim(str_replace(["\r", "\n"], ' ', $line));
        if ($line === '' || str_contains($line, 'BEGIN') || str_contains($line, 'PRIVATE')) {
            return null;
        }
        $parts = preg_split('/\s+/', $line, 3) ?: [];

        return self::tryFields((string) ($parts[0] ?? ''), (string) ($parts[1] ?? ''), (string) ($parts[2] ?? ''));
    }

    /** @return array{type: string, key: string, comment: string, id: string}|null */
    public static function tryFields(string $type, string $blob, string $comment): ?array
    {
        $type = strtolower(trim($type));
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }
        $blob = trim($blob);
        if ($blob === '' || strlen($blob) > self::MAX_BLOB || preg_match('#^[A-Za-z0-9+/=]+$#', $blob) !== 1) {
            return null;
        }
        $comment = trim($comment);
        if ($comment !== '' && preg_match('/^[A-Za-z0-9._@+-]{1,64}$/', $comment) !== 1) {
            $comment = '';
        }

        return [
            'type' => $type,
            'key' => $blob,
            'comment' => $comment,
            'id' => substr(hash('sha256', $type . ' ' . $blob), 0, 16),
        ];
    }

    public static function tryShell(string $shell): ?string
    {
        $shell = strtolower(trim($shell));

        return in_array($shell, self::SHELLS, true) ? $shell : null;
    }
}
