<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The panel must not be clickjackable in production — but a dev/preview box
 * (--insecure-http) is embedded in an iframe on purpose. Both behaviours are
 * explicit here so neither can silently change.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_production_defaults_block_framing(): void
    {
        config(['acp.security.frame_options' => 'DENY']);

        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_sameorigin_mode_is_honoured(): void
    {
        config(['acp.security.frame_options' => 'SAMEORIGIN']);

        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_dev_mode_omits_framing_headers_for_previews(): void
    {
        config(['acp.security.frame_options' => 'OFF']);

        $response = $this->get('/');

        $this->assertFalse($response->headers->has('X-Frame-Options'), 'X-Frame-Options must be absent in OFF mode');
        $this->assertStringContainsString(
            'frame-ancestors *',
            (string) $response->headers->get('Content-Security-Policy'),
            'OFF mode says out loud that framing is allowed, it does not stay silent',
        );
    }
}
