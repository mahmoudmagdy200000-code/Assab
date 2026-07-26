<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * «activate Account» → "Unauthenticated." (reported 2026-07-26 for a supplier
 * created from the dashboard).
 *
 * The screen has two password boxes and nothing else, and the endpoint sat
 * behind `auth:sanctum` — so an app build that does not attach the first-login
 * token as a Bearer header hit a dead end it could not recover from. The handler
 * now accepts any proof of the issued credential: the token in the header, the
 * token in the body, or the default password again (the cashier `/activate`
 * shape). Nothing may set a password without one of those.
 */
class MobileFirstLoginActivationTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'Dashboard Supplier',
            'email' => 'activate-'.uniqid().'@assab.com',
            'phone' => '05'.random_int(10000000, 99999999),
            'password' => bcrypt('default_password'),
            'is_active' => true,
            'is_first_login' => true,
        ], $attributes));
    }

    private function firstLoginToken(Supplier $supplier): string
    {
        return $this->postJson('/api/v1/supplier/auth/first-login', [
            'email' => $supplier->email,
            'password' => 'default_password',
        ])->assertStatus(200)->json('data.token');
    }

    // ---- supplier ----

    public function test_activation_works_with_the_bearer_token(): void
    {
        $supplier = $this->supplier();
        $token = $this->firstLoginToken($supplier);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/supplier/auth/password/reset/first-login', [
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])->assertStatus(200);

        $supplier->refresh();
        $this->assertFalse((bool) $supplier->is_first_login);
        $this->assertTrue(Hash::check('NewPass123', $supplier->password));
    }

    public function test_activation_works_with_the_token_in_the_body(): void
    {
        $supplier = $this->supplier();
        $token = $this->firstLoginToken($supplier);

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'token' => $token,
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('NewPass123', $supplier->refresh()->password));
    }

    public function test_activation_works_with_the_default_password(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'email' => $supplier->email,          // folded onto `identifier`
            'default_password' => 'default_password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('NewPass123', $supplier->refresh()->password));
    }

    public function test_activation_without_any_proof_is_refused(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(401);

        // The default password must still be the only one that works.
        $this->assertTrue(Hash::check('default_password', $supplier->refresh()->password));
    }

    public function test_activation_with_a_wrong_default_password_is_refused(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'identifier' => $supplier->email,
            'default_password' => 'not-the-password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(401);

        $this->assertTrue(Hash::check('default_password', $supplier->refresh()->password));
    }

    /** A token cannot activate somebody else's account. */
    public function test_a_token_cannot_activate_a_different_account(): void
    {
        $mine = $this->supplier();
        $other = $this->supplier(['email' => 'other-target@assab.com']);
        $token = $this->firstLoginToken($mine);

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'token' => $token,
            'identifier' => $other->email,
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(200);

        // The token's owner was activated; the named account was not touched.
        $this->assertTrue(Hash::check('NewPass123', $mine->refresh()->password));
        $this->assertTrue(Hash::check('default_password', $other->refresh()->password));
    }

    /**
     * An activated account may still set a password here WITH its current one —
     * that is change-password semantics, and it keeps the screen usable in older
     * builds now that a successful sign-in clears the first-login flag.
     */
    public function test_an_activated_account_may_set_a_password_with_the_current_one(): void
    {
        $supplier = $this->supplier(['is_first_login' => false]);

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'identifier' => $supplier->email,
            'default_password' => 'default_password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('NewPass123', $supplier->refresh()->password));
    }

    /** …but a plain session token must not rotate an activated account's password. */
    public function test_a_session_token_cannot_rotate_an_activated_password(): void
    {
        $supplier = $this->supplier(['is_first_login' => false]);
        $sessionToken = $this->postJson('/api/v1/supplier/auth/login', [
            'email' => $supplier->email,
            'password' => 'default_password',
        ])->assertStatus(200)->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$sessionToken)
            ->postJson('/api/v1/supplier/auth/password/reset/first-login', [
                'password' => 'NewPass123',
                'password_confirmation' => 'NewPass123',
            ])->assertStatus(400);

        $this->assertTrue(Hash::check('default_password', $supplier->refresh()->password));
    }

    /** The first sign-in with the issued password activates the account itself. */
    public function test_first_login_completes_activation_and_opens_the_normal_login(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/first-login', [
            'email' => $supplier->email,
            'password' => 'default_password',
        ])->assertStatus(200)->assertJsonPath('data.requires_password_reset', false);

        $this->assertFalse((bool) $supplier->refresh()->is_first_login);

        // The normal login used to answer "Please complete first login setup"
        // forever, because the activation screen could not clear the flag.
        $this->postJson('/api/v1/supplier/auth/login', [
            'email' => $supplier->email,
            'password' => 'default_password',
        ])->assertStatus(200);
    }

    public function test_an_inactive_supplier_cannot_activate(): void
    {
        $supplier = $this->supplier(['is_active' => false]);

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'identifier' => $supplier->email,
            'default_password' => 'default_password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(403);
    }

    // ---- branch manager: same screen, same fix ----

    public function test_branch_manager_activation_works_with_the_default_password(): void
    {
        $manager = BranchManager::factory()->firstLogin()->create([
            'email' => 'bm-activate@assab.com',
            'password' => Hash::make('default-pass-1'),
        ]);

        $this->postJson('/api/v1/branch-manager/auth/reset-password-first-login', [
            'email' => 'bm-activate@assab.com',
            'default_password' => 'default-pass-1',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('NewPass123', $manager->refresh()->password));
    }

    public function test_branch_manager_activation_without_proof_is_refused(): void
    {
        BranchManager::factory()->firstLogin()->create([
            'email' => 'bm-noproof@assab.com',
            'password' => Hash::make('default-pass-1'),
        ]);

        $this->postJson('/api/v1/branch-manager/auth/reset-password-first-login', [
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(401);
    }
}
