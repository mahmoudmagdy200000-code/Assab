<?php

namespace Tests\Unit;

use App\Support\TemporaryPassword;
use Tests\TestCase;

/**
 * Meeting 2026-08-04: the emailed temporary password (`%OP43zSVf}-h`) failed the
 * mobile login screen's own field validation — «Password must be 8+ characters
 * and include a-z, A-Z, 0-9, and one of $, &, @» — leaving the Log In button
 * disabled. Every generated credential must be typeable into that screen.
 */
class TemporaryPasswordTest extends TestCase
{
    public function test_every_generated_password_satisfies_the_app_policy(): void
    {
        // Loop: the guarantee must hold by construction, not by luck.
        for ($i = 0; $i < 200; $i++) {
            $password = TemporaryPassword::generate();

            $this->assertSame(12, mb_strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[$&@]/', $password);
            $this->assertTrue(TemporaryPassword::satisfiesPolicy($password));
        }
    }

    /** No character outside the app's accepted set, and no look-alikes. */
    public function test_it_uses_only_characters_the_app_accepts(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z0-9$&@]+$/',
                TemporaryPassword::generate(),
                'punctuation outside $ & @ is rejected by the login field',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/[0O1lI]/',
                TemporaryPassword::generate(),
                'look-alike characters cause "the emailed password does not work" tickets',
            );
        }
    }

    public function test_a_shorter_length_is_raised_to_the_policy_minimum(): void
    {
        $this->assertSame(8, mb_strlen(TemporaryPassword::generate(4)));
        $this->assertSame(16, mb_strlen(TemporaryPassword::generate(16)));
    }

    public function test_the_policy_check_rejects_what_the_app_rejects(): void
    {
        $this->assertFalse(TemporaryPassword::satisfiesPolicy('%OP43zSVf}-h'), 'no $ & @');
        $this->assertFalse(TemporaryPassword::satisfiesPolicy('abc$1234'), 'no uppercase');
        $this->assertFalse(TemporaryPassword::satisfiesPolicy('ABC$1234'), 'no lowercase');
        $this->assertFalse(TemporaryPassword::satisfiesPolicy('Abcdefg$'), 'no digit');
        $this->assertFalse(TemporaryPassword::satisfiesPolicy('Ab$12'), 'too short');
        $this->assertTrue(TemporaryPassword::satisfiesPolicy('Ab$12345'));
    }

    public function test_two_calls_do_not_collide(): void
    {
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $seen[] = TemporaryPassword::generate();
        }

        $this->assertCount(100, array_unique($seen));
    }
}
