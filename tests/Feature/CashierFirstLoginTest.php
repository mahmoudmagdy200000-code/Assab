<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/**
 * Meeting 2026-08-15 — the cashier joins the shared mobile first-login contract.
 *
 * It was the only one of the four account types with no `first-login` step and
 * no first-login token: an app build implementing the two-step flow the branch
 * manager / brand owner / supplier use could not activate a cashier at all.
 * The one-shot `/activate` the shipped app posts keeps working unchanged.
 */
class CashierFirstLoginTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_PASSWORD = 'Issued-2026';

    private const NEW_PASSWORD = 'Newpass2026';

    private function cashier(string $status = 'pending'): Cashier
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);

        return Cashier::create([
            'name' => 'كاشير',
            'email' => 'cashier@chain.test',
            'phone' => '0500000123',
            'password' => self::DEFAULT_PASSWORD,
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'status' => $status,
        ]);
    }

    // ── step 1: first-login ─────────────────────────────────────────────────

    public function test_first_login_returns_a_first_login_token_and_activates_the_account(): void
    {
        $cashier = $this->cashier();

        $res = $this->postJson('/api/v1/cashier/auth/first-login', [
            'identifier' => 'cashier@chain.test',
            'password' => self::DEFAULT_PASSWORD,
        ])->assertStatus(200);

        $token = $res->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertSame(
            'first-login-token',
            PersonalAccessToken::findToken($token)->name,
            'the token must be the one the shared activation resolver recognises',
        );

        // Policy off (default): the sign-in itself completes activation, so the
        // app must NOT route to «activate Account».
        $this->assertFalse($res->json('data.requires_password_reset'));
        $cashier->refresh();
        $this->assertSame('active', $cashier->status);
        $this->assertNotNull($cashier->activated_at);
    }

    public function test_first_login_by_phone_works_too(): void
    {
        $this->cashier();

        $this->postJson('/api/v1/cashier/auth/first-login', [
            'identifier' => '0500000123',
            'password' => self::DEFAULT_PASSWORD,
        ])->assertStatus(200);
    }

    public function test_an_unknown_identifier_answers_the_same_401_as_a_wrong_password(): void
    {
        $this->cashier();

        // 404 here would turn the endpoint into an account enumerator.
        $this->postJson('/api/v1/cashier/auth/first-login', [
            'identifier' => 'nobody@chain.test',
            'password' => self::DEFAULT_PASSWORD,
        ])->assertStatus(401);

        $this->postJson('/api/v1/cashier/auth/first-login', [
            'identifier' => 'cashier@chain.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_a_deactivated_cashier_cannot_first_login(): void
    {
        $this->cashier('deactivated');

        $this->postJson('/api/v1/cashier/auth/first-login', [
            'identifier' => 'cashier@chain.test',
            'password' => self::DEFAULT_PASSWORD,
        ])->assertStatus(403);
    }

    public function test_the_forced_flow_keeps_the_account_pending_and_asks_for_the_reset(): void
    {
        config(['features.mobile_force_first_login_reset' => true]);
        $cashier = $this->cashier();

        $res = $this->postJson('/api/v1/cashier/auth/first-login', [
            'identifier' => 'cashier@chain.test',
            'password' => self::DEFAULT_PASSWORD,
        ])->assertStatus(200);

        $this->assertTrue($res->json('data.requires_password_reset'));
        $this->assertSame('pending', $cashier->fresh()->status);
    }

    // ── step 2: activate / reset-password-first-login ───────────────────────

    public function test_the_first_login_token_alone_activates_the_account(): void
    {
        $cashier = $this->cashier();
        config(['features.mobile_force_first_login_reset' => true]); // stay pending

        $token = $this->postJson('/api/v1/cashier/auth/first-login', [
            'identifier' => 'cashier@chain.test',
            'password' => self::DEFAULT_PASSWORD,
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/cashier/auth/reset-password-first-login', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertStatus(200);

        $cashier->refresh();
        $this->assertSame('active', $cashier->status);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $cashier->password));
    }

    public function test_the_shipped_activate_screen_shape_still_works_unchanged(): void
    {
        $cashier = $this->cashier();

        $this->postJson('/api/v1/cashier/auth/activate', [
            'identifier' => 'cashier@chain.test',
            'default_password' => self::DEFAULT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(200)->assertJsonPath('data.redirect_to_login', true);

        $cashier->refresh();
        $this->assertSame('active', $cashier->status);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $cashier->password));
    }

    public function test_activation_without_any_proof_is_401_not_a_validation_dead_end(): void
    {
        $this->cashier();

        $this->postJson('/api/v1/cashier/auth/activate', [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(401);
    }

    public function test_an_active_account_cannot_be_rotated_by_a_bare_session_token(): void
    {
        $cashier = $this->cashier('active');
        $sessionToken = $cashier->createToken('cashier-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$sessionToken)
            ->postJson('/api/v1/cashier/auth/reset-password-first-login', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertStatus(400);

        $this->assertTrue(Hash::check(self::DEFAULT_PASSWORD, $cashier->fresh()->password));
    }

    public function test_the_password_rules_of_the_old_activation_screen_are_kept(): void
    {
        $this->cashier();

        $body = [
            'identifier' => 'cashier@chain.test',
            'default_password' => self::DEFAULT_PASSWORD,
        ];

        // no uppercase, no digit, and too short — each must still be refused.
        $this->postJson('/api/v1/cashier/auth/activate', $body + [
            'password' => 'newpass2026', 'password_confirmation' => 'newpass2026',
        ])->assertStatus(422);

        $this->postJson('/api/v1/cashier/auth/activate', $body + [
            'password' => 'Newpassword', 'password_confirmation' => 'Newpassword',
        ])->assertStatus(422);

        $this->postJson('/api/v1/cashier/auth/activate', $body + [
            'password' => 'Np2026', 'password_confirmation' => 'Np2026',
        ])->assertStatus(422);
    }

    public function test_login_works_with_the_new_password_after_activation(): void
    {
        $this->cashier();

        $this->postJson('/api/v1/cashier/auth/activate', [
            'identifier' => 'cashier@chain.test',
            'default_password' => self::DEFAULT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(200);

        $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => 'cashier@chain.test',
            'password' => self::NEW_PASSWORD,
        ])->assertStatus(200)->assertJsonStructure(['data' => ['user', 'token']]);
    }
}
