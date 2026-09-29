<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** SHA-512 crypt() hash for /etc/shadow (useradd -p). Never log the input. */
final class ShadowHash
{
    public static function make(string $password): string
    {
        $salt = bin2hex(random_bytes(8));
        $hash = crypt($password, '$6$rounds=5000$' . $salt . '$');
        if (! is_string($hash) || strlen($hash) < 20 || ! str_starts_with($hash, '$6$')) {
            throw new RuntimeException('crypt() SHA-512 hash nahi bana.');
        }
        return $hash;
    }
}
