<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Events\NotificationBroadcasted;
use Modules\Notification\Models\NotificationPreference;
use Modules\Notification\Notifications\BaseNotification;
use Modules\Notification\Services\NotificationService;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Resolved per call, not in setUp: the service takes the event dispatcher by
     * constructor injection, so an instance built before `Event::fake()` would
     * keep publishing to the real dispatcher and the fake would see nothing.
     */
    private function notificationService(): NotificationService
    {
        return $this->app->make(NotificationService::class);
    }

    /**
     * Test that push notification creates database notification
     */
    public function test_push_notification_creates_database_notification(): void
    {
        // Arrange: Create notification preference with push channel
        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::EXPENSE_APPROVED,
            'channels' => [NotificationChannel::PUSH->value],
            'priority_level' => NotificationPriority::LOW,
            'enabled' => true,
        ]);

        // Act: Send notification
        $this->notificationService()->send(
            $this->user,
            NotificationType::EXPENSE_APPROVED,
            ['expense_id' => '123', 'amount' => 100.50]
        );

        // Assert: Database notification exists
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
        ]);

        $notification = $this->user->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertEquals(NotificationType::EXPENSE_APPROVED->value, $notification->data['type']);
    }

    /**
     * Test that push notification broadcasts event
     */
    public function test_push_notification_broadcasts_event(): void
    {
        // Arrange: Fake events and create preference
        Event::fake([NotificationBroadcasted::class]);

        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::SHIFT_START_REMINDER,
            'channels' => [NotificationChannel::PUSH->value],
            'priority_level' => NotificationPriority::LOW,
            'enabled' => true,
        ]);

        // Act: Send notification
        $this->notificationService()->send(
            $this->user,
            NotificationType::SHIFT_START_REMINDER,
            ['shift_id' => '456']
        );

        // Assert: Broadcast event was dispatched
        Event::assertDispatched(NotificationBroadcasted::class, function ($event) {
            return $event->notifiable->id === $this->user->id
                && $event->data['type'] === NotificationType::SHIFT_START_REMINDER->value;
        });
    }

    /**
     * Test that push notification includes correct data in broadcast
     */
    public function test_push_notification_broadcast_data(): void
    {
        // Arrange
        Event::fake([NotificationBroadcasted::class]);

        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::ORDER_CREATED,
            'channels' => [NotificationChannel::PUSH->value],
            'priority_level' => NotificationPriority::LOW,
            'enabled' => true,
        ]);

        $customData = [
            'order_id' => '789',
            'order_number' => 'ORD-2024-001',
            'total' => 1500.00,
        ];

        // Act
        $this->notificationService()->send(
            $this->user,
            NotificationType::ORDER_CREATED,
            $customData
        );

        // Assert: Broadcast event was dispatched for push-enabled preference
        Event::assertDispatched(NotificationBroadcasted::class);
    }

    /**
     * The Pusher broadcast is no longer coupled to the `push` channel.
     *
     * `push` now means FCM device push. Pusher drives the live in-app UI of a
     * client that is already open, so it fires whenever the recipient gets the
     * notification at all — including an in-app-only preference.
     */
    public function test_notification_without_push_channel_still_broadcasts_for_live_clients(): void
    {
        // Arrange
        Event::fake([NotificationBroadcasted::class]);

        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::EXPENSE_APPROVED,
            'channels' => [NotificationChannel::IN_APP->value], // Only in-app, no device push
            'priority_level' => NotificationPriority::LOW,
            'enabled' => true,
        ]);

        // Act
        $this->notificationService()->send(
            $this->user,
            NotificationType::EXPENSE_APPROVED,
            ['expense_id' => '123']
        );

        Event::assertDispatched(NotificationBroadcasted::class);
    }

    /**
     * A suppressed notification must not reach the live channel either.
     */
    public function test_a_disabled_preference_does_not_broadcast(): void
    {
        Event::fake([NotificationBroadcasted::class]);

        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::EXPENSE_APPROVED,
            'channels' => [NotificationChannel::IN_APP->value],
            'priority_level' => NotificationPriority::LOW,
            'enabled' => false,
        ]);

        $this->notificationService()->send(
            $this->user,
            NotificationType::EXPENSE_APPROVED,
            ['expense_id' => '123']
        );

        Event::assertNotDispatched(NotificationBroadcasted::class);
    }

    /**
     * Test that notification with multiple channels works correctly
     */
    public function test_notification_with_multiple_channels(): void
    {
        // Arrange
        Event::fake([NotificationBroadcasted::class]);

        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::CUSTODY_LOW_BALANCE,
            'channels' => [
                NotificationChannel::PUSH->value,
                NotificationChannel::IN_APP->value,
            ],
            'priority_level' => NotificationPriority::MEDIUM,
            'enabled' => true,
        ]);

        // Act
        $this->notificationService()->send(
            $this->user,
            NotificationType::CUSTODY_LOW_BALANCE,
            ['balance' => 50.00]
        );

        // Assert: Both database notification and broadcast event exist
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
        ]);

        Event::assertDispatched(NotificationBroadcasted::class);
    }

    /**
     * BaseNotification routes itself to the `fcm` channel only when the push
     * channel was requested AND the recipient can actually hold a device token.
     */
    public function test_base_notification_routes_to_fcm_only_when_push_is_requested(): void
    {
        $withPush = new BaseNotification(
            NotificationType::EXPENSE_APPROVED,
            ['test' => 'data'],
            NotificationPriority::LOW,
            [NotificationChannel::PUSH->value, NotificationChannel::IN_APP->value]
        );

        $withoutPush = new BaseNotification(
            NotificationType::EXPENSE_APPROVED,
            ['test' => 'data'],
            NotificationPriority::LOW,
            [NotificationChannel::IN_APP->value]
        );

        $this->assertSame(['database', 'fcm'], $withPush->via($this->user));
        $this->assertSame(['database'], $withoutPush->via($this->user));
    }

    /**
     * Test that broadcast event uses correct channel name
     */
    public function test_broadcast_event_channel_name(): void
    {
        // Arrange
        Event::fake([NotificationBroadcasted::class]);

        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::SHIFT_HANDOVER_APPROVED,
            'channels' => [NotificationChannel::PUSH->value],
            'priority_level' => NotificationPriority::LOW,
            'enabled' => true,
        ]);

        // Act
        $this->notificationService()->send(
            $this->user,
            NotificationType::SHIFT_HANDOVER_APPROVED,
            []
        );

        // Assert: Event broadcasts on correct private channel
        Event::assertDispatched(NotificationBroadcasted::class, function ($event) {
            $channels = $event->broadcastOn();

            return count($channels) === 1
                && $channels[0]->name === 'private-user.'.$this->user->id;
        });
    }

    /**
     * Test that broadcast event has correct event name
     */
    public function test_broadcast_event_name(): void
    {
        // Arrange
        Event::fake([NotificationBroadcasted::class]);

        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::ORDER_STATUS_CHANGED,
            'channels' => [NotificationChannel::PUSH->value],
            'priority_level' => NotificationPriority::LOW,
            'enabled' => true,
        ]);

        // Act
        $this->notificationService()->send(
            $this->user,
            NotificationType::ORDER_STATUS_CHANGED,
            ['status' => 'delivered']
        );

        // Assert: Event has correct broadcast name
        Event::assertDispatched(NotificationBroadcasted::class, function ($event) {
            return $event->broadcastAs() === 'notification.received';
        });
    }
}
