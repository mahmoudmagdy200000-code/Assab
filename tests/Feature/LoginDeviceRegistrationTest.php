<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Branch\Models\Branch;
use Modules\Cashier\Models\Cashier;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Enums\DevicePlatform;
use Modules\Notification\Models\DeviceToken;
use Modules\Notification\Services\DeviceTokenService;
use Tests\Fakes\RecordingFcmClient;
use Tests\TestCase;

/**
 * Device registration folded into sign-in.
 *
 * The contract the mobile clients rely on: pass `fcm_token` to login and the
 * account is push-addressable immediately — but push must never be able to
 * break authentication.
 */
class LoginDeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Cashier $cashier;

    private string $password = 'cashier-secret-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(FcmClientInterface::class, new RecordingFcmClient);

        $this->branch = Branch::factory()->create();
        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'password' => $this->password,
        ]);
    }

    private function token(string $seed = 'a'): string
    {
        return str_repeat($seed, 40).Str::random(80);
    }

    // ── Mobile login ────────────────────────────────────────────────────────

    public function test_login_registers_the_device_when_an_fcm_token_is_sent(): void
    {
        $token = $this->token();

        $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => $this->cashier->email,
            'password' => $this->password,
            'fcm_token' => $token,
            'platform' => 'ios',
            'device_id' => 'iphone-1',
            'device_name' => 'iPhone 15',
            'app_version' => '2.4.1',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'notifiable_type' => $this->cashier->getMorphClass(),
            'notifiable_id' => $this->cashier->id,
            'token_hash' => DeviceToken::hashFor($token),
            'platform' => 'ios',
            'app' => DeviceApp::MOBILE->value,
            'device_id' => 'iphone-1',
        ]);
    }

    public function test_login_without_an_fcm_token_still_succeeds(): void
    {
        // Web clients and older app builds send nothing; they must be unaffected.
        $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => $this->cashier->email,
            'password' => $this->password,
        ])->assertOk();

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_login_defaults_the_platform_when_the_client_omits_it(): void
    {
        // A client that sends only `fcm_token` must not get a 422 on login.
        $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => $this->cashier->email,
            'password' => $this->password,
            'fcm_token' => $this->token(),
        ])->assertOk();

        $this->assertSame(DevicePlatform::ANDROID, DeviceToken::query()->value('platform'));
    }

    public function test_login_rejects_an_unknown_platform(): void
    {
        $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => $this->cashier->email,
            'password' => $this->password,
            'fcm_token' => $this->token(),
            'platform' => 'blackberry',
        ])->assertStatus(422);
    }

    public function test_a_failed_login_does_not_register_the_device(): void
    {
        $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => $this->cashier->email,
            'password' => 'wrong-password',
            'fcm_token' => $this->token(),
        ])->assertStatus(401);

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_login_is_idempotent_across_repeated_launches(): void
    {
        $token = $this->token();

        foreach (range(1, 3) as $launch) {
            unset($launch);

            $this->postJson('/api/v1/cashier/auth/login', [
                'identifier' => $this->cashier->email,
                'password' => $this->password,
                'fcm_token' => $token,
                'device_id' => 'iphone-1',
            ])->assertOk();
        }

        $this->assertSame(1, DeviceToken::query()->count());
    }

    public function test_a_shared_handset_moves_to_whoever_logs_in(): void
    {
        $other = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'password' => $this->password,
        ]);

        $token = $this->token();

        foreach ([$this->cashier, $other] as $user) {
            $this->postJson('/api/v1/cashier/auth/login', [
                'identifier' => $user->email,
                'password' => $this->password,
                'fcm_token' => $token,
            ])->assertOk();
        }

        // The first cashier must stop receiving push on a handset they handed over.
        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertSame($other->id, DeviceToken::query()->value('notifiable_id'));
    }

    // ── Logout ──────────────────────────────────────────────────────────────

    /**
     * Signs in for real rather than using actingAs(): logout deletes the current
     * personal access token, which only exists on the genuine bearer path.
     */
    private function bearer(): string
    {
        return $this->cashier->createToken('cashier-token')->plainTextToken;
    }

    public function test_logout_releases_the_device(): void
    {
        $token = $this->token();
        $this->app->make(DeviceTokenService::class)->register($this->cashier, $token, ['platform' => 'android']);

        $this->withHeader('Authorization', 'Bearer '.$this->bearer())
            ->postJson('/api/v1/cashier/logout', ['fcm_token' => $token])
            ->assertOk();

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_logout_without_a_token_leaves_other_devices_alone(): void
    {
        $this->app->make(DeviceTokenService::class)
            ->register($this->cashier, $this->token(), ['platform' => 'android']);

        $this->withHeader('Authorization', 'Bearer '.$this->bearer())
            ->postJson('/api/v1/cashier/logout')
            ->assertOk();

        // The server cannot guess which of the user's devices signed out.
        $this->assertSame(1, DeviceToken::query()->count());
    }

    // ── Dashboard login ─────────────────────────────────────────────────────

    public function test_dashboard_login_registers_the_device_as_a_dashboard_app(): void
    {
        $company = AsabCompany::create(['name' => 'Login Co', 'plan' => 'Professional', 'status' => 'active']);
        $user = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'محاسب',
            'email' => 'accountant@login.test',
            'password' => 'dashboard-secret',
            'status' => 'active',
        ]);

        $token = $this->token('b');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'dashboard-secret',
            'fcm_token' => $token,
            'platform' => 'web',
        ])->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'token_hash' => DeviceToken::hashFor($token),
            'app' => DeviceApp::DASHBOARD->value,
            'platform' => 'web',
        ]);
    }

    // ── Resilience ──────────────────────────────────────────────────────────

    public function test_a_broken_device_registry_does_not_break_authentication(): void
    {
        $this->app->bind(DeviceTokenService::class, fn () => new class extends DeviceTokenService
        {
            public function __construct() {}

            public function register(object $owner, string $token, array $attributes = []): DeviceToken
            {
                throw new \RuntimeException('device registry is down');
            }
        });

        // Losing a notification is bad. Locking a user out of the app is worse.
        $this->postJson('/api/v1/cashier/auth/login', [
            'identifier' => $this->cashier->email,
            'password' => $this->password,
            'fcm_token' => $this->token(),
        ])->assertOk()->assertJsonPath('success', true);
    }
}
