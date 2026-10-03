<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * SSH Access — authorized_keys + nologin/bash. No private keys. No key options.
 */
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
    public const BASH = '/bin/bash';

    /**
     * @param  list<mixed> $raw
     * @return list<array{type: string, key: string, comment: string}>
     */
    public static function sanitizeKeys(array $raw): array
    {
        if (count($raw) > self::MAX_KEYS) {
            throw new TaskRejectedException('too many ssh keys (20 max)');
        }
        $byBlob = [];
        foreach ($raw as $i => $row) {
            if (is_string($row)) {
                $parsed = self::parseLine($row);
            } elseif (is_array($row)) {
                $parsed = self::fromFields(
                    (string) ($row['type'] ?? ''),
                    (string) ($row['key'] ?? ''),
                    (string) ($row['comment'] ?? ''),
                );
            } else {
                throw new TaskRejectedException("invalid ssh key at {$i}");
            }
            $byBlob[$parsed['type'] . ' ' . $parsed['key']] = $parsed;
        }

        return array_values($byBlob);
    }

    /** @return array{type: string, key: string, comment: string} */
    public static function parseLine(string $line): array
    {
        $line = str_replace("\0", '', $line);
        $line = trim(str_replace(["\r", "\n"], '', $line));
        if ($line === '') {
            throw new TaskRejectedException('empty ssh key');
        }
        if (str_contains($line, 'BEGIN') || str_contains($line, 'PRIVATE')) {
            throw new TaskRejectedException('private keys are not allowed');
        }
        $parts = preg_split('/\s+/', $line, 3) ?: [];
        $type = (string) ($parts[0] ?? '');
        $blob = (string) ($parts[1] ?? '');
        $comment = (string) ($parts[2] ?? '');

        return self::fromFields($type, $blob, $comment);
    }

    /** @return array{type: string, key: string, comment: string} */
    public static function fromFields(string $type, string $blob, string $comment): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::TYPES, true)) {
            throw new TaskRejectedException('unsupported ssh key type');
        }
        $blob = trim($blob);
        if ($blob === '' || strlen($blob) > self::MAX_BLOB) {
            throw new TaskRejectedException('invalid ssh key blob');
        }
        if (preg_match('#^[A-Za-z0-9+/=]+$#', $blob) !== 1) {
            throw new TaskRejectedException('ssh key blob must be base64');
        }
        $comment = trim($comment);
        if ($comment !== '' && preg_match('/^[A-Za-z0-9._@+-]{1,64}$/', $comment) !== 1) {
            throw new TaskRejectedException('invalid ssh key comment');
        }

        return ['type' => $type, 'key' => $blob, 'comment' => $comment];
    }

    public static function format(array $row): string
    {
        $line = $row['type'] . ' ' . $row['key'];
        if (($row['comment'] ?? '') !== '') {
            $line .= ' ' . $row['comment'];
        }

        return $line;
    }

    public static function normalizeShell(string $shell): string
    {
        $shell = strtolower(trim($shell));
        if (!in_array($shell, self::SHELLS, true)) {
            throw new TaskRejectedException('invalid ssh shell');
        }

        return $shell;
    }

    public static function idFor(array $row): string
    {
        return substr(hash('sha256', $row['type'] . ' ' . $row['key']), 0, 16);
    }
}
