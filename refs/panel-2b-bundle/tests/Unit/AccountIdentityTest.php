<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AccountIdentity;
use App\Support\ShadowHash;
use PHPUnit\Framework\TestCase;

class AccountIdentityTest extends TestCase
{
    public function test_reserved_names_include_system_and_panel_users(): void
    {
        $this->assertTrue(AccountIdentity::isReserved('root'));
        $this->assertTrue(AccountIdentity::isReserved('alphacp'));
        $this->assertTrue(AccountIdentity::isReserved('Admin'));
        $this->assertFalse(AccountIdentity::isReserved('alicehost'));
    }

    public function test_username_and_domain_patterns(): void
    {
        $this->assertSame(1, preg_match(AccountIdentity::USERNAME_PATTERN, 'alicehost'));
        $this->assertSame(0, preg_match(AccountIdentity::USERNAME_PATTERN, 'ab'));
        $this->assertSame(0, preg_match(AccountIdentity::USERNAME_PATTERN, 'Alice'));
        $this->assertSame(1, preg_match(AccountIdentity::DOMAIN_PATTERN, 'shop.example.com'));
        $this->assertSame(0, preg_match(AccountIdentity::DOMAIN_PATTERN, 'localhost'));
    }

    public function test_shadow_hash_is_sha512_crypt(): void
    {
        $hash = ShadowHash::make('CorrectHorse1');
        $this->assertStringStartsWith('$6$', $hash);
        $this->assertGreaterThan(20, strlen($hash));
        $this->assertStringNotContainsString('CorrectHorse1', $hash);
    }
}
