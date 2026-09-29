<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PasswordGenerator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

final class PasswordGeneratorTest extends TestCase
{
    public function test_every_generated_password_passes_the_panel_policy(): void
    {
        config(['acp.password_check_pwned' => false]);

        // The old generator failed ~2.9% of the time, so 2000 samples would
        // have caught it with near certainty (P(miss) ≈ 0.971^2000 ≈ 0).
        for ($i = 0; $i < 2000; $i++) {
            $password = PasswordGenerator::generate();

            $this->assertSame(20, strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password, 'no ambiguous characters');
        }

        $validator = Validator::make(
            ['password' => PasswordGenerator::generate()],
            ['password' => ['required', 'string', Password::defaults()]],
        );
        $this->assertFalse($validator->fails(), implode(' ', $validator->errors()->all()));
    }

    public function test_minimum_length_is_enforced(): void
    {
        $this->assertSame(12, strlen(PasswordGenerator::generate(4)));
        $this->assertSame(32, strlen(PasswordGenerator::generate(32)));
    }

    public function test_passwords_are_unique(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $seen[PasswordGenerator::generate()] = true;
        }
        $this->assertCount(500, $seen);
    }
}
