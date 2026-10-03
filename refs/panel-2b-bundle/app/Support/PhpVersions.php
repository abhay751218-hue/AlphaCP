<?php

declare(strict_types=1);

namespace App\Support;

/** Installed MultiPHP versions on this Ubuntu stack (Ondrej + 7.4). */
final class PhpVersions
{
    /** @var list<string> */
    public const ALLOWED = ['8.4', '8.3', '8.2', '8.1', '7.4'];

    public static function pattern(): string
    {
        return '/^(7\\.4|8\\.[0-9])$/';
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALLOWED;
    }
}
