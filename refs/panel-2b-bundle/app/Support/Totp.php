<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RFC 6238 TOTP (Google Authenticator / Authy compatible).
 *
 * Hand-rolled and dependency-free on purpose: the panel core must not depend
 * on a third-party package for its second factor. Tested against the RFC
 * test vectors in tests/Unit/TotpTest.php.
 *
 * Defaults: SHA-1, 6 digits, 30 second period, +/-1 step drift window.
 * Replay protection: callers store the last accepted step per user.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }
        return $secret;
    }

    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    public static function codeForStep(string $secret, int $step): string
    {
        $key    = self::base32Decode($secret);
        $binary = pack('N*', 0) . pack('N*', $step);

        $hash   = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
               | ((ord($hash[$offset + 1]) & 0xFF) << 16)
               | ((ord($hash[$offset + 2]) & 0xFF) << 8)
               | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        return self::codeForStep($secret, self::step($timestamp));
    }

    /**
     * @return int|null matched step (store it to block replays), or null
     */
    public static function verify(string $secret, string $code, ?int $minStep = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $now = self::step();
        for ($drift = -$window; $drift <= $window; $drift++) {
            $step = $now + $drift;
            if ($minStep !== null && $step <= $minStep) {
                continue;
            }
            if (hash_equals(self::codeForStep($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer = 'AlphaCP'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public static function prettySecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits   = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr((int) bindec($byte));
            }
        }

        return $binary;
    }
}
