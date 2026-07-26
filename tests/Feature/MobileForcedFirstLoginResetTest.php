<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * `MOBILE_FORCE_FIRST_LOGIN_RESET=true` — the state the client asked for
 * («المفروض أعمل verification الأول وأغيّر الباسورد»).
 *
 * With the flag ON the first sign-in does NOT complete activation, the normal
 * login refuses an unactivated account with 403 (not the old opaque 500/400),
 * and the activation endpoint is the single exit. The default (OFF) behaviour is
 * pinned by MobileFirstLoginActivationTest — both states are covered on purpose,
 * because flipping this flag is retroactive for every account that still carries
 * `is_first_login`.
 */
class MobileForcedFirstLoginResetTest extends TestCase
{
    use RefreshDatabase;

    /** Force the flag ON before the framework loads config. */
    public function createApplication()
    {
        putenv('MOBILE_FORCE_FIRST_LOGIN_RESET=true');
        $_ENV['MOBILE_FORCE_FIRST_LOGIN_RESET'] = 'true';
        $_SERVER['MOBILE_FORCE_FIRST_LOGIN_RESET'] = 'true';

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        // Never leak the flag into another test's application.
        putenv('MOBILE_FORCE_FIRST_LOGIN_RESET');
        unset($_ENV['MOBILE_FORCE_FIRST_LOGIN_RESET'], $_SERVER['MOBILE_FORCE_FIRST_LOGIN_RESET']);

        parent::tearDown();
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'Forced Supplier',
            'email' => 'forced-'.uniqid().'@assab.com',
            'phone' => '05'.random_int(10000000, 99999999),
            'password' => bcrypt('default_password'),
            'is_active' => true,
            'is_first_login' => true,
        ], $attributes));
    }

    public function test_the_flag_is_on_for_this_case(): void
    {
        $this->assertTrue(config('features.mobile_force_first_login_reset'));
    }

    public function test_first_login_keeps_the_account_pending_and_asks_for_the_reset(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/first-login', [
            'email' => $supplier->email,
            'password' => 'default_password',
        ])->assertStatus(200)->assertJsonPath('data.requires_password_reset', true);

        $this->assertTrue((bool) $supplier->refresh()->is_first_login);
    }

    public function test_the_normal_login_refuses_an_unactivated_account_with_403(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/login', [
            'email' => $supplier->email,
            'password' => 'default_password',
        ])->assertStatus(403);
    }

    public function test_activation_is_the_exit_and_then_the_login_works(): void
    {
        $supplier = $this->supplier();

        $this->postJson('/api/v1/supplier/auth/password/reset/first-login', [
            'identifier' => $supplier->email,
            'default_password' => 'default_password',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(200);

        $this->assertFalse((bool) $supplier->refresh()->is_first_login);

        $this->postJson('/api/v1/supplier/auth/login', [
            'email' => $supplier->email,
            'password' => 'NewPass123',
        ])->assertStatus(200);
    }

    public function test_branch_manager_follows_the_same_rule(): void
    {
        $manager = BranchManager::factory()->firstLogin()->create([
            'email' => 'forced-bm@assab.com',
            'password' => Hash::make('default-pass-1'),
        ]);

        $this->postJson('/api/v1/branch-manager/auth/first-login', [
            'email' => 'forced-bm@assab.com',
            'password' => 'default-pass-1',
        ])->assertStatus(200)->assertJsonPath('data.requires_password_reset', true);

        $this->assertTrue((bool) $manager->refresh()->is_first_login);

        $this->postJson('/api/v1/branch-manager/auth/login', [
            'email' => 'forced-bm@assab.com',
            'password' => 'default-pass-1',
        ])->assertStatus(403);
    }
}
