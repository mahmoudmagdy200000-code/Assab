<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Models\DeviceToken;
use Modules\Notification\Models\NotificationPreference;
use Modules\Notification\Services\DeviceTokenService;
use Tests\Fakes\RecordingFcmClient;
use Tests\TestCase;

/**
 * End-to-end delivery: preferences → channels → FCM → token hygiene.
 */
class FcmNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private RecordingFcmClient $fcm;

    private Branch $branch;

    private Cashier $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fcm = new RecordingFcmClient;
        $this->app->instance(FcmClientInterface::class, $this->fcm);

        $this->branch = Branch::factory()->create();
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
    }

    private function service(): NotificationServiceInterface
    {
        return $this->app->make(NotificationServiceInterface::class);
    }

    private function registerDevice(object $owner, string $locale = 'en', string $seed = 'a'): DeviceToken
    {
        return $this->app->make(DeviceTokenService::class)->register(
            $owner,
            str_repeat($seed, 40).Str::random(80),
            ['platform' => 'android', 'locale' => $locale, 'device_id' => 'dev-'.$seed]
        );
    }

    // ── Default behaviour ───────────────────────────────────────────────────

    public function test_it_delivers_to_devices_when_the_user_has_no_preference_row(): void
    {
        $device = $this->registerDevice($this->cashier);

        $this->service()->send(
            $this->cashier,
            NotificationType::SHIFT_START_REMINDER,
            ['shift_id' => 'shift-1']
        );

        // A user who has never opened the preferences screen must still be
        // reachable — the previous implementation dropped these silently.
        $this->assertSame([$device->token], $this->fcm->sentTokens());
        $this->assertSame(1, $this->cashier->notifications()->count());
    }

    public function test_it_writes_a_delivery_log_per_channel(): void
    {
        $this->registerDevice($this->cashier);

        $this->service()->send($this->cashier, NotificationType::SHIFT_START_REMINDER, []);

        $notificationId = $this->cashier->notifications()->value('id');

        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notificationId,
            'channel' => NotificationChannel::IN_APP->value,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'notification_id' => $notificationId,
            'channel' => NotificationChannel::PUSH->value,
            'status' => 'sent',
        ]);
    }

    public function test_the_push_payload_carries_the_in_app_notification_id(): void
    {
        $this->registerDevice($this->cashier);

        $this->service()->send($this->cashier, NotificationType::SHIFT_START_REMINDER, []);

        $notificationId = $this->cashier->notifications()->value('id');

        $this->assertSame(
            $notificationId,
            $this->fcm->sent[0]['message']->data['notification_id'] ?? null
        );
    }

    // ── Preferences ─────────────────────────────────────────────────────────

    public function test_a_preference_without_the_push_channel_suppresses_the_device_send(): void
    {
        $this->registerDevice($this->cashier);

        NotificationPreference::create([
            'notifiable_type' => Cashier::class,
            'notifiable_id' => $this->cashier->id,
            'notification_type' => NotificationType::SHIFT_START_REMINDER->value,
            'channels' => [NotificationChannel::IN_APP->value],
            'priority_level' => NotificationPriority::LOW->value,
            'enabled' => true,
        ]);

        $this->service()->send($this->cashier, NotificationType::SHIFT_START_REMINDER, []);

        $this->assertSame([], $this->fcm->sentTokens());
        $this->assertSame(1, $this->cashier->notifications()->count());
    }

    public function test_a_disabled_preference_suppresses_the_notification_entirely(): void
    {
        $this->registerDevice($this->cashier);

        NotificationPreference::create([
            'notifiable_type' => Cashier::class,
            'notifiable_id' => $this->cashier->id,
            'notification_type' => NotificationType::SHIFT_START_REMINDER->value,
            'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::PUSH->value],
            'priority_level' => NotificationPriority::LOW->value,
            'enabled' => false,
        ]);

        $this->service()->send($this->cashier, NotificationType::SHIFT_START_REMINDER, []);

        $this->assertSame([], $this->fcm->sentTokens());
        $this->assertSame(0, $this->cashier->notifications()->count());
    }

    public function test_a_mandatory_type_ignores_a_disabled_preference(): void
    {
        $this->registerDevice($this->cashier);

        NotificationPreference::create([
            'notifiable_type' => Cashier::class,
            'notifiable_id' => $this->cashier->id,
            'notification_type' => NotificationType::CASHIER_ACCOUNT_DEACTIVATED->value,
            'channels' => [NotificationChannel::IN_APP->value, NotificationChannel::PUSH->value],
            'priority_level' => NotificationPriority::CRITICAL->value,
            'enabled' => false,
        ]);

        $this->service()->send($this->cashier, NotificationType::CASHIER_ACCOUNT_DEACTIVATED, []);

        // Account-security events are not opt-out.
        $this->assertCount(1, $this->fcm->sentTokens());
        $this->assertSame(1, $this->cashier->notifications()->count());
    }

    // ── Token hygiene ───────────────────────────────────────────────────────

    public function test_it_prunes_a_token_firebase_reports_as_unregistered(): void
    {
        $dead = $this->registerDevice($this->cashier, 'en', 'a');
        $live = $this->registerDevice($this->cashier, 'en', 'b');

        $this->fcm->failures[$dead->token] = 'prune';

        $this->service()->send($this->cashier, NotificationType::SHIFT_START_REMINDER, []);

        $this->assertNull(DeviceToken::query()->find($dead->id));
        $this->assertNotNull(DeviceToken::query()->find($live->id));
    }

    public function test_a_transient_failure_does_not_prune_the_token(): void
    {
        $device = $this->registerDevice($this->cashier);
        $this->fcm->failures[$device->token] = 'transient';

        $this->service()->send($this->cashier, NotificationType::SHIFT_START_REMINDER, []);

        $this->assertNotNull(DeviceToken::query()->find($device->id));
    }

    // ── Localization ────────────────────────────────────────────────────────

    public function test_push_copy_follows_the_device_locale(): void
    {
        $arabicCashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->registerDevice($arabicCashier, 'ar', 'c');

        $this->service()->send($arabicCashier, NotificationType::SHIFT_START_REMINDER, []);

        $message = $this->fcm->sent[0]['message'];

        $this->assertSame('تبدأ ورديتك خلال ١٥ دقيقة.', $message->body);
    }

    public function test_push_copy_falls_back_to_english(): void
    {
        $this->registerDevice($this->cashier, 'en');

        $this->service()->send($this->cashier, NotificationType::SHIFT_START_REMINDER, []);

        $this->assertSame('Your shift starts in 15 minutes.', $this->fcm->sent[0]['message']->body);
    }

    public function test_data_placeholders_are_interpolated(): void
    {
        $this->registerDevice($this->cashier);

        $this->service()->send(
            $this->cashier,
            NotificationType::SHIFT_HANDOVER_PENDING,
            ['cashier_name' => 'Fahad']
        );

        $this->assertStringContainsString('Fahad', $this->fcm->sent[0]['message']->body);
    }

    // ── Fan-out ─────────────────────────────────────────────────────────────

    public function test_send_to_role_reaches_every_active_holder_in_the_branch(): void
    {
        $otherBranch = Branch::factory()->create();

        $inBranch = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $elsewhere = BranchManager::factory()->create(['branch_id' => $otherBranch->id, 'status' => 'active']);

        $wanted = $this->registerDevice($inBranch, 'en', 'd');
        $this->registerDevice($elsewhere, 'en', 'e');

        $this->service()->sendToRole(
            'branch_manager',
            NotificationType::CASH_VARIANCE_HIGH,
            [],
            NotificationPriority::HIGH,
            $this->branch->id
        );

        $this->assertSame([$wanted->token], $this->fcm->sentTokens());
    }

    public function test_topic_broadcast_does_not_create_in_app_rows(): void
    {
        $this->service()->broadcastToTopic('branch_'.$this->branch->id, NotificationType::COMPLIANCE_VIOLATION, []);

        $this->assertCount(1, $this->fcm->topicSends);
        $this->assertSame(0, $this->cashier->notifications()->count());
    }

    // ── The `fcm` Laravel channel ───────────────────────────────────────────

    public function test_a_notification_can_route_itself_through_the_fcm_channel(): void
    {
        $device = $this->registerDevice($this->cashier, 'en', 'h');

        // The plain Laravel path: no preferences, no delivery log — just
        // `via(): ['database', 'fcm']`.
        $this->cashier->notify(new \Modules\Notification\Notifications\BaseNotification(
            NotificationType::SHIFT_END_REMINDER,
            ['shift_id' => 'shift-9'],
            NotificationPriority::LOW,
            [NotificationChannel::PUSH->value],
        ));

        $this->assertSame([$device->token], $this->fcm->sentTokens());
        $this->assertSame(1, $this->cashier->notifications()->count());
    }

    // ── Two-worlds mirroring ────────────────────────────────────────────────

    public function test_push_is_mirrored_to_the_linked_account_in_the_other_world(): void
    {
        $company = AsabCompany::create(['name' => 'Mirror Co', 'plan' => 'Professional', 'status' => 'active']);

        $dashboardUser = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'مدير الفرع',
            'email' => 'mirror@test.sa',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $legacyManager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        AsabIdentityMap::create([
            'company_id' => $company->id,
            'entity_type' => AsabIdentityMap::ENTITY_BRANCH_MANAGER,
            'dashboard_type' => 'asab_user',
            'dashboard_id' => $dashboardUser->id,
            'legacy_type' => 'branch_manager',
            'legacy_id' => $legacyManager->id,
            'match_method' => 'email',
            'linked_email' => 'mirror@test.sa',
            'source' => 'test',
            'linked_at' => now(),
        ]);

        $dashboardDevice = $this->registerDevice($dashboardUser, 'ar', 'f');
        $mobileDevice = $this->registerDevice($legacyManager, 'ar', 'g');

        $this->service()->send($dashboardUser, NotificationType::EXPENSE_APPROVED, ['amount' => '250']);

        // One human, two accounts, two apps — the alert reaches whichever they
        // actually have installed.
        $this->assertEqualsCanonicalizing(
            [$dashboardDevice->token, $mobileDevice->token],
            $this->fcm->sentTokens()
        );

        // ...but only one in-app record, on the account that was addressed.
        $this->assertSame(1, $dashboardUser->notifications()->count());
        $this->assertSame(0, $legacyManager->notifications()->count());
    }
}
