<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * License signing — do algorithms:
 *   * ed25519 (preferred): customer panel PUBLIC key se offline verify karta
 *     hai; secret sirf issuer (aapke license server) ke paas.
 *   * hmac-sha256 (fallback): hosts jahan sodium extension nahi — aise
 *     customer panels offline verify nahi kar sakte, activation online hoti
 *     hai aur stored record trust hota hai (root-owned file).
 *
 * Purana issue()/verify() (HMAC key-string format) backward-compat ke liye
 * bana hua hai; naya flow issueSigned()/canonical() use karta hai.
 */
final class LicenseSigner
{
    public static function hasSodium(): bool
    {
        return function_exists('sodium_crypto_sign_keypair')
            && function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Payload ko sign karo. Canonical (sorted-keys) JSON par signature hota
     * hai — customer panel ka canonicalPayload se byte-exact match.
     *
     * @param array<string, mixed> $payload
     * @return array{signature: string, algo: string}
     */
    public static function issueSigned(array $payload, string $hmacSecret): array
    {
        $message = self::canonical($payload);

        if (self::hasSodium()) {
            $kp  = self::keypair();
            $sig = sodium_crypto_sign_detached($message, base64_decode($kp['secret'], true) ?: '');

            return ['signature' => base64_encode($sig), 'algo' => 'ed25519'];
        }

        return [
            'signature' => base64_encode(hash_hmac('sha256', $message, $hmacSecret, true)),
            'algo'      => 'hmac',
        ];
    }

    /** Ed25519 public key PEM (raw 32-byte base64 body) — customer panel ke liye. */
    public static function publicPem(): ?string
    {
        if (! self::hasSodium()) {
            return null;
        }

        $body = base64_decode(self::keypair()['public'], true);

        if ($body === false || $body === '') {
            return null;
        }

        $b64 = base64_encode($body);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($b64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** @return array{secret: string, public: string} */
    public static function keypair(): array
    {
        $path = storage_path('app/private/license_signer.json');

        if (is_file($path)) {
            $kp = json_decode((string) @file_get_contents($path), true);
            if (is_array($kp) && isset($kp['secret'], $kp['public'])) {
                /** @var array{secret: string, public: string} $kp */
                return $kp;
            }
        }

        if (! self::hasSodium()) {
            throw new RuntimeException('sodium unavailable — ed25519 keypair nahi ban sakta.');
        }

        $pair = sodium_crypto_sign_keypair();
        $kp   = [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];

        @mkdir(dirname($path), 0700, true);
        @file_put_contents($path, json_encode($kp), LOCK_EX);
        @chmod($path, 0600);

        return $kp;
    }

    /**
     * Canonical JSON — panel ke LicenseClient::canonicalPayload jaisa hi:
     * recursive key-sort, lists order preserve, UNESCAPED_SLASHES|UNICODE.
     *
     * @param array<string, mixed> $value
     */
    public static function canonical(array $value): string
    {
        return json_encode(
            self::sortObject($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<mixed, mixed> $value @return array<mixed, mixed> */
    private static function sortObject(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $item): mixed => is_array($item) ? self::sortObject($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortObject($item);
            }
        }

        return $value;
    }

    // ---- legacy (HMAC key-string) — purane issued keys ke liye ----

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
