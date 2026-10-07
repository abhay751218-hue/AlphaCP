<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Signed license keys (HMAC-SHA256) — sellable, offline-verifiable.
 * Format: base64url(json payload) . '.' . base64url(hmac_sha256(json, secret)).
 * Customer panel locally verify kar sakta hai (secret sirf issuer ke paas).
 */
final class LicenseSigner
{
    public static function issue(array $payload, string $secret): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig  = hash_hmac('sha256', (string) $json, $secret, true);

        return self::b64url((string) $json) . '.' . self::b64url($sig);
    }

    /** @return array|null payload, ya null agar signature/expiry invalid */
    public static function verify(string $key, string $secret): ?array
    {
        $parts = explode('.', $key);
        if (count($parts) !== 2) {
            return null;
        }

        $json = self::b64urlDecode($parts[0]);
        $sig  = self::b64urlDecode($parts[1]);
        if ($json === null || $sig === null) {
            return null;
        }

        $expect = hash_hmac('sha256', $json, $secret, true);
        if (! hash_equals($expect, $sig)) {
            return null;
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null; // expired
        }

        return $payload;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        $pad  = strlen($s) % 4;
        $s   .= $pad ? str_repeat('=', 4 - $pad) : '';
        $raw  = base64_decode(strtr($s, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
