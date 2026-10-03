<?php

declare(strict_types=1);

namespace App\Support;

/** Backup job kind allowlist (agent re-validates). No tar/shell. */
final class Backup
{
    public const MAX = 10;

    /** @var list<string> */
    public const KINDS = ['home', 'mail', 'mysql'];

    public static function tryKind(string $kind): ?string
    {
        $kind = strtolower(trim($kind));
        if (! in_array($kind, self::KINDS, true)) {
            return null;
        }

        return $kind;
    }
}
