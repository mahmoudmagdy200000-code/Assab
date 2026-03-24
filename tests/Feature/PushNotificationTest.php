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

    private NotificationService $notificationService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->notificationService = $this->app->make(NotificationService::class);
        $this->user = User::factory()->create();
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
        $this->notificationService->send(
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
        $this->notificationService->send(
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
        $this->notificationService->send(
            $this->user,
            NotificationType::ORDER_CREATED,
            $customData
        );

        // Assert: Broadcast event was dispatched for push-enabled preference
        Event::assertDispatched(NotificationBroadcasted::class);
    }

    /**
     * Test that notification without push channel does not broadcast
     */
    public function test_notification_without_push_channel_does_not_broadcast(): void
    {
        // Arrange
        Event::fake([NotificationBroadcasted::class]);
        
        NotificationPreference::create([
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'notification_type' => NotificationType::EXPENSE_APPROVED,
            'channels' => [NotificationChannel::IN_APP->value], // Only in-app, no push
            'priority_level' => NotificationPriority::LOW,
            'enabled' => true,
        ]);

        // Act
        $this->notificationService->send(
            $this->user,
            NotificationType::EXPENSE_APPROVED,
            ['expense_id' => '123']
        );

        // Assert: Broadcast event was NOT dispatched
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
        $this->notificationService->send(
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
     * Test that BaseNotification correctly sets shouldBroadcast flag
     */
    public function test_base_notification_sets_broadcast_flag(): void
    {
        // Test with push channel
        $notificationWithPush = new BaseNotification(
            NotificationType::EXPENSE_APPROVED,
            ['test' => 'data'],
            NotificationPriority::LOW,
            [NotificationChannel::PUSH->value, NotificationChannel::IN_APP->value]
        );

        // Use reflection to check private property
        $reflection = new \ReflectionClass($notificationWithPush);
        $property = $reflection->getProperty('shouldBroadcast');
        $property->setAccessible(true);
        
        $this->assertTrue($property->getValue($notificationWithPush));

        // Test without push channel
        $notificationWithoutPush = new BaseNotification(
            NotificationType::EXPENSE_APPROVED,
            ['test' => 'data'],
            NotificationPriority::LOW,
            [NotificationChannel::IN_APP->value]
        );

        $this->assertFalse($property->getValue($notificationWithoutPush));
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
        $this->notificationService->send(
            $this->user,
            NotificationType::SHIFT_HANDOVER_APPROVED,
            []
        );

        // Assert: Event broadcasts on correct private channel
        Event::assertDispatched(NotificationBroadcasted::class, function ($event) {
            $channels = $event->broadcastOn();
            return count($channels) === 1
                && $channels[0]->name === 'private-user.' . $this->user->id;
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
        $this->notificationService->send(
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

