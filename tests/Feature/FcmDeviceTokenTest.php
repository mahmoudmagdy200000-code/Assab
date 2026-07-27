<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
 * Device token registration lifecycle.
 *
 * A registration token is a device credential: whoever holds it can push to
 * that handset. These tests pin the ownership rules that keep it single-owner.
 */
class FcmDeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Cashier $cashier;

    private Cashier $otherCashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(FcmClientInterface::class, new RecordingFcmClient);

        $this->branch = Branch::factory()->create();
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->otherCashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
    }

    private function token(string $seed = 'a'): string
    {
        return str_repeat($seed, 40).Str::random(120);
    }

    // ── API ─────────────────────────────────────────────────────────────────

    public function test_it_registers_a_device_token_for_the_authenticated_caller(): void
    {
        $token = $this->token();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/device-tokens', [
                'token' => $token,
                'platform' => DevicePlatform::ANDROID->value,
                'app' => DeviceApp::MOBILE->value,
                'locale' => 'ar',
                'device_id' => 'device-1',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.locale', 'ar');

        // Cashier is in Shift's morph map, so the stored type is the alias.
        $this->assertDatabaseHas('device_tokens', [
            'notifiable_type' => $this->cashier->getMorphClass(),
            'notifiable_id' => $this->cashier->id,
            'token_hash' => DeviceToken::hashFor($token),
        ]);
    }

    public function test_the_raw_token_is_never_echoed_back(): void
    {
        $token = $this->token();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/device-tokens', [
                'token' => $token,
                'platform' => 'ios',
            ]);

        $response->assertCreated();
        $this->assertStringNotContainsString($token, $response->getContent());
    }

    public function test_it_rejects_an_unauthenticated_registration(): void
    {
        $this->postJson('/api/v1/device-tokens', [
            'token' => $this->token(),
            'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_it_validates_the_platform(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/device-tokens', [
                'token' => $this->token(),
                'platform' => 'blackberry',
            ])
            ->assertStatus(422);
    }

    public function test_registration_is_idempotent_for_the_same_owner(): void
    {
        $token = $this->token();

        foreach (['en', 'ar'] as $locale) {
            $this->actingAs($this->cashier, 'sanctum')
                ->postJson('/api/v1/device-tokens', [
                    'token' => $token,
                    'platform' => 'android',
                    'locale' => $locale,
                ])->assertCreated();
        }

        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertSame('ar', DeviceToken::query()->value('locale'));
    }

    public function test_it_lists_only_the_callers_devices(): void
    {
        $service = $this->app->make(DeviceTokenService::class);
        $service->register($this->cashier, $this->token('a'), ['platform' => 'android']);
        $service->register($this->otherCashier, $this->token('b'), ['platform' => 'ios']);

        $this->actingAs($this->cashier, 'sanctum')
            ->getJson('/api/v1/device-tokens')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unregister_does_not_delete_another_users_token(): void
    {
        $service = $this->app->make(DeviceTokenService::class);
        $victimToken = $this->token('b');
        $service->register($this->otherCashier, $victimToken, ['platform' => 'android']);

        $this->actingAs($this->cashier, 'sanctum')
            ->deleteJson('/api/v1/device-tokens', ['token' => $victimToken])
            ->assertOk();

        // The endpoint reports success either way — it must not double as an
        // oracle — but the row survives.
        $this->assertDatabaseHas('device_tokens', [
            'token_hash' => DeviceToken::hashFor($victimToken),
            'notifiable_id' => $this->otherCashier->id,
        ]);
    }

    // ── Service rules ───────────────────────────────────────────────────────

    public function test_registering_a_token_held_by_another_user_rebinds_it(): void
    {
        $service = $this->app->make(DeviceTokenService::class);
        $token = $this->token();

        $service->register($this->otherCashier, $token, ['platform' => 'android']);
        $service->register($this->cashier, $token, ['platform' => 'android']);

        // Exactly one live row, owned by the most recent registrant: the handset
        // changed hands and the previous user must stop receiving pushes on it.
        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertSame(
            $this->cashier->id,
            DeviceToken::query()->value('notifiable_id')
        );
    }

    public function test_a_rotated_token_on_the_same_device_supersedes_the_old_one(): void
    {
        $service = $this->app->make(DeviceTokenService::class);

        $service->register($this->cashier, $this->token('a'), [
            'platform' => 'android',
            'device_id' => 'handset-7',
        ]);
        $service->register($this->cashier, $this->token('b'), [
            'platform' => 'android',
            'device_id' => 'handset-7',
        ]);

        $this->assertSame(1, DeviceToken::query()->count());
    }

    public function test_revoke_all_clears_every_device(): void
    {
        $service = $this->app->make(DeviceTokenService::class);
        $service->register($this->cashier, $this->token('a'), ['platform' => 'android', 'device_id' => 'd1']);
        $service->register($this->cashier, $this->token('b'), ['platform' => 'ios', 'device_id' => 'd2']);

        $this->assertSame(2, $service->revokeAll($this->cashier));
        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_prune_removes_only_stale_tokens(): void
    {
        $service = $this->app->make(DeviceTokenService::class);
        $service->register($this->cashier, $this->token('a'), ['platform' => 'android', 'device_id' => 'fresh']);
        $stale = $service->register($this->cashier, $this->token('b'), ['platform' => 'ios', 'device_id' => 'stale']);

        $stale->forceFill(['last_used_at' => now()->subDays(400)])->save();

        $this->assertSame(1, $service->pruneStale(180));
        $this->assertSame(1, DeviceToken::query()->count());
    }
}
