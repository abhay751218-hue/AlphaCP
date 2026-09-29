<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 test vectors (SHA-1). If these break, every 2FA login breaks —
 * so this is the first test any change to Totp must pass.
 */
class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"

    public function test_rfc6238_sha1_vectors(): void
    {
        $vectors = [
            59         => '287082',  // 94287082 truncated to 6 digits
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame(
                $expected,
                Totp::code(self::RFC_SECRET, $timestamp),
                "TOTP mismatch at t={$timestamp}",
            );
        }
    }

    public function test_generated_secret_is_base32_and_correct_length(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function test_verify_accepts_current_code(): void
    {
        $secret = Totp::generateSecret();
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret)));
    }

    public function test_verify_accepts_codes_inside_drift_window(): void
    {
        $secret = Totp::generateSecret();
        $previous = Totp::codeForStep($secret, Totp::step() - 1);
        $next     = Totp::codeForStep($secret, Totp::step() + 1);

        $this->assertNotNull(Totp::verify($secret, $previous));
        $this->assertNotNull(Totp::verify($secret, $next));
    }

    public function test_verify_rejects_codes_outside_drift_window(): void
    {
        $secret = Totp::generateSecret();
        $old    = Totp::codeForStep($secret, Totp::step() - 5);

        $this->assertNull(Totp::verify($secret, $old));
    }

    public function test_verify_blocks_replays(): void
    {
        $secret = Totp::generateSecret();
        $step   = Totp::verify($secret, Totp::code($secret));
        $this->assertNotNull($step);

        // Same code again with the step recorded → refused.
        $this->assertNull(Totp::verify($secret, Totp::codeForStep($secret, $step), $step));
    }

    public function test_verify_rejects_junk(): void
    {
        $secret = Totp::generateSecret();
        $this->assertNull(Totp::verify($secret, '12345'));      // too short
        $this->assertNull(Totp::verify($secret, 'abcdef'));     // not digits
        $this->assertNull(Totp::verify($secret, ''));
    }

    public function test_codes_are_tolerated_with_spaces(): void
    {
        $secret = Totp::generateSecret();
        $code   = Totp::code($secret);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        $this->assertNotNull(Totp::verify($secret, $spaced));
    }

    public function test_provisioning_uri_shape(): void
    {
        $uri = Totp::provisioningUri('ABCDEFGHIJKLMNOP', 'admin');
        $this->assertStringStartsWith('otpauth://totp/AlphaCP:admin?', $uri);
        $this->assertStringContainsString('secret=ABCDEFGHIJKLMNOP', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_pretty_secret_is_grouped(): void
    {
        $this->assertSame('ABCD EFGH IJKL', Totp::prettySecret('ABCDEFGHIJKL'));
    }
}
