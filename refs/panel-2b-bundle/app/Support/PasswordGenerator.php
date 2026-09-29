<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Strong random passwords that always satisfy the panel policy
 * (Password::defaults(): min length, letters, mixed case, numbers).
 *
 * Why this exists: the old generator (base64 → strip → 20 chars) produced a
 * password without a digit or without one letter case ~2.9% of the time, so
 * `alphacp:admin-password admin` (the lockout rescue path) randomly failed
 * with a validation error.
 */
final class PasswordGenerator
{
    /** No ambiguous characters (0/O, 1/l/I) for humans retyping it. */
    private const LOWER  = 'abcdefghijkmnopqrstuvwxyz';
    private const UPPER  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const DIGITS = '23456789';

    public static function generate(int $length = 20): string
    {
        $length = max(12, $length);
        $all = self::LOWER . self::UPPER . self::DIGITS;

        // One of each required class, the rest from the full alphabet…
        $chars = [
            self::pick(self::LOWER),
            self::pick(self::UPPER),
            self::pick(self::DIGITS),
        ];
        while (count($chars) < $length) {
            $chars[] = self::pick($all);
        }

        // …then a Fisher–Yates shuffle with a CSPRNG so the classes are not in fixed positions.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
